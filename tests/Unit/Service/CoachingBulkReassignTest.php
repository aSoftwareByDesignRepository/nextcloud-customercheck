<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\CoachingService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\NotificationService;
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
 * Bulk reassign must resolve entityTypes (UI) into owned ids — product §13.5.
 * Activities: company-join ACL (no workspace_id on crm_activity), open-only, CAS.
 */
final class CoachingBulkReassignTest extends TestCase
{
	public function testConfirmMismatchRejected(): void
	{
		$svc = $this->serviceWithDb($this->createMock(IDBConnection::class));
		$this->expectException(ValidationException::class);
		$svc->bulkReassign(1, [
			'fromUserId' => 'alex',
			'toUserId' => 'sam',
			'confirmName' => 'wrong',
			'entityTypes' => ['deals'],
		], 'mgr');
	}

	public function testSameFromAndToRejected(): void
	{
		$svc = $this->serviceWithDb($this->createMock(IDBConnection::class));
		try {
			$svc->bulkReassign(1, [
				'fromUserId' => 'alex',
				'toUserId' => 'alex',
				'confirmName' => 'alex',
				'entityTypes' => ['activities'],
			], 'mgr');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertStringContainsString('different colleague', $e->getMessage());
		}
	}

	public function testNonMemberToUserRejected(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$ws = $this->createMock(WorkspaceService::class);
		$ws->method('listMembers')->willReturn([$this->member('alex')]);
		$svc = $this->serviceWithDb($db, $ws);
		try {
			$svc->bulkReassign(7, [
				'fromUserId' => 'alex',
				'toUserId' => 'outsider',
				'confirmName' => 'alex',
				'entityTypes' => ['activities'],
			], 'mgr');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertStringContainsString('colleague in this workspace', $e->getMessage());
		}
	}

	public function testEntityTypesResolvesOwnedDeals(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('beginTransaction');
		$db->method('commit');

		$selectQb = $this->queryBuilderReturning([['id' => 11], ['id' => 12]]);
		$updateQb = $this->updatingQueryBuilder(2);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(static function () use (&$calls, $selectQb, $updateQb) {
			$calls++;
			// 1) idsOwnedBy  2) filterDealIdsInWorkspace  then updates
			return $calls <= 2 ? $selectQb : $updateQb;
		});

		$svc = $this->serviceWithDb($db);
		$result = $svc->bulkReassign(7, [
			'fromUser' => 'alex',
			'toUser' => 'sam',
			'confirmFromUserName' => 'alex',
			'entityTypes' => ['deals'],
		], 'mgr');

		$this->assertSame(2, $result['reassigned']);
		$this->assertSame(2, $result['counts']['deals']);
		$this->assertSame(0, $result['counts']['accounts']);
		$this->assertSame(0, $result['counts']['activities']);
	}

	public function testEntityTypesResolvesOwnedOpenActivities(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('beginTransaction');
		$db->method('commit');

		$selectQb = $this->queryBuilderReturning([['id' => 41], ['id' => 42]]);
		$updateQb = $this->updatingQueryBuilder(2);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(static function () use (&$calls, $selectQb, $updateQb) {
			$calls++;
			return $calls <= 2 ? $selectQb : $updateQb;
		});

		$svc = $this->serviceWithDb($db);
		$result = $svc->bulkReassign(7, [
			'fromUser' => 'alex',
			'toUser' => 'sam',
			'confirmFromUserName' => 'alex',
			'entityTypes' => ['activities'],
		], 'mgr');

		$this->assertSame(2, $result['reassigned']);
		$this->assertSame(2, $result['counts']['activities']);
		$this->assertSame(0, $result['counts']['deals']);
	}

	public function testExplicitActivityIdsScopedViaCompanyJoin(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('beginTransaction');
		$db->method('commit');

		// No entityTypes → only filterActivityIdsInWorkspace (1 select), then updates.
		$selectQb = $this->queryBuilderReturning([['id' => 55]]);
		$updateQb = $this->updatingQueryBuilder(1);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(static function () use (&$calls, $selectQb, $updateQb) {
			$calls++;
			return $calls === 1 ? $selectQb : $updateQb;
		});

		$svc = $this->serviceWithDb($db);
		$result = $svc->bulkReassign(7, [
			'fromUserId' => 'alex',
			'toUserId' => 'sam',
			'confirmName' => 'alex',
			'activityIds' => [55, 999],
		], 'mgr');

		$this->assertSame(1, $result['reassigned']);
		$this->assertSame(1, $result['counts']['activities']);
	}

