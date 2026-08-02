<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\DbTransaction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class DbTransactionTest extends TestCase
{
	public function testBeginReturnsFalseWhenAlreadyInTransaction(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(true);
		$db->expects($this->never())->method('beginTransaction');
		self::assertFalse(DbTransaction::begin($db));
	}

	public function testBeginOpensWhenIdle(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);
		$db->expects($this->once())->method('beginTransaction');
		self::assertTrue(DbTransaction::begin($db));
	}

	public function testCommitOnlyWhenOwned(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('commit');
		DbTransaction::commit($db, true);
		DbTransaction::commit($db, false);
	}

	public function testRollBackOnlyWhenOwned(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('rollBack');
		DbTransaction::rollBack($db, true);
		DbTransaction::rollBack($db, false);
	}
}
