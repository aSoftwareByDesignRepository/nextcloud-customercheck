<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\BackgroundJob;
use OCA\CustomerCheck\Service\PersonaMaintenanceService;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** OOO activate/end + escalation auto-resolve + commitment auto-verify. */
class PersonaMaintenanceJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly PersonaMaintenanceService $service,
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
				'persona maintenance',
			);
		} catch (LockedException) {
			$this->logger->warning('CustomerCheck: skipping persona maintenance — upgrade backup/restore in progress.', ['app' => 'customercheck']);
			return;
		}

		try {
			$this->service->run();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
