<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;

use OCA\CustomerCheck\Service\MailboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Duplicate IMAP uid must backfill empty To/CC on open rows so CC’d / team-sent
 * mail can file onto customers after Sync now (recipient matching).
 */
final class MailboxServiceIngestRefreshTest extends TestCase
{
	public function testDuplicateUidBackfillsEmptyToAndAssignsCustomer(): void
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-14 08:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$comp = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('andX')->willReturn($comp);

		$insertQb = $this->createMock(IQueryBuilder::class);
		$insertQb->method('insert')->willReturnSelf();
		$insertQb->method('values')->willReturnSelf();
		$insertQb->method('createNamedParameter')->willReturn('p');
		$insertQb->method('executeStatement')->willThrowException(new \RuntimeException('unique'));

		$selectResult = $this->createMock(IResult::class);
		$selectResult->method('fetch')->willReturn([
			'id' => 42,
			'to_addrs' => null,
			'from_addr' => 'you@office.example',
			'triage_status' => 'open',
			'company_id' => null,
		]);
		$selectResult->expects($this->once())->method('closeCursor');

		$selectQb = $this->createMock(IQueryBuilder::class);
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('setMaxResults')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn('p');
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$toJson = null;
		$updateQb = $this->createMock(IQueryBuilder::class);
		$updateQb->method('expr')->willReturn($expr);
		$updateQb->method('update')->with('crm_mail_msg')->willReturnSelf();
		$updateQb->method('set')->willReturnCallback(function (string $col, $val) use ($updateQb, &$toJson) {
			if ($col === 'to_addrs') {
				$toJson = $val;
			}
			return $updateQb;
		});
		$updateQb->method('where')->willReturnSelf();
		$updateQb->method('andWhere')->willReturnSelf();
		$updateQb->method('createNamedParameter')->willReturnCallback(static function ($v) {
			return is_string($v) ? $v : 'p';
		});
		$updateQb->expects($this->once())->method('executeStatement')->willReturn(1);

		// triageAssign: company exists check + update open → matched
		$companyResult = $this->createMock(IResult::class);
		$companyResult->method('fetch')->willReturn(['id' => 9]);
		$companyResult->method('closeCursor');
		$companySelect = $this->createMock(IQueryBuilder::class);
		$companySelect->method('expr')->willReturn($expr);
		$companySelect->method('select')->willReturnSelf();
		$companySelect->method('from')->willReturnSelf();
		$companySelect->method('where')->willReturnSelf();
		$companySelect->method('andWhere')->willReturnSelf();
		$companySelect->method('setMaxResults')->willReturnSelf();
		$companySelect->method('createNamedParameter')->willReturn('p');
		$companySelect->method('executeQuery')->willReturn($companyResult);

		$triageUpdate = $this->createMock(IQueryBuilder::class);
		$triageUpdate->method('expr')->willReturn($expr);
		$triageUpdate->method('update')->willReturnSelf();
		$triageUpdate->method('set')->willReturnSelf();
		$triageUpdate->method('where')->willReturnSelf();
		$triageUpdate->method('andWhere')->willReturnSelf();
		$triageUpdate->method('createNamedParameter')->willReturn('p');
		$triageUpdate->expects($this->once())->method('executeStatement')->willReturn(1);

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$metaResult = $this->createMock(IResult::class);
		$metaResult->method('fetch')->willReturn(['kind' => 'team', 'owner_user_id' => null]);
		$metaResult->method('closeCursor');
		$metaQb = $this->createMock(IQueryBuilder::class);
		$metaQb->method('expr')->willReturn($expr);
		$metaQb->method('select')->willReturnSelf();
		$metaQb->method('from')->willReturnSelf();
		$metaQb->method('where')->willReturnSelf();
		$metaQb->method('setMaxResults')->willReturnSelf();
		$metaQb->method('createNamedParameter')->willReturn('p');
		$metaQb->method('executeQuery')->willReturn($metaResult);

