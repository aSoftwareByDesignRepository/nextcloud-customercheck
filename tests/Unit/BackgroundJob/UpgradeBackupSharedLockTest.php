<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\BackgroundJob;

use OCA\CustomerCheck\BackgroundJob\AgreementStatusRecomputeJob;
use OCA\CustomerCheck\BackgroundJob\EntityRevisionPurgeJob;
use OCA\CustomerCheck\BackgroundJob\InvoicePaidPollJob;
use OCA\CustomerCheck\BackgroundJob\MailboxSyncJob;
use OCA\CustomerCheck\BackgroundJob\MailRetentionPurgeJob;
use OCA\CustomerCheck\BackgroundJob\PersonaMaintenanceJob;
use OCA\CustomerCheck\BackgroundJob\RenewalReminderJob;
use OCA\CustomerCheck\BackgroundJob\ReviewIngressJob;
use OCA\CustomerCheck\BackgroundJob\WeeklyDigestJob;
use OCA\CustomerCheck\Service\EntityRevisionService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract proof for the upgrade-backup coordination contract:
 * UpgradeBackupService::restore() holds LOCK_KEY exclusively — every
 * BackgroundJob that scans/writes app tables must take the SHARED lock,
 * skip on LockedException without doing work, and always release.
 * (restore_lock_contract_half_wired class — azc r16 / mn @941a98f.)
 */
final class UpgradeBackupSharedLockTest extends TestCase
{
	/** Every app BackgroundJob — the whole family is wired into the contract. */
	private const JOB_CLASSES = [
		AgreementStatusRecomputeJob::class,
		EntityRevisionPurgeJob::class,
		InvoicePaidPollJob::class,
		MailboxSyncJob::class,
		MailRetentionPurgeJob::class,
		PersonaMaintenanceJob::class,
		RenewalReminderJob::class,
		ReviewIngressJob::class,
		WeeklyDigestJob::class,
	];

	/**
	 * Static contract: every job class (a) declares an ILockingProvider
	 * constructor dependency and (b) its source acquires the shared lock on
	 * UpgradeBackupService::LOCK_KEY and releases it in a finally.
	 */
	public function testEveryJobIsWiredIntoSharedLockContract(): void
	{
		foreach (self::JOB_CLASSES as $class) {
			$ref = new \ReflectionClass($class);
			$ctor = $ref->getConstructor();
			$this->assertNotNull($ctor, $class);
			$hasLockDep = false;
			foreach ($ctor->getParameters() as $p) {
				$t = $p->getType();
				if ($t instanceof \ReflectionNamedType && $t->getName() === ILockingProvider::class) {
					$hasLockDep = true;
				}
			}
			$this->assertTrue($hasLockDep, "$class constructor lacks an ILockingProvider dependency");

			$file = $ref->getFileName();
			$this->assertNotFalse($file, $class);
			$src = (string)file_get_contents($file);
			$this->assertStringContainsString('UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('ILockingProvider::LOCK_SHARED', $src, $class);
			$this->assertStringContainsString('releaseLock(UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('} finally {', $src, $class);
		}
		// Sanity: the contract really does cover the whole BackgroundJob dir —
		// no silently missed class when a tenth job lands.
		$dir = dirname((string)(new \ReflectionClass(self::JOB_CLASSES[0]))->getFileName());
		$onDisk = [];
		foreach (glob($dir . '/*Job.php') ?: [] as $f) {
			$onDisk[] = 'OCA\\CustomerCheck\\BackgroundJob\\' . basename($f, '.php');
		}
		sort($onDisk);
		$declared = self::JOB_CLASSES;
		sort($declared);
		$this->assertSame($onDisk, $declared, 'JOB_CLASSES must enumerate every *Job.php in lib/BackgroundJob');
	}

	private function invokeRun(object $job, $argument = null): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->setAccessible(true);
		$m->invoke($job, $argument);
	}

	private function lockedProvider(): ILockingProvider
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED)
			->willThrowException(new LockedException('restore in progress'));
		return $locking;
	}

	public function testLockedOutJobSkipsWithoutTouchingAppTables(): void
	{
		// A restore holds the exclusive backup lock — the sync must skip,
		// not write mail rows into tables being truncated+reinserted.
		$sync = $this->createMock(MailboxSyncService::class);
		$sync->expects($this->never())->method('syncAll');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('skipping'), $this->arrayHasKey('app'));

		$time = $this->createMock(ITimeFactory::class);
		// Time must never be consulted — the skip happens before any work.
		$time->expects($this->never())->method('getTime');

		$job = new MailboxSyncJob($time, $sync, $this->lockedProvider(), $logger);
		$this->invokeRun($job); // must not throw
	}

	public function testLockedOutPurgeJobDefersWithoutDeleting(): void
	{
		$revisions = $this->createMock(EntityRevisionService::class);
		$revisions->expects($this->never())->method('purgeOlderThanDays');

		$job = new EntityRevisionPurgeJob(
			$this->createMock(ITimeFactory::class),
			$revisions,
			$this->lockedProvider(),
			$this->createMock(LoggerInterface::class),
		);
		$this->invokeRun($job); // must not throw
	}

	public function testSharedLockIsReleasedWhenWorkThrows(): void
	{
		// acquire+release must bracket the work even when it blows up —
		// a leaked shared lock would starve the exclusive restore.
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$revisions = $this->createMock(EntityRevisionService::class);
		$revisions->method('purgeOlderThanDays')
			->willThrowException(new \RuntimeException('db gone'));

		$job = new EntityRevisionPurgeJob(
			$this->createMock(ITimeFactory::class),
			$revisions,
			$locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->expectException(\RuntimeException::class);
		$this->invokeRun($job);
	}

	public function testHappyPathAcquiresAndReleasesSharedLock(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$sync = $this->createMock(MailboxSyncService::class);
		$sync->expects($this->once())->method('syncAll');

		$job = new MailboxSyncJob(
			$this->createMock(ITimeFactory::class),
			$sync,
			$locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->invokeRun($job);
	}
}
