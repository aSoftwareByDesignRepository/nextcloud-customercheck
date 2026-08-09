<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Cross-workspace object access must 404 with no existence leak (product §3.3 / §21 ACL).
 */
final class WorkspaceAccessIsolationTest extends TestCase
{
	private WorkspaceAccessService $svc;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var WorkspaceMapper&MockObject */
	private WorkspaceMapper $workspaces;
	/** @var WorkspaceMemberMapper&MockObject */
	private WorkspaceMemberMapper $members;
	/** @var IConfig&MockObject */
	private IConfig $config;
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var IL10N&MockObject */
	private IL10N $l10n;

	protected function setUp(): void
	{
		$this->workspaces = $this->createMock(WorkspaceMapper::class);
		$this->members = $this->createMock(WorkspaceMemberMapper::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->request = $this->createMock(IRequest::class);
		$time = $this->createMock(ITimeFactory::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $m) => $m);

		$this->svc = new WorkspaceAccessService(
			$this->workspaces,
			$this->members,
			$this->access,
			$this->config,
			$this->request,
			$time,
			$this->l10n,
		);
	}

	public function testAssertCompanyInWorkspaceRejectsMismatch(): void
	{
		$this->expectException(NotFoundException::class);
		$this->svc->assertCompanyInWorkspace(10, 20);
	}

	public function testAssertCompanyInWorkspaceRejectsZeroWorkspace(): void
	{
		$this->expectException(NotFoundException::class);
		$this->svc->assertCompanyInWorkspace(0, 5);
	}

	public function testAssertCompanyInWorkspaceAllowsMatch(): void
	{
		$this->svc->assertCompanyInWorkspace(7, 7);
		$this->addToAssertionCount(1);
	}

	public function testAssertUserCanAccessCompany404WhenNotMember(): void
	{
		$ws = new Workspace();
		$ws->setId(5);
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->workspaces->method('findById')->willReturn($ws);
		$this->members->method('findMembershipOrNull')->willReturn(null);
		$this->request->method('getParam')->willReturn(null);

		$this->expectException(NotFoundException::class);
		$this->svc->assertUserCanAccessCompany('alice', 5, 'read');
	}

	public function testMessageIsGenericNotFound(): void
	{
		try {
			$this->svc->assertCompanyInWorkspace(1, 2);
			$this->fail('expected NotFoundException');
		} catch (NotFoundException $e) {
			$this->assertStringContainsString('not found', strtolower($e->getMessage()));
			$this->assertStringNotContainsString('workspace 1', strtolower($e->getMessage()));
			$this->assertStringNotContainsString('workspace 2', strtolower($e->getMessage()));
		}
	}

	public function testListVisibleSummariesForMemberUsesCanonical(): void
	{
		$ws = new Workspace();
		$ws->setId(3);
		$ws->setSlug('org');
		$ws->setName('Organisation');
		$ws->setIsDefault(1);
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->workspaces->expects($this->once())->method('findDefault')->willReturn($ws);
		$this->workspaces->expects($this->never())->method('findForUser');
		$this->workspaces->expects($this->never())->method('findAll');
		$this->members->method('findMembershipOrNull')->with(3, 'alice')->willReturn(
			(static function () {
				$m = new \OCA\CustomerCheck\Db\WorkspaceMember();
				$m->setWorkspaceId(3);
				$m->setUserId('alice');
				$m->setRole('member');
				return $m;
			})(),
		);

		$list = $this->svc->listVisibleSummaries('alice');
		$this->assertCount(1, $list);
		$this->assertSame(3, $list[0]['id']);
		$this->assertSame('Organisation', $list[0]['name']);
		$this->assertSame('org', $list[0]['slug']);
		$this->assertFalse($list[0]['archived']);
		$this->assertTrue($list[0]['isDefault']);
	}

	public function testListVisibleSummariesForAppAdminUsesCanonical(): void
	{
		$ws = new Workspace();
		$ws->setId(1);
		$ws->setSlug('a');
		$ws->setName('A');
		$ws->setIsDefault(1);
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->workspaces->expects($this->once())->method('findDefault')->willReturn($ws);
		$this->workspaces->expects($this->never())->method('findAll');
		$this->workspaces->expects($this->never())->method('findForUser');

		$list = $this->svc->listVisibleSummaries('admin');
		$this->assertCount(1, $list);
		$this->assertSame(1, $list[0]['id']);
		$this->assertTrue($list[0]['isDefault']);
	}
}
