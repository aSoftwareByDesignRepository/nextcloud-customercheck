<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\DigestLog;
use OCA\CustomerCheck\Db\DigestLogMapper;
use OCA\CustomerCheck\Support\DateMath;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Weekly digest — PSD §14.1. Exactly-once via crm_digest_log.week_key.
 */
class WeeklyDigestService
{
	public function __construct(
		private readonly DigestLogMapper $digestLogMapper,
		private readonly TodayService $todayService,
		private readonly MoneyService $moneyService,
		private readonly QuotaService $quotaService,
		private readonly NotificationService $notificationService,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_DIGEST_ENABLED,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_DIGEST_ENABLED],
		) === '1';
	}

	public function shouldRunNow(?\DateTimeInterface $now = null): bool
	{
		if (!$this->isEnabled()) {
			return false;
		}
		$now = $now ?? $this->timeFactory->getDateTime();
		$weekday = (int)$this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_DIGEST_WEEKDAY,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_DIGEST_WEEKDAY],
		);
		$hour = (int)$this->config->getAppValue(
			Application::APP_ID,
			Constants::CONFIG_DIGEST_HOUR,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_DIGEST_HOUR],
		);
		$isoDow = (int)$now->format('N'); // 1=Mon … 7=Sun
		return $isoDow === $weekday && (int)$now->format('G') === $hour;
	}

	/**
	 * @return array{sent: bool, weekKey: string, counts: array<string,int>}
	 */
	public function run(?\DateTimeInterface $now = null): array
	{
		$now = $now ?? $this->timeFactory->getDateTime();
		$weekKey = DateMath::isoWeekKey($now);
		$counts = $this->collectCounts($now);

		if (array_sum($counts) === 0) {
			return ['sent' => false, 'weekKey' => $weekKey, 'counts' => $counts];
		}

		if (!$this->claimWeek($weekKey, $counts, $now)) {
			return ['sent' => false, 'weekKey' => $weekKey, 'counts' => $counts];
		}

		$this->notificationService->notifyCrmUsers(
			Constants::NOTIFY_DIGEST,
			'digest',
			$weekKey,
			[
				'renewals' => $counts['renewals'],
				'overdue' => $counts['overdue'],
				'unpaid' => $counts['unpaid'],
				'quota80' => $counts['quota80'],
			],
		);

		return ['sent' => true, 'weekKey' => $weekKey, 'counts' => $counts];
	}

	/** @return array{renewals:int,overdue:int,unpaid:int,quota80:int} */
	public function collectCounts(\DateTimeInterface $now): array
	{
		$today = $this->todayService->build($now);
		$renewals = 0;
		$overdue = 0;
		foreach ($today['items'] as $item) {
			if (($item['kind'] ?? '') === 'agreement_expiry' && (int)($item['daysUntil'] ?? 99) <= 30) {
				$renewals++;
			}
			if (($item['kind'] ?? '') === 'activity_overdue') {
				$overdue++;
			}
		}

		$money = $this->moneyService->overview(500);
		$unpaid = (int)($money['meta']['total'] ?? count($money['items'] ?? []));

		$quota80 = 0;
		foreach ($this->quotaService->partnerQuotaOverview(500) as $row) {
			if (!empty($row['alert'])) {
				$quota80++;
			}
		}

		return [
			'renewals' => $renewals,
			'overdue' => $overdue,
			'unpaid' => $unpaid,
			'quota80' => $quota80,
		];
	}

	/** @param array<string,int> $counts */
	private function claimWeek(string $weekKey, array $counts, \DateTimeInterface $now): bool
	{
		$row = new DigestLog();
		$row->setWeekKey($weekKey);
		$row->setSentAt($now instanceof \DateTime ? $now : new \DateTime($now->format('c')));
		$row->setPayloadJson(json_encode($counts, JSON_THROW_ON_ERROR));
		try {
			$this->digestLogMapper->insert($row);
			return true;
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}
}
