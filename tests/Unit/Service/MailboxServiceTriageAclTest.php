<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\MailboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Mail triage must not attach messages to foreign-workspace companies.
 */
final class MailboxServiceTriageAclTest extends TestCase
{
	public function testTriageRejectsCompanyOutsideWorkspace(): void
	{
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$select = $this->createMock(IQueryBuilder::class);
		$select->method('expr')->willReturn($expr);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('andWhere')->willReturnSelf();
		$select->method('setMaxResults')->willReturnSelf();
		$select->method('createNamedParameter')->willReturn('p');
		$select->expects($this->once())->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($select);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);

		$this->expectException(NotFoundException::class);
		$svc->triageAssign(9, 1, 99);
	}

	public function testTriageRejectsMissingCompanyId(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new MailboxService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);

		$this->expectException(ValidationException::class);
		try {
			$svc->triageAssign(9, 1, 0);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			throw $e;
		}
	}
}
