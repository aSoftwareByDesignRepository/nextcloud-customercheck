<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\MailboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Shared-mailbox CRM index retention — product §5.10 / §18.9.
 */
final class MailboxServicePurgeTest extends TestCase
{
	public function testPurgeExpiredNoopsWhenTableMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('tableExists')->with('crm_mail_msg')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);

		$this->assertSame(0, $svc->purgeExpired(1, 365));
	}

	public function testPurgeExpiredNoopsForInvalidWorkspace(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('tableExists');

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);

		$this->assertSame(0, $svc->purgeExpired(0, 365));
	}

	public function testPurgeExpiredClampsRetentionBelowMinimum(): void
	{
		$now = new \DateTimeImmutable('2026-07-31 12:00:00');
		$expectedCutoff = $now->modify('-' . Constants::MAIL_RETENTION_DAYS_MIN . ' days')->format('Y-m-d H:i:s');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(\DateTime::createFromImmutable($now));

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->expects($this->once())->method('closeCursor');

		$comp = $this->createMock(ICompositeExpression::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('orX')->willReturn($comp);
		$expr->method('andX')->willReturn($comp);
		$expr->method('isNotNull')->willReturn('nn');
		$expr->method('isNull')->willReturn('n');
		$expr->method('lt')->willReturn('lt');

		$seenCutoffs = [];
		$select = $this->createMock(IQueryBuilder::class);
		$select->method('expr')->willReturn($expr);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('andWhere')->willReturnSelf();
		$select->method('orderBy')->willReturnSelf();
		$select->method('setMaxResults')->willReturnSelf();
		$select->method('createNamedParameter')->willReturnCallback(static function ($v) use (&$seenCutoffs) {
			if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
				$seenCutoffs[] = $v;
			}
			return 'p';
		});
		$select->expects($this->once())->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('crm_mail_msg')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($select);

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$this->createMock(IL10N::class),
		);

		$this->assertSame(0, $svc->purgeExpired(9, 7));
		$this->assertContains($expectedCutoff, $seenCutoffs);
		$this->assertSame('2026-07-01 12:00:00', $expectedCutoff);
	}

	public function testPurgeExpiredDeletesSelectedIds(): void
	{
		$now = new \DateTime('2026-07-31 12:00:00');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($now);

		$fetchResult = $this->createMock(\OCP\DB\IResult::class);
		$fetchResult->method('fetch')->willReturnOnConsecutiveCalls(
			['id' => 11],
			['id' => 12],
			false,
		);
		$fetchResult->expects($this->once())->method('closeCursor');

		$comp = $this->createMock(ICompositeExpression::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('orX')->willReturn($comp);
		$expr->method('andX')->willReturn($comp);
		$expr->method('isNotNull')->willReturn('nn');
		$expr->method('isNull')->willReturn('n');
		$expr->method('lt')->willReturn('lt');
		$expr->method('in')->willReturn('in');

		$select = $this->createMock(IQueryBuilder::class);
		$select->method('expr')->willReturn($expr);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('andWhere')->willReturnSelf();
		$select->method('orderBy')->willReturnSelf();
		$select->method('setMaxResults')->willReturnSelf();
		$select->method('createNamedParameter')->willReturn('p');
		$select->method('executeQuery')->willReturn($fetchResult);

		$delete = $this->createMock(IQueryBuilder::class);
		$delete->method('expr')->willReturn($expr);
		$delete->method('delete')->willReturnSelf();
		$delete->method('where')->willReturnSelf();
		$delete->method('andWhere')->willReturnSelf();
		$delete->method('createNamedParameter')->willReturn('p');
		$delete->expects($this->once())->method('executeStatement')->willReturn(2);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('crm_mail_msg')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($select, $delete);

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$this->createMock(IL10N::class),
		);

		$this->assertSame(2, $svc->purgeExpired(3, 365));
	}
}