		$db->method('getQueryBuilder')->willReturnCallback(function () use (
			&$calls,
			$metaQb,
			$insertQb,
			$selectQb,
			$updateQb,
			$companySelect,
			$triageUpdate,
		) {
			return match ($calls++) {
				0 => $metaQb,
				1 => $insertQb,
				2 => $selectQb,
				3 => $updateQb,
				4 => $companySelect,
				5 => $triageUpdate,
				default => throw new \RuntimeException('unexpected qb #' . $calls),
			};
		});

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$l10n,
			new HtmlSanitizeService(),
		);

		$match = $svc->ingestMessage(
			1,
			1,
			3,
			[
				'uid' => '99',
				'from' => 'you@office.example',
				'to' => ['buyer@customer.example', 'shared@office.example'],
				'subject' => 'Offer',
				'snippet' => 'Offer',
				'sentAt' => '2026-08-14 07:00:00',
			],
			[
				['companyId' => 1, 'email' => 'you@office.example'],
				['companyId' => 9, 'email' => 'buyer@customer.example'],
			],
			['you@office.example', 'shared@office.example'],
		);

		$this->assertSame(9, $match['companyId']);
		$this->assertSame('recipient_email', $match['how']);
		$this->assertSame('["buyer@customer.example","shared@office.example"]', $toJson);
	}

	public function testDuplicateUidSkipsRefreshWhenAlreadyMatched(): void
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-14 08:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$insertQb = $this->createMock(IQueryBuilder::class);
		$insertQb->method('insert')->willReturnSelf();
		$insertQb->method('values')->willReturnSelf();
		$insertQb->method('createNamedParameter')->willReturn('p');
		$insertQb->method('executeStatement')->willThrowException(new \RuntimeException('unique'));

		$selectResult = $this->createMock(IResult::class);
		$selectResult->method('fetch')->willReturn([
			'id' => 7,
			'to_addrs' => null,
			'from_addr' => 'you@office.example',
			'triage_status' => 'matched',
			'company_id' => 9,
		]);
		$selectResult->method('closeCursor');

		$selectQb = $this->createMock(IQueryBuilder::class);
		$selectQb->method('expr')->willReturn($expr);
		$selectQb->method('select')->willReturnSelf();
		$selectQb->method('from')->willReturnSelf();
		$selectQb->method('where')->willReturnSelf();
		$selectQb->method('andWhere')->willReturnSelf();
		$selectQb->method('setMaxResults')->willReturnSelf();
		$selectQb->method('createNamedParameter')->willReturn('p');
		$selectQb->method('executeQuery')->willReturn($selectResult);

		$calls = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$metaResult = $this->createMock(IResult::class);
		$metaResult->method('fetch')->willReturn(['kind' => 'team', 'owner_user_id' => null]);
		$metaResult->method('closeCursor');
		$metaQb = $this->createMock(IQueryBuilder::class);
		$metaQb->method('expr')->willReturn($expr);
		$metaQb->method('select')->willReturnSelf();
		$metaQb->method('from')->willReturnSelf();
		$metaQb->method('where')->willReturnSelf();
		$metaQb->method('setMaxResults')->willReturnSelf();
		$metaQb->method('createNamedParameter')->willReturn('p');
		$metaQb->method('executeQuery')->willReturn($metaResult);

		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls, $metaQb, $insertQb, $selectQb) {
			return match ($calls++) {
				0 => $metaQb,
				1 => $insertQb,
				2 => $selectQb,
				default => throw new \RuntimeException('unexpected qb'),
			};
		});

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$time,
			$l10n,
			new HtmlSanitizeService(),
		);

		$match = $svc->ingestMessage(
			1,
			1,
			3,
			[
				'uid' => '99',
				'from' => 'you@office.example',
				'to' => ['buyer@customer.example'],
				'subject' => 'Offer',
			],
			[['companyId' => 9, 'email' => 'buyer@customer.example']],
			['you@office.example'],
		);

		$this->assertSame(9, $match['companyId']);
		$this->assertSame(3, $calls, 'must not update after matched row');
	}

	public function testRefreshBackfillRequiresOpenTriageStatus(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$pos = strpos($src, 'function refreshOpenIndexedMessage');
		$this->assertNotFalse($pos);
		$chunk = substr($src, $pos, 9000);
		$this->assertStringContainsString(
			"'triage_status', \$upd->createNamedParameter('open')",
			$chunk,
			'duplicate-uid backfill must never overwrite matched/discarded mail',
		);
		$this->assertStringContainsString(
			"(string)(\$row['triage_status'] ?? '') !== 'open'",
			$chunk,
			'refresh must bail when triage is no longer open',
		);
	}
}
