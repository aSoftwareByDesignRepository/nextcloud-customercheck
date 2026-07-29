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
use PHPUnit\Framework\TestCase;

/**
 * Bulk reassign must resolve entityTypes (UI) into owned ids — product §13.5.
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

	/** @param list<array{id:int}> $rows */
	private function queryBuilderReturning(array $rows): object
	{
		$qb = new class($rows) {
			/** @param list<array{id:int}> $rows */
			public function __construct(private array $rows) {}
			public function select(...$a): self { return $this; }
			public function from(...$a): self { return $this; }
			public function innerJoin(...$a): self { return $this; }
			public function where(...$a): self { return $this; }
			public function andWhere(...$a): self { return $this; }
			public function setMaxResults(...$a): self { return $this; }
			public function expr(): object
			{
				return new class {
					public function eq(...$a): string { return 'eq'; }
					public function neq(...$a): string { return 'neq'; }
					public function in(...$a): string { return 'in'; }
				};
			}
			public function createNamedParameter(...$a): string { return '?'; }
			public function executeQuery(): object
			{
				$snapshot = $this->rows;
				return new class($snapshot) {
					/** @param list<array{id:int}> $rows */
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
		$qb = new class($times) {
			private int $left;
			public function __construct(int $times) { $this->left = $times; }
			public function update(...$a): self { return $this; }
			public function set(...$a): self { return $this; }
			public function where(...$a): self { return $this; }
			public function andWhere(...$a): self { return $this; }
			public function createNamedParameter(...$a): string { return '?'; }
			public function expr(): object
			{
				return new class {
					public function eq(...$a): string { return 'eq'; }
				};
			}
			public function executeStatement(): int
			{
				$this->left--;
				return 1;
			}
		};
		return $qb;
	}

	private function serviceWithDb(IDBConnection $db): CoachingService
	{
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
			$this->createMock(OooCoverService::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			$this->createMock(IUserManager::class),
		);
	}
}
