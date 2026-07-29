<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityDueLog;
use OCA\CustomerCheck\Db\ActivityDueLogMapper;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Companion P2 — notify + push for open activities due today or overdue.
 *
 * Exactly-once: insert ledger row before dispatch; unique index absorbs races;
 * roll back the ledger row if dispatch throws so the next run retries.
 */
class ActivityDueReminderService
{
	private const CHUNK = 200;

	public function __construct(
		private readonly ActivityMapper $activities,
		private readonly ActivityDueLogMapper $ledger,
		private readonly CompanyMapper $companies,
		private readonly NotificationService $notifications,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Same hour gate as renewal reminders (Settings → reminder_hour).
	 */
	public function shouldRunNow(): bool
	{
		$hour = (int)$this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_REMINDER_HOUR,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_REMINDER_HOUR],
		);
		$now = $this->timeFactory->getDateTime();

		return (int)$now->format('G') === $hour;
	}

	/**
	 * @return array{scanned: int, sent: int, skipped: int}
	 */
	public function run(): array
	{
		$today = $this->timeFactory->getDateTime();
		$todayYmd = $today->format('Y-m-d');
		$due = $this->activities->findOpenDueUpTo($today, self::CHUNK);
		$sent = 0;
		$skipped = 0;

		foreach ($due as $activity) {
			$result = $this->notifyOne($activity, $todayYmd);
			if ($result === 'sent') {
				$sent++;
			} elseif ($result === 'skipped') {
				$skipped++;
			}
		}

		return [
			'scanned' => count($due),
			'sent' => $sent,
			'skipped' => $skipped,
		];
	}

	/**
	 * @return 'sent'|'skipped'|'failed'
	 */
	private function notifyOne(Activity $activity, string $todayYmd): string
	{
		$activityId = (int)$activity->getId();
		$due = $activity->getDueDate();
		if ($due === null) {
			return 'skipped';
		}
		$dueYmd = $due->format('Y-m-d');

		if ($this->ledger->exists($activityId, $dueYmd)) {
			return 'skipped';
		}

		$row = new ActivityDueLog();
		$row->setActivityId($activityId);
		$row->setDueDateSnap($due);
		$row->setSentAt($this->timeFactory->getDateTime());

		try {
			$this->ledger->insert($row);
		} catch (DbException $e) {
			// Unique race — another worker already claimed this (activity, due).
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				|| str_contains(strtolower($e->getMessage()), 'unique')
				|| str_contains(strtolower($e->getMessage()), 'duplicate')) {
				return 'skipped';
			}
			throw $e;
		}

		try {
			$companyId = (int)$activity->getCompanyId();
			$companyName = '';
			try {
				$company = $this->companies->findById($companyId);
				$companyName = (string)$company->getName();
			} catch (\Throwable) {
				// Still notify with activity title alone.
			}

			$dueDay = new \DateTimeImmutable($dueYmd);
			$todayDay = new \DateTimeImmutable($todayYmd);
			$daysLate = $dueDay < $todayDay
				? (int)$dueDay->diff($todayDay)->days
				: 0;

			$this->notifications->notifyCrmUsers(
				Constants::NOTIFY_ACTIVITY_DUE,
				'activity',
				(string)$activityId,
				[
					'companyId' => $companyId,
					'companyName' => $companyName,
					'activityId' => $activityId,
					'title' => (string)$activity->getTitle(),
					'dueOn' => $dueYmd,
					'daysLate' => $daysLate,
					'overdue' => $daysLate > 0 ? 1 : 0,
				],
			);

			return 'sent';
		} catch (\Throwable $e) {
			// Allow retry on the next job tick.
			try {
				$this->ledger->delete($row);
			} catch (\Throwable) {
				$this->ledger->deleteByActivity($activityId);
			}
			$this->logger->warning('CustomerCheck activity-due notify failed', [
				'app' => Application::APP_ID,
				'activityId' => $activityId,
				'message' => $e->getMessage(),
			]);

			return 'failed';
		}
	}
}
