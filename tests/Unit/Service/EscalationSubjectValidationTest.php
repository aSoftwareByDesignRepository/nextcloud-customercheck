<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\EscalationService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * Escalation subjectUserId is a teammate uid — never free text
 * (§13.5 mass-assignment gate, same class as ownerUserId on deals).
 */
final class EscalationSubjectValidationTest extends TestCase
{
	public function testCreateRejectsNonMemberSubject(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('assertOwnerCandidate')->willThrowException(
			ValidationException::field(Constants::ERR_VALIDATION, 'subjectUserId', 'nope'),
		);

		$svc = new EscalationService(
			$db,
			$this->createMock(ITimeFactory::class),
			$l10n,
			$locking,
			$workspaces,
		);

		try {
			$svc->create(7, [
				'subjectUserId' => 'mallory',
				'reason' => 'No response in two weeks',
			], 'actor');
			$this->fail('non-member subject must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('subjectUserId', $e->getFields());
		}
	}
}
