<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\CoachingService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\OooCoverService;
use OCA\CustomerCheck\Service\PromiseService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * OOO cover insert must serialize validate+insert (§13.4 TOCTOU).
 */
final class CoachingOooLockTest extends TestCase
{
	public function testSetOooAcquiresExclusiveLockPerPrimary(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$insertQb = new class {
			public function insert(...$a): self { return $this; }
			public function values(...$a): self { return $this; }
			public function createNamedParameter(...$a): string { return '?'; }
			public function executeStatement(): int { return 1; }
		};
		$db->method('getQueryBuilder')->willReturn($insertQb);
		$db->method('lastInsertId')->willReturn(42);

		$ooo = $this->createMock(OooCoverService::class);
		$ooo->method('listAll')->willReturn([]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-01 12:00:00'));

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())
			->method('acquireLock')
			->with('crm_ooo/7/alex', ILockingProvider::LOCK_EXCLUSIVE);
		$locking->expects($this->once())
			->method('releaseLock')
			->with('crm_ooo/7/alex', ILockingProvider::LOCK_EXCLUSIVE);

		$svc = $this->service($db, $ooo, $time, $locking);
		$result = $svc->setOoo(7, [
			'primaryUserId' => 'alex',
			'backupUserId' => 'sam',
			'startsOn' => '2026-08-10',
			'endsOn' => '2026-08-12',
		], 'mgr');

		$this->assertSame(42, $result['id']);
		$this->assertSame('alex', $result['primaryUserId']);
	}

	public function testSetOooReleasesLockWhenOverlapRejected(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$ooo = $this->createMock(OooCoverService::class);
		$ooo->method('listAll')->willReturn([[
			'id' => 1,
			'primaryUserId' => 'alex',
			'backupUserId' => 'pat',
			'startsOn' => '2026-08-01',
			'endsOn' => '2026-08-20',
			'status' => 'scheduled',
		]]);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$svc = $this->service($db, $ooo, $this->createMock(ITimeFactory::class), $locking);
		try {
			$svc->setOoo(7, [
				'primaryUserId' => 'alex',
				'backupUserId' => 'sam',
				'startsOn' => '2026-08-10',
				'endsOn' => '2026-08-12',
			], 'mgr');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_OOO_OVERLAP, $e->getErrorCode());
		}
	}

	private function service(
		IDBConnection $db,
		OooCoverService $ooo,
		ITimeFactory $time,
		ILockingProvider $locking,
	): CoachingService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		return new CoachingService(
			$db,
			$this->createMock(WorkspaceService::class),
			$this->createMock(ChaseService::class),
			$this->createMock(PromiseService::class),
			$this->createMock(CoverageService::class),
			$this->createMock(EscalationService::class),
			$this->createMock(AccountPlanService::class),
			$ooo,
			$time,
			$l10n,
			$this->createMock(IUserManager::class),
			$locking,
		);
	}
}