	public function testCrossWorkspaceActivityIdsYieldEmptyAndReject(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$selectQb = $this->queryBuilderReturning([]);
		$db->method('getQueryBuilder')->willReturn($selectQb);

		$svc = $this->serviceWithDb($db);
		$this->expectException(ValidationException::class);
		$svc->bulkReassign(7, [
			'fromUserId' => 'alex',
			'toUserId' => 'sam',
			'confirmName' => 'alex',
			'activityIds' => [9001],
		], 'mgr');
	}

	public function testEmptyResolutionRejected(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$selectQb = $this->queryBuilderReturning([]);
		$db->method('getQueryBuilder')->willReturn($selectQb);

		$svc = $this->serviceWithDb($db);
		$this->expectException(ValidationException::class);
		$svc->bulkReassign(7, [
			'fromUserId' => 'alex',
			'toUserId' => 'sam',
			'confirmName' => 'alex',
			'entityTypes' => ['deals'],
		], 'mgr');
	}

	public function testCasMissRollsBackAndReportsConflict(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');
		$db->method('inTransaction')->willReturn(false);

		$selectQb = $this->queryBuilderReturning([['id' => 11], ['id' => 12]]);
		// First update succeeds, second misses (owner already changed).
		$updateQb = $this->updatingQueryBuilderSequence([1, 0]);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(static function () use (&$calls, $selectQb, $updateQb) {
			$calls++;
			return $calls <= 2 ? $selectQb : $updateQb;
		});

		$svc = $this->serviceWithDb($db);
		try {
			$svc->bulkReassign(7, [
				'fromUser' => 'alex',
				'toUser' => 'sam',
				'confirmFromUserName' => 'alex',
				'entityTypes' => ['deals'],
			], 'mgr');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_CONFLICT_STALE, $e->getErrorCode());
		}
	}

	public function testActivityCasMissRollsBack(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');
		$db->method('inTransaction')->willReturn(false);

		$selectQb = $this->queryBuilderReturning([['id' => 41], ['id' => 42]]);
		$updateQb = $this->updatingQueryBuilderSequence([1, 0]);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(static function () use (&$calls, $selectQb, $updateQb) {
			$calls++;
			return $calls <= 2 ? $selectQb : $updateQb;
		});

		$svc = $this->serviceWithDb($db);
		try {
			$svc->bulkReassign(7, [
				'fromUser' => 'alex',
				'toUser' => 'sam',
				'confirmFromUserName' => 'alex',
				'entityTypes' => ['todos'],
			], 'mgr');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_CONFLICT_STALE, $e->getErrorCode());
		}
	}

	public function testActivityBulkNotifiesNewAssigneeOncePerItem(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('beginTransaction');
		$db->method('commit');

		$selectOwned = $this->queryBuilderReturning([['id' => 71]]);
		$filterQb = $this->queryBuilderReturning([['id' => 71]]);
		$updateQb = $this->updatingQueryBuilder(1);
		$notifyQb = $this->queryBuilderReturning([
			[
				'id' => 71,
				'activity_title' => 'Call back',
				'company_id' => 9,
				'due_date' => '2026-08-25',
				'company_name' => 'Acme',
			],
		]);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(
			static function () use (&$calls, $selectOwned, $filterQb, $updateQb, $notifyQb) {
				$calls++;
				return match ($calls) {
					1 => $selectOwned,
					2 => $filterQb,
					3 => $updateQb,
					default => $notifyQb,
				};
			},
		);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('notifyUser')->with(
			'sam',
			Constants::NOTIFY_ACTIVITY_ASSIGNED,
			'activity',
			'71',
			$this->callback(static function (array $p): bool {
				return ($p['workspaceId'] ?? 0) === 7
					&& ($p['activityId'] ?? 0) === 71
					&& ($p['title'] ?? '') === 'Call back'
					&& ($p['companyName'] ?? '') === 'Acme';
			}),
		);

		$svc = $this->serviceWithDb($db, null, $notifications);
		$result = $svc->bulkReassign(7, [
			'fromUser' => 'alex',
			'toUser' => 'sam',
			'confirmFromUserName' => 'alex',
			'entityTypes' => ['activities'],
		], 'mgr');

		$this->assertSame(1, $result['counts']['activities']);
	}

	public function testActivityBulkSkipsNotifyWhenAssigneeIsActor(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('beginTransaction');
		$db->method('commit');

		$selectQb = $this->queryBuilderReturning([['id' => 71]]);
		$updateQb = $this->updatingQueryBuilder(1);
		$calls = 0;
		$db->method('getQueryBuilder')->willReturnCallback(static function () use (&$calls, $selectQb, $updateQb) {
			$calls++;
			return $calls <= 2 ? $selectQb : $updateQb;
		});

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notifyUser');

		$svc = $this->serviceWithDb($db, null, $notifications);
		$svc->bulkReassign(7, [
			'fromUser' => 'alex',
			'toUser' => 'sam',
			'confirmFromUserName' => 'alex',
			'entityTypes' => ['activities'],
		], 'sam');
	}

	/** @param list<array<string, mixed>> $rows */
	private function queryBuilderReturning(array $rows): object
	{
		$qb = new class($rows) {
			/** @param list<array<string, mixed>> $rows */
			public function __construct(private array $rows) {}
			public function select(...$a): self { return $this; }
			public function selectAlias(...$a): self { return $this; }
			public function from(...$a): self { return $this; }
			public function innerJoin(...$a): self { return $this; }
			public function leftJoin(...$a): self { return $this; }
			public function where(...$a): self { return $this; }
			public function andWhere(...$a): self { return $this; }
			public function setMaxResults(...$a): self { return $this; }
			public function expr(): object
			{
				return new class {
					public function eq(...$a): string { return 'eq'; }
					public function neq(...$a): string { return 'neq'; }
					public function in(...$a): string { return 'in'; }
					public function isNull(...$a): string { return 'isNull'; }
				};
			}
			public function createNamedParameter(...$a): string { return '?'; }
			public function executeQuery(): object
			{
				$snapshot = $this->rows;
				return new class($snapshot) {
					/** @param list<array<string, mixed>> $rows */
					public function __construct(private array $rows) {}
					public function fetch(): array|false
					{
						$row = array_shift($this->rows);
						return $row === null ? false : $row;
					}
					public function closeCursor(): void {}
				};
			}
		};
		return $qb;
	}

	private function updatingQueryBuilder(int $times): object
	{
		$returns = array_fill(0, max(1, $times), 1);
		return $this->updatingQueryBuilderSequence($returns);
	}

	/** @param list<int> $returns */
	private function updatingQueryBuilderSequence(array $returns): object
	{
		$qb = new class($returns) {
			/** @var list<int> */
			private array $returns;
			public function __construct(array $returns) { $this->returns = $returns; }
			public function update(...$a): self { return $this; }
			public function set(...$a): self { return $this; }
			public function where(...$a): self { return $this; }
			public function andWhere(...$a): self { return $this; }
			public function createNamedParameter(...$a): string { return '?'; }
			public function expr(): object
			{
				return new class {
					public function eq(...$a): string { return 'eq'; }
					public function isNull(...$a): string { return 'isNull'; }
				};
			}
			public function executeStatement(): int
			{
				if ($this->returns === []) {
					return 0;
				}
				return (int)array_shift($this->returns);
			}
		};
		return $qb;
	}

	private function member(string $userId): WorkspaceMember
	{
		$m = new WorkspaceMember();
		$m->setUserId($userId);
		$m->setRole('member');
		$m->setWorkspaceId(7);
		return $m;
	}

	private function serviceWithDb(
		IDBConnection $db,
		?WorkspaceService $workspaces = null,
		?NotificationService $notifications = null,
	): CoachingService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$locking = $this->createMock(ILockingProvider::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-24 12:00:00'));
		$ws = $workspaces ?? $this->createMock(WorkspaceService::class);
		if ($workspaces === null) {
			$ws->method('listMembers')->willReturn([
				$this->member('alex'),
				$this->member('sam'),
			]);
		}
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturn(true);
		return new CoachingService(
			$db,
			$ws,
			$this->createMock(ChaseService::class),
			$this->createMock(PromiseService::class),
			$this->createMock(CoverageService::class),
			$this->createMock(EscalationService::class),
			$this->createMock(AccountPlanService::class),
			$this->createMock(OooCoverService::class),
			$time,
			$l10n,
			$userManager,
			$locking,
			$notifications,
		);
	}
}
