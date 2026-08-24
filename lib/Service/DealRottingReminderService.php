<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\DealRotLog;
use OCA\CustomerCheck\Db\DealRotLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Owner nudge when an open deal's stage has been idle too long (rotting).
 *
 * Exactly-once per (deal, stageChangedYmd#nudgeIndex): claim ledger before
 * notify; unique index absorbs races; release claim if nobody can receive.
 */
class DealRottingReminderService
{
	private const CHUNK = 200;

	public function __construct(
		private readonly DealMapper $deals,
		private readonly DealRotLogMapper $ledger,
		private readonly CompanyMapper $companies,
		private readonly NotificationService $notifications,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

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

	public function staleDealDays(): int
	{
		$raw = $this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_STALE_DEAL_DAYS,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_STALE_DEAL_DAYS],
		);
		$days = (int)$raw;

		return $days > 0 ? $days : 14;
	}

	/**
	 * @return array{scanned: int, sent: int, skipped: int}
	 */
	public function run(): array
	{
		$today = $this->timeFactory->getDateTime();
		$staleDays = $this->staleDealDays();
		// findOpenStaleBefore uses lt(cutoff): for idle ≥ N days, cutoff = today − (N − 1).
		$cutoffDays = max(0, $staleDays - 1);
		$cutoff = (clone $today)->modify('-' . $cutoffDays . ' days');
		$candidates = $this->deals->findOpenStaleBefore($cutoff, self::CHUNK, null);
		$sent = 0;
		$skipped = 0;

		foreach ($candidates as $deal) {
			$result = $this->notifyOne($deal, $today, $staleDays);
			if ($result === 'sent') {
				$sent++;
			} elseif ($result === 'skipped') {
				$skipped++;
			}
		}

		return [
			'scanned' => count($candidates),
			'sent' => $sent,
			'skipped' => $skipped,
		];
	}

	/**
	 * @return 'sent'|'skipped'|'failed'
	 */
	private function notifyOne(Deal $deal, \DateTimeInterface $today, int $staleDays): string
	{
		$dealId = (int)$deal->getId();
		$stage = (string)$deal->getStage();
		$changed = $deal->getStageChangedAt();
		if ($changed === null || !DealFreshnessLogic::isRotting($stage, $changed, $staleDays, $today)) {
			return 'skipped';
		}

		$stageIdle = DealFreshnessLogic::stageIdleDays($changed, $today) ?? 0;
		$nudge = DealFreshnessLogic::nudgeIndex($stageIdle, $staleDays);
		if ($nudge < 1) {
			return 'skipped';
		}

		$snapKey = DealFreshnessLogic::snapKey($changed, $nudge);
		if ($this->ledger->exists($dealId, $snapKey)) {
			return 'skipped';
		}

		$owner = trim((string)($deal->getOwnerUserId() ?? ''));
		if ($owner === '') {
			// Unowned rotting deals stay visible in Today/Pipeline; no owner to ping.
			return 'skipped';
		}

		$companyId = (int)$deal->getCompanyId();
		$companyName = '';
		$workspaceId = 0;
		try {
			$company = $this->companies->findById($companyId);
			$companyName = (string)$company->getName();
			$workspaceId = (int)($company->getWorkspaceId() ?? 0);
		} catch (\Throwable) {
			return 'skipped';
		}
		if ($workspaceId <= 0) {
			return 'skipped';
		}

		$row = new DealRotLog();
		$row->setDealId($dealId);
		$row->setSnapKey($snapKey);
		$row->setSentAt($this->timeFactory->getDateTime());
		try {
			$this->ledger->insert($row);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return 'skipped';
			}
			throw $e;
		}

		try {
			$ok = $this->notifications->notifyUser(
				$owner,
				Constants::NOTIFY_DEAL_ROTTING,
				'deal',
				(string)$dealId,
				[
					'workspaceId' => $workspaceId,
					'companyId' => $companyId,
					'companyName' => $companyName,
					'dealId' => $dealId,
					'title' => (string)$deal->getTitle(),
					'stage' => $stage,
					'stageIdleDays' => $stageIdle,
					'staleDays' => $staleDays,
					'nudgeIndex' => $nudge,
				],
			);
			if (!$ok) {
				$this->releaseClaim($row, $dealId, $snapKey);

				return 'skipped';
			}

			return 'sent';
		} catch (\Throwable $e) {
			$this->releaseClaim($row, $dealId, $snapKey);
			$this->logger->warning('CustomerCheck deal-rotting notify failed', [
				'app' => Application::APP_ID,
				'dealId' => $dealId,
				'message' => $e->getMessage(),
			]);

			return 'failed';
		}
	}

	private function releaseClaim(DealRotLog $row, int $dealId, string $snapKey): void
	{
		try {
			$this->ledger->delete($row);
		} catch (\Throwable) {
			$this->ledger->deleteByDealAndSnap($dealId, $snapKey);
		}
	}
}
