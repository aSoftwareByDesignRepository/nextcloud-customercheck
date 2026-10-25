<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\BackgroundJob;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Shared mailbox IMAP sync — product §14 / §17.1. Interval 15 minutes. */
class MailboxSyncJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly MailboxSyncService $sync,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(Constants::MAILBOX_SYNC_INTERVAL_SECONDS);
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
				'mailbox sync',
			);
		} catch (LockedException) {
			$this->logger->warning('CustomerCheck: skipping mailbox sync — upgrade backup/restore in progress.', ['app' => 'customercheck']);
			return;
		}

		try {
			$this->sync->syncAll();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
