<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\EntityRevisionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class EntityRevisionPurgeTest extends TestCase
{
	public function testPurgeNoopsWhenTableMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('tableExists')->with('crm_entity_rev')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');
		$svc = new EntityRevisionService($db, $this->createMock(ITimeFactory::class));
		$this->assertSame(0, $svc->purgeOlderThanDays(90));
	}

	public function testPurgeDeletesSelectedIds(): void
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('lt')->willReturn('lt');
		$expr->method('in')->willReturn('in');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(['id' => 7], ['id' => 8], false);
		$result->method('closeCursor');

		$select = $this->createMock(IQueryBuilder::class);
		$select->method('expr')->willReturn($expr);
		$select->method('createNamedParameter')->willReturnArgument(0);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('orderBy')->willReturnSelf();
		$select->method('setMaxResults')->willReturnSelf();
		$select->method('executeQuery')->willReturn($result);

		$delete = $this->createMock(IQueryBuilder::class);
		$delete->method('expr')->willReturn($expr);
		$delete->method('createNamedParameter')->willReturnArgument(0);
		$delete->expects($this->once())->method('delete')->with('crm_entity_rev')->willReturnSelf();
		$delete->method('where')->willReturnSelf();
		$delete->expects($this->once())->method('executeStatement')->willReturn(2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($select, $delete);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-02T00:00:00+00:00'));

		$svc = new EntityRevisionService($db, $time);
		$this->assertSame(2, $svc->purgeOlderThanDays(90, 500));
	}
}
