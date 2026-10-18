<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Public;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Public\CrmCompanyLinkReadFacade;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * F14 producer side — the picker search must stay workspace-scoped and
 * fail closed: no app access or no visible workspaces → empty, never the
 * whole book.
 */
class CrmCompanyLinkPickerTest extends TestCase
{
	private CompanyMapper $mapper;
	private AccessControlService $acl;
	private WorkspaceAccessService $ws;
	private CrmCompanyLinkReadFacade $facade;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(CompanyMapper::class);
		$this->acl = $this->createMock(AccessControlService::class);
		$this->ws = $this->createMock(WorkspaceAccessService::class);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/customercheck/companies/9');
		$this->facade = new CrmCompanyLinkReadFacade($this->mapper, $this->acl, $urls, $this->ws);
	}

	public function testEmptyQueryReturnsNothing(): void
	{
		$this->acl->expects($this->never())->method('canUseApp');
		$this->assertSame([], $this->facade->searchForPicker('alice', '   '));
	}

	public function testNoAppAccessReturnsNothing(): void
	{
		$this->acl->method('canUseApp')->willReturn(false);
		$this->ws->expects($this->never())->method('listAccessibleWorkspaceIds');
		$this->assertSame([], $this->facade->searchForPicker('alice', 'acme'));
	}

	public function testNoVisibleWorkspacesFailsClosed(): void
	{
		$this->acl->method('canUseApp')->willReturn(true);
		$this->ws->method('listAccessibleWorkspaceIds')->willReturn([]);
		$this->mapper->expects($this->never())->method('search');
		$this->assertSame([], $this->facade->searchForPicker('alice', 'acme'));
	}

	public function testSearchIsScopedToAccessibleWorkspaces(): void
	{
		$this->acl->method('canUseApp')->willReturn(true);
		$this->ws->method('listAccessibleWorkspaceIds')->with('alice')->willReturn([3, 7]);
		$company = new Company();
		$company->setId(9);
		$company->setName('Acme Pflege');
		$company->setWorkspaceId(3);
		$this->mapper->expects($this->once())
			->method('search')
			->with($this->callback(fn (array $f) => ($f['workspaceIds'] ?? null) === [3, 7]
				&& ($f['archived'] ?? null) === false
				&& ($f['search'] ?? '') === 'acme'))
			->willReturn([$company]);
		$out = $this->facade->searchForPicker('alice', 'acme');
		$this->assertCount(1, $out);
		$this->assertSame(9, $out[0]['crmCompanyId']);
		$this->assertSame('Acme Pflege', $out[0]['name']);
		$this->assertStringContainsString('workspaceId=3', $out[0]['deepLink']);
	}

	public function testMapperFailureDegradesToEmpty(): void
	{
		$this->acl->method('canUseApp')->willReturn(true);
		$this->ws->method('listAccessibleWorkspaceIds')->willReturn([3]);
		$this->mapper->method('search')->willThrowException(new \RuntimeException('schema drift'));
		$this->assertSame([], $this->facade->searchForPicker('alice', 'acme'));
	}
}
