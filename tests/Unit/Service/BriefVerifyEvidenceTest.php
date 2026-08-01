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
