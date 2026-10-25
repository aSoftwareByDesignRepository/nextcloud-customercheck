<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Dedicated mail-index retention drain — product §5.10 / §14 + SF-04 draft TTL.
 *
 * Runs more often than IMAP sync so aged CRM mail snippets are purged even
 * when sync is idle. Fair round-robin lives in MailboxSyncService.
 */
class MailRetentionPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly MailboxSyncService $sync,
		private readonly MailDraftService $drafts,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
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
				'mail retention purge',
			);
		} catch (LockedException) {
			$this->logger->warning('CustomerCheck: skipping mail retention purge — upgrade backup/restore in progress.', ['app' => 'customercheck']);
			return;
		}

		try {
			$this->sync->purgeExpiredAcrossWorkspaces();
			$this->drafts->purgeStale();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
