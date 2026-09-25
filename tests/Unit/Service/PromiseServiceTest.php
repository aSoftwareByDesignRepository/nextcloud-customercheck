<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\PromiseService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class PromiseServiceTest extends TestCase
{
	public function testCreateRequiresDueOn(): void
	{
		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$svc = $this->service($this->createMock(IDBConnection::class), $companies);

		try {
			$svc->create(9, ['title' => 'Ship deck'], 'alex');
			$this->fail('missing dueOn must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('dueOn', $e->getFields());
		}
	}

	public function testCreateRequiresTitle(): void
	{
		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$svc = $this->service($this->createMock(IDBConnection::class), $companies);

		try {
			$svc->create(9, ['title' => '  ', 'dueOn' => '2026-08-01'], 'alex');
			$this->fail('empty title must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('title', $e->getFields());
		}
	}

	public function testCompleteRequiresEvidence(): void
	{
		$svc = $this->service($this->createMock(IDBConnection::class), $this->createMock(CompanyService::class));

		try {
			$svc->complete(1, 3, " \t ", 'alex', 9);
			$this->fail('empty evidence must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('evidence', $e->getFields());
		}
	}

	public function testCompleteRequiresCompanyId(): void
	{
		$svc = $this->service($this->createMock(IDBConnection::class), $this->createMock(CompanyService::class));

		try {
			$svc->complete(1, 3, 'Shipped', 'alex', 0);
			$this->fail('missing companyId must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('companyId', $e->getFields());
		}
	}

	public function testCancelRequiresCompanyId(): void
	{
		$svc = $this->service($this->createMock(IDBConnection::class), $this->createMock(CompanyService::class));

		try {
			$svc->cancel(1, 3, 'alex', 0);
			$this->fail('missing companyId must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('companyId', $e->getFields());
		}
	}

	public function testCreateRejectsForeignPersonId(): void
	{
		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$person = new \OCA\CustomerCheck\Db\Person();
		$person->setId(5);
		$person->setCompanyId(88);
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->with(5)->willReturn($person);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$svc = $this->service($db, $companies, $persons);

		try {
			$svc->create(9, ['title' => 'Ship', 'dueOn' => '2026-08-01', 'personId' => 5], 'alex');
			$this->fail('foreign person must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('personId', $e->getFields());
		}
	}

	public function testCreateRejectsForeignOwnerForReassignCallers(): void
	{
		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('assertOwnerCandidate')->willThrowException(
			ValidationException::field(Constants::ERR_VALIDATION, 'ownerUserId', 'nope'),
		);

		$svc = $this->service($this->createMock(IDBConnection::class), $companies, null, $workspaces);
		try {
			$svc->create(9, [
				'title' => 'Ship',
				'dueOn' => '2026-08-01',
				'ownerUserId' => 'mallory',
			], 'alex', true);
			$this->fail('foreign owner must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('ownerUserId', $e->getFields());
		}
	}

	public function testCreateStripsOwnerWithoutReassignCapability(): void
	{
		$company = new Company();
		$company->setId(9);
		$company->setWorkspaceId(3);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$captured = null;
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnCallback(static function (array $v) use ($qb, &$captured) {
			$captured = $v;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willReturn(1);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('lastInsertId')->willReturn(7);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects($this->never())->method('assertOwnerCandidate');

		$svc = $this->service($db, $companies, null, $workspaces);
		$out = $svc->create(9, [
			'title' => 'Ship',
			'dueOn' => '2026-08-01',
			'ownerUserId' => 'mallory',
		], 'alex');

		$this->assertSame(7, $out['id']);
		$this->assertSame('alex', $captured['owner_user_id']);
	}

	private function service(
		IDBConnection $db,
		CompanyService $companies,
		?PersonMapper $persons = null,
		?WorkspaceService $workspaces = null,
	): PromiseService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-31 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		return new PromiseService(
			$db,
			$companies,
			new DisplayLabelService($l10n),
			$time,
			$l10n,
			$persons ?? $this->createMock(PersonMapper::class),
			$workspaces,
		);
	}
}
