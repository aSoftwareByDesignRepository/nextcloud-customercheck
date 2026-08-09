<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Companion listForMobile — single organisation pins to the canonical book.
 */
final class WorkspaceAccessListForMobileTest extends TestCase
{
	/** @var WorkspaceMapper&MockObject */
	private WorkspaceMapper $workspaces;
	/** @var WorkspaceMemberMapper&MockObject */
	private WorkspaceMemberMapper $members;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var IConfig&MockObject */
	private IConfig $config;
	private WorkspaceAccessService $svc;

	protected function setUp(): void
	{
		$this->workspaces = $this->createMock(WorkspaceMapper::class);
		$this->members = $this->createMock(WorkspaceMemberMapper::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->config = $this->createMock(IConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $m) => $m);

		$this->svc = new WorkspaceAccessService(
			$this->workspaces,
			$this->members,
			$this->access,
			$this->config,
			$this->createMock(IRequest::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
		);
	}

	private function workspace(int $id, string $name, string $slug, bool $isDefault = false): Workspace
	{
		$ws = new Workspace();
		$ws->setId($id);
		$ws->setName($name);
		$ws->setSlug($slug);
		$ws->setIsDefault($isDefault ? 1 : 0);

		return $ws;
	}

	private function member(int $workspaceId, string $userId, string $role): WorkspaceMember
	{
		$m = new WorkspaceMember();
		$m->setWorkspaceId($workspaceId);
		$m->setUserId($userId);
		$m->setRole($role);

		return $m;
	}

	public function testAppAdminSeesOnlyCanonicalWithManagerRole(): void
	{
		$this->assertFalse(Constants::MULTI_WORKSPACE_ENABLED);
		$a = $this->workspace(1, 'A', 'a', true);
		$this->access->method('isAppAdmin')->with('admin')->willReturn(true);
		$this->workspaces->expects($this->once())->method('findDefault')->willReturn($a);
		$this->workspaces->expects($this->never())->method('findAll');
		$this->workspaces->expects($this->never())->method('findForUser');
		$this->members->method('findMembershipOrNull')->willReturn(null);
		$this->config->method('getUserValue')->willReturn('1');
		$this->config->expects($this->never())->method('setUserValue');

		$result = $this->svc->listForMobile('admin');

		$this->assertCount(1, $result['workspaces']);
		$this->assertSame(1, $result['workspaces'][0]['id']);
		$this->assertSame('manager', $result['workspaces'][0]['role']);
		$this->assertSame(1, $result['lastUsedWorkspaceId']);
	}

	public function testMemberSeesCanonicalWhenMember(): void
	{
		$ws = $this->workspace(3, 'Mine', 'mine', true);
		$this->access->method('isAppAdmin')->with('alice')->willReturn(false);
		$this->workspaces->expects($this->once())->method('findDefault')->willReturn($ws);
		$this->workspaces->expects($this->never())->method('findForUser');
		$this->members->method('findMembershipOrNull')
			->with(3, 'alice')
			->willReturn($this->member(3, 'alice', 'member'));
		$this->config->method('getUserValue')->willReturn('3');
		$this->config->expects($this->never())->method('setUserValue');

		$result = $this->svc->listForMobile('alice');

		$this->assertCount(1, $result['workspaces']);
		$this->assertSame(3, $result['workspaces'][0]['id']);
		$this->assertSame('member', $result['workspaces'][0]['role']);
		$this->assertSame(3, $result['lastUsedWorkspaceId']);
	}

	public function testMemberHiddenWhenNotOnCanonical(): void
	{
		$ws = $this->workspace(3, 'Org', 'org', true);
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->workspaces->method('findDefault')->willReturn($ws);
		$this->members->method('findMembershipOrNull')->with(3, 'alice')->willReturn(null);
		$this->config->method('getUserValue')->willReturn('3');
		$this->config->expects($this->never())->method('setUserValue');

		$result = $this->svc->listForMobile('alice');

		$this->assertSame([], $result['workspaces']);
		$this->assertNull($result['lastUsedWorkspaceId']);
	}

	public function testLastUsedClearedWhenNotVisible(): void
	{
		$ws = $this->workspace(3, 'Mine', 'mine', true);
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->workspaces->method('findDefault')->willReturn($ws);
		$this->members->method('findMembershipOrNull')
			->willReturn($this->member(3, 'alice', 'viewer'));
		$this->config->expects($this->once())
			->method('getUserValue')
			->with('alice', 'customercheck', Constants::USER_CONFIG_LAST_WORKSPACE, '0')
			->willReturn('99');
		$this->config->expects($this->never())->method('setUserValue');

		$result = $this->svc->listForMobile('alice');

		$this->assertNull($result['lastUsedWorkspaceId']);
		$this->assertSame('viewer', $result['workspaces'][0]['role']);
	}

	public function testDoesNotCallSetUserValueRemember(): void
	{
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->workspaces->method('findDefault')->willReturn(null);
		$this->workspaces->method('findAll')->with(1)->willReturn([]);
		$this->config->method('getUserValue')->willReturn('0');
		$this->config->expects($this->never())->method('setUserValue');

		$result = $this->svc->listForMobile('nobody');

		$this->assertSame([], $result['workspaces']);
		$this->assertNull($result['lastUsedWorkspaceId']);
	}
}
