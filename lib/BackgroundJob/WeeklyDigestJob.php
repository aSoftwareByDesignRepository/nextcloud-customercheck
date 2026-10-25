<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\BackgroundJob;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCA\CustomerCheck\Service\WeeklyDigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class WeeklyDigestJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly WeeklyDigestService $service,
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
				'weekly digest',
			);
		} catch (LockedException) {
			$this->logger->warning('CustomerCheck: skipping weekly digest — upgrade backup/restore in progress.', ['app' => 'customercheck']);
			return;
		}

		try {
			if ($this->service->shouldRunNow()) {
				$this->service->run();
			}
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
