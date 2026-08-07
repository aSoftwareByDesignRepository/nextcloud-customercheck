<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\ValueMapService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class ValueMapServiceDeleteTest extends TestCase
{
	public function testDeleteNoopsWhenTableMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('tableExists')->with('crm_value_map')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');

		$svc = new ValueMapService(
			$db,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);
		$svc->delete(3, 11);
	}

	public function testDeleteScopesByWorkspaceAndId(): void
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->expects($this->once())->method('delete')->with('crm_value_map')->willReturnSelf();
		$qb->expects($this->once())->method('where')->willReturnSelf();
		$qb->expects($this->once())->method('andWhere')->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ValueMapService(
			$db,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);
		$svc->delete(3, 11);
	}
}
