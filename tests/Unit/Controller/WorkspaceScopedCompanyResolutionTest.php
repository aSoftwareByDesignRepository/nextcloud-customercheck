<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\Controller\WorkspaceScopedTrait;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use PHPUnit\Framework\TestCase;

/**
 * Multi-WS members must resolve company APIs via the company's workspace (§3.3),
 * not last-used alone (false 404).
 */
final class WorkspaceScopedCompanyResolutionTest extends TestCase
{
	use WorkspaceScopedTrait;

	private AccessControlService $accessControl;
	private WorkspaceAccessService $workspaces;
	private CompanyService $companies;

	protected function setUp(): void
	{
		$this->accessControl = $this->createMock(AccessControlService::class);
		$this->workspaces = $this->createMock(WorkspaceAccessService::class);
		$this->companies = $this->createMock(CompanyService::class);
	}

	public function testPinnedWrongWorkspaceIsNotFound(): void
	{
		$company = new Company();
		$company->setId(10);
		$company->setWorkspaceId(2);
		$this->companies->method('get')->with(10)->willReturn($company);

		$this->expectException(NotFoundException::class);
		$this->requireCompanyInResolvedWorkspace(10, 99);
	}

	public function testResolvesAgainstCompanyWorkspaceWithoutPin(): void
	{
		$company = new Company();
		$company->setId(10);
		$company->setWorkspaceId(7);
		$this->companies->method('get')->with(10)->willReturn($company);

		$this->accessControl->method('requireCanUseApp')->willReturn('alex');
		$ws = new \OCA\CustomerCheck\Db\Workspace();
		$ws->setId(7);
		$this->workspaces->expects($this->once())
			->method('assertCapability')
			->with('alex', 7, 'read');
		$this->workspaces->method('resolveForUser')->with('alex', 7)->willReturn([
			'workspace' => $ws,
			'role' => 'member',
			'isAppAdmin' => false,
			'member' => null,
			'needsPicker' => false,
		]);
		$this->workspaces->expects($this->once())
			->method('assertCompanyInWorkspace')
			->with(7, 7);

		$got = $this->requireCompanyInResolvedWorkspace(10, null);
		$this->assertSame(10, (int)$got->getId());
	}
}
