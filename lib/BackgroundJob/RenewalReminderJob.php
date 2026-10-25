<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\ActivityDueReminderService;
use OCA\CustomerCheck\Service\DealRottingReminderService;
use OCA\CustomerCheck\Service\ReminderScheduler;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Hourly TimedJob that fires the renewal cadence only at `reminder_hour` (§15).
 * Same hour also bridges activity-due + deal-rotting NC notifications + companion push.
 * Status recompute for off-hour ticks lives in AgreementStatusRecomputeJob.
 */
class RenewalReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ReminderScheduler $service,
		private readonly ActivityDueReminderService $activityDue,
		private readonly DealRottingReminderService $dealRotting,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans/writes must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'renewal reminder scan',
			);
		} catch (LockedException) {
			$this->logger->warning('CustomerCheck: skipping renewal reminder scan — upgrade backup/restore in progress.', ['app' => 'customercheck']);
			return;
		}

		try {
			if ($this->service->shouldRunNow()) {
				$this->service->run();
			}
			if ($this->activityDue->shouldRunNow()) {
				$this->activityDue->run();
			}
			if ($this->dealRotting->shouldRunNow()) {
				$this->dealRotting->run();
			}
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
