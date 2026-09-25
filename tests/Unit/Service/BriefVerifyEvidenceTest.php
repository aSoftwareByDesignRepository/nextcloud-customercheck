<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AgreementRenewalMoney;
use OCA\CustomerCheck\Service\BriefService;
use OCA\CustomerCheck\Service\MoneyService;
use OCA\CustomerCheck\Service\PulseService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * Brief done must refuse honor-system verify without evidence (§10.3).
 */
final class BriefVerifyEvidenceTest extends TestCase
{
	public function testConfirmRejectsNonMemberOwner(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$result = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch', 'closeCursor'])->getMock();
		$result->method('fetch')->willReturn(false);
		$expr = $this->getMockBuilder(\stdClass::class)->addMethods(['eq'])->getMock();
		$expr->method('eq')->willReturn('eq');
		$qb = $this->getMockBuilder(\stdClass::class)
			->addMethods(['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'executeQuery', 'expr', 'createNamedParameter'])
			->getMock();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getSettings')->willReturn([
			'wipLimit' => 3,
			'leadSlaHours' => 24,
			'stuckChaseDays' => 3,
			'mailRetentionDays' => 365,
			'digestEnabled' => true,
			'personalMailEnabled' => false,
			'personalMailDsgvoAt' => null,
			'personalMailDsgvoBy' => null,
			'reviewSources' => [],
		]);
		$workspaces->method('assertOwnerCandidate')->willThrowException(
			ValidationException::field('VALIDATION', 'ownerUserId', 'nope'),
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-31 12:00:00'));
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$svc = new BriefService(
			$db,
			$workspaces,
			$this->createMock(PulseService::class),
			$this->createMock(MoneyService::class),
			$this->createMock(AgreementRenewalMoney::class),
			$time,
			$l10n,
			$locking,
		);

		try {
			$svc->confirm(7, [
				'kind' => 'custom',
				'title' => 'Call Acme',
				'ownerUserId' => 'mallory',
			], 'actor');
			$this->fail('non-member owner must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('ownerUserId', $e->getFields());
		}
	}

	private function serviceWithCommitRow(array $row): BriefService
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);

		$qbSelect = $this->getMockBuilder(\stdClass::class)
			->addMethods(['select', 'from', 'where', 'andWhere', 'setMaxResults', 'executeQuery', 'expr', 'createNamedParameter'])
			->getMock();
		$expr = $this->getMockBuilder(\stdClass::class)->addMethods(['eq'])->getMock();
		$expr->method('eq')->willReturn('eq');
		$qbSelect->method('expr')->willReturn($expr);
		$qbSelect->method('createNamedParameter')->willReturnArgument(0);
		$qbSelect->method('select')->willReturnSelf();
		$qbSelect->method('from')->willReturnSelf();
		$qbSelect->method('where')->willReturnSelf();
		$qbSelect->method('andWhere')->willReturnSelf();
		$qbSelect->method('setMaxResults')->willReturnSelf();
		$result = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch', 'closeCursor'])->getMock();
		$result->method('fetch')->willReturn($row);
		$qbSelect->method('executeQuery')->willReturn($result);

		$db->method('getQueryBuilder')->willReturn($qbSelect);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		return new BriefService(
			$db,
			$this->createMock(WorkspaceService::class),
			$this->createMock(PulseService::class),
			$this->createMock(MoneyService::class),
			$this->createMock(AgreementRenewalMoney::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			$this->createMock(ILockingProvider::class),
		);
	}

	public function testDoneWithoutEvidenceRejected(): void
	{
		$svc = $this->serviceWithCommitRow([
			'id' => 1,
			'week_key' => '2026-W31',
			'kind' => 'ar_chase',
			'title' => 'Chase Acme',
			'company_id' => 5,
			'owner_user_id' => 'alex',
			'status' => 'committed',
			'due_on' => null,
			'carry_streak' => 0,
			'counts_wip' => 1,
		]);
		$this->expectException(ValidationException::class);
		$svc->verify(1, 1, 'done', 'alex', null, null, 'manager');
	}

	public function testMemberCannotVerifyOthers(): void
	{
		$svc = $this->serviceWithCommitRow([
			'id' => 1,
			'week_key' => '2026-W31',
			'kind' => 'ar_chase',
			'title' => 'Chase Acme',
			'company_id' => 5,
			'owner_user_id' => 'alex',
			'status' => 'committed',
			'due_on' => null,
			'carry_streak' => 0,
			'counts_wip' => 1,
		]);
		$this->expectException(ValidationException::class);
		$svc->verify(1, 1, 'missed', 'sam', null, null, 'member');
	}

	public function testClosedCommitmentCannotBeReVerified(): void
	{
		$svc = $this->serviceWithCommitRow([
			'id' => 1,
			'week_key' => '2026-W31',
			'kind' => 'ar_chase',
			'title' => 'Chase Acme',
			'company_id' => 5,
			'owner_user_id' => 'alex',
			'status' => 'done',
			'due_on' => null,
			'carry_streak' => 0,
			'counts_wip' => 1,
		]);
		$this->expectException(ValidationException::class);
		$svc->verify(1, 1, 'missed', 'alex', null, null, 'manager');
	}
}
