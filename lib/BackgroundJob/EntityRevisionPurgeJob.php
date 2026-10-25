<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\EntityRevisionService;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Purge crm_entity_rev snapshots older than 90 days — GNQ §6.3.
 */
class EntityRevisionPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly EntityRevisionService $revisions,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
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
				'entity revision purge',
			);
		} catch (LockedException) {
			$this->logger->warning('CustomerCheck: skipping entity revision purge — upgrade backup/restore in progress.', ['app' => 'customercheck']);
			return;
		}

		try {
			// Drain in batches until a short pass returns empty (bounded wall time).
			for ($i = 0; $i < 20; $i++) {
				$deleted = $this->revisions->purgeOlderThanDays(90, 500);
				if ($deleted < 1) {
					break;
				}
			}
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
