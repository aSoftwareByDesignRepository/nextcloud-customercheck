<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\CoverageService;
use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Strategy account-plan updates must refuse silent LWW (stamp + CAS).
 */
final class AccountPlanOptimisticLockTest extends TestCase
{
	public function testUpdateWithoutUpdatedAtIsRejected(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setWorkspaceId(1);
		$company->setName('Acme');

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);
		$companies->expects($this->once())->method('lockRow')->with(4);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$select = $this->createMock(IQueryBuilder::class);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('setMaxResults')->willReturnSelf();
		$select->method('createNamedParameter')->willReturn('p');
		$select->method('expr')->willReturn($expr);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn([
			'id' => 9,
			'company_id' => 4,
			'workspace_id' => 1,
			'goal' => 'Grow',
			'priority' => 'p2',
			'owner_user_id' => 'mgr',
			'status' => 'active',
			'horizon' => null,
			'next_review_on' => null,
			'updated_at' => '2026-08-01 10:00:00',
		]);
		$result->method('closeCursor');
		$select->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $t): bool => $t === 'crm_acct_plan');
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->method('getQueryBuilder')->willReturn($select);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$labels = new DisplayLabelService($l10n);
		$coverage = $this->createMock(CoverageService::class);
		$coverage->method('forCompany')->willReturn(['flags' => []]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-02T12:00:00+00:00'));
		$directory = $this->createMock(DirectoryOptionsService::class);
		$directory->method('resolveUsers')->willReturn([]);

		$svc = new AccountPlanService($db, $companies, $coverage, $labels, $time, $l10n, $directory);
		$this->expectException(ConflictException::class);
		$svc->upsert(4, ['goal' => 'New goal', 'priority' => 'p1'], 'mgr');
	}

	public function testContractRequiresOptimisticLockOnUpsert(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AccountPlanService.php');
		$this->assertStringContainsString('OptimisticLock::assertFresh', $src);
		$this->assertStringContainsString("'updatedAt'", $src);
		$this->assertStringContainsString('ConflictException', $src);
	}
}
