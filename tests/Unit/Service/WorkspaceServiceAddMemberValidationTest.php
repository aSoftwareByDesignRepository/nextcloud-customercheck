<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CirclesDirectoryService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WorkspaceServiceAddMemberValidationTest extends TestCase
{
	/** @var IUserManager&MockObject */
	private IUserManager $users;

	/** @var WorkspaceMemberMapper&MockObject */
	private WorkspaceMemberMapper $members;

	/** @var WorkspaceMapper&MockObject */
	private WorkspaceMapper $workspaces;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var ITimeFactory&MockObject */
	private ITimeFactory $time;

	private WorkspaceService $service;

	protected function setUp(): void
	{
		$this->users = $this->createMock(IUserManager::class);
		$this->members = $this->createMock(WorkspaceMemberMapper::class);
		$this->workspaces = $this->createMock(WorkspaceMapper::class);
		$this->workspaces->method('lockRow')->willReturn(true);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$this->service = new WorkspaceService(
			$this->workspaces,
			$this->members,
			$this->createMock(AccessControlService::class),
			$this->db,
			$this->time,
			$l10n,
			$this->users,
			$this->groups,
			$this->createMock(CirclesDirectoryService::class),
		);
	}

	public function testAddMemberRejectsUnknownUser(): void
	{
		$this->users->expects($this->once())->method('userExists')->with('ghost')->willReturn(false);
		$this->members->expects($this->never())->method('insert');
		$this->members->expects($this->never())->method('update');
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->service->addMember(1, 'ghost', 'member', true, 'actor');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertArrayHasKey('userId', $e->getFields());
			$this->assertStringContainsString('Unknown Nextcloud user', $e->getFields()['userId']);
		}
	}

	public function testAddMemberRejectsBlankUserId(): void
	{
		$this->users->expects($this->never())->method('userExists');
		$this->expectException(ValidationException::class);
		$this->service->addMember(1, '   ', 'member', true, 'actor');
	}

	public function testAddMemberUpdatesExistingEvenWhenAtLimit(): void
	{
		$this->users->method('userExists')->with('ae')->willReturn(true);
		$existing = new \OCA\CustomerCheck\Db\WorkspaceMember();
		$existing->setId(9);
		$existing->setWorkspaceId(1);
		$existing->setUserId('ae');
		$existing->setRole('member');
		$existing->setCanMailSend(0);
		$this->members->expects($this->once())->method('findMembershipOrNull')->with(1, 'ae')->willReturn($existing);
		$this->members->expects($this->never())->method('countByWorkspace');
		$this->members->expects($this->never())->method('insert');
		$this->members->expects($this->once())->method('update')->willReturnCallback(
			static function ($m) {
				return $m;
			},
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->workspaces->expects($this->once())->method('lockRow')->with(1)->willReturn(true);

		$result = $this->service->addMember(1, 'ae', 'manager', true, 'actor');
		$this->assertSame('manager', $result->getRole());
		$this->assertSame(1, $result->getCanMailSend());
	}

	public function testAddMemberEnforcesLimitOnInsert(): void
	{
		$this->users->method('userExists')->with('new')->willReturn(true);
		$this->members->method('findMembershipOrNull')->willReturn(null);
		$this->members->method('countByWorkspace')->with(1)->willReturn(Constants::WS_MAX_MEMBERS);
		$this->members->expects($this->never())->method('insert');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->service->addMember(1, 'new', 'member', true, 'actor');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_WS_MEMBER_LIMIT, $e->getErrorCode());
		}
	}

	public function testAddMemberRollsBackWhenWorkspaceMissing(): void
	{
		$workspaces = $this->createMock(WorkspaceMapper::class);
		$workspaces->expects($this->once())->method('lockRow')->with(99)->willReturn(false);
		$members = $this->createMock(WorkspaceMemberMapper::class);
		$members->expects($this->never())->method('insert');
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');
		$this->users->method('userExists')->with('ae')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$service = new WorkspaceService(
			$workspaces,
			$members,
			$this->createMock(AccessControlService::class),
			$db,
			$this->time,
			$l10n,
			$this->users,
			$this->groups,
			$this->createMock(CirclesDirectoryService::class),
		);

		try {
			$service->addMember(99, 'ae', 'member', true, 'actor');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_VALIDATION, $e->getErrorCode());
			$this->assertArrayHasKey('workspaceId', $e->getFields());
		}
	}

	public function testImportMembersFromGroupRejectsUnknownGroup(): void
	{
		$this->groups->method('get')->with('missing')->willReturn(null);
		$this->expectException(ValidationException::class);
		$this->service->importMembersFromGroup(1, 'missing', 'member', false, 'actor');
	}

	public function testImportMembersFromGroupAddsAndSkipsDisabled(): void
	{
		$enabled = $this->createMock(\OCP\IUser::class);
		$enabled->method('getUID')->willReturn('alice');
		$enabled->method('isEnabled')->willReturn(true);

		$disabled = $this->createMock(\OCP\IUser::class);
		$disabled->method('getUID')->willReturn('bob');
		$disabled->method('isEnabled')->willReturn(false);

		$group = $this->createMock(\OCP\IGroup::class);
		$group->method('getDisplayName')->willReturn('Sales');
		$group->method('getUsers')->willReturn([$enabled, $disabled]);
		$this->groups->method('get')->with('sales')->willReturn($group);

		$this->users->method('get')->with('alice')->willReturn($enabled);
		$this->users->method('userExists')->with('alice')->willReturn(true);
		$this->members->method('findMembershipOrNull')->willReturn(null);
		$this->members->method('countByWorkspace')->willReturn(0);
		$this->members->expects($this->once())->method('insert')->willReturnCallback(
			static function ($m) {
				return $m;
			},
		);

		$result = $this->service->importMembersFromGroup(1, 'sales', 'member', true, 'actor');
		$this->assertSame(1, $result['added']);
		$this->assertSame(0, $result['updated']);
		$this->assertSame(1, $result['skipped']);
		$this->assertFalse($result['limitReached']);
		$this->assertSame('Sales', $result['groupName']);
	}

	public function testImportMembersFromGroupSetsLimitReached(): void
	{
		$alice = $this->createMock(\OCP\IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('isEnabled')->willReturn(true);
		$group = $this->createMock(\OCP\IGroup::class);
		$group->method('getDisplayName')->willReturn('Full');
		$group->method('getUsers')->willReturn([$alice]);
		$this->groups->method('get')->with('full')->willReturn($group);
		$this->users->method('get')->with('alice')->willReturn($alice);
		$this->users->method('userExists')->with('alice')->willReturn(true);
		$this->members->method('findMembershipOrNull')->willReturn(null);
		$this->members->method('countByWorkspace')->willReturn(Constants::WS_MAX_MEMBERS);
		$this->members->expects($this->never())->method('insert');

		$result = $this->service->importMembersFromGroup(1, 'full', 'member', false, 'actor');
		$this->assertSame(0, $result['added']);
		$this->assertSame(1, $result['skipped']);
		$this->assertTrue($result['limitReached']);
	}

	public function testImportMembersFromCircleRequiresCircles(): void
	{
		$circles = $this->createMock(CirclesDirectoryService::class);
		$circles->method('isAvailable')->willReturn(false);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$service = new WorkspaceService(
			$this->workspaces,
			$this->members,
			$this->createMock(AccessControlService::class),
			$this->db,
			$this->time,
			$l10n,
			$this->users,
			$this->groups,
			$circles,
		);
		$this->expectException(ValidationException::class);
		$service->importMembersFromCircle(1, 'c1', 'member', false, 'actor');
	}

	public function testImportMembersFromCircleAddsLocalUsers(): void
	{
		$circles = $this->createMock(CirclesDirectoryService::class);
		$circles->method('isAvailable')->willReturn(true);
		$circles->method('localUserIdsInCircle')->with('circ-a', 'actor')->willReturn([
			'circleId' => 'circ-a',
			'circleName' => 'Team A',
			'userIds' => ['alice', 'ghost'],
		]);
		$alice = $this->createMock(\OCP\IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('isEnabled')->willReturn(true);
		$this->users->method('get')->willReturnCallback(static function (string $uid) use ($alice) {
			return $uid === 'alice' ? $alice : null;
		});
		$this->users->method('userExists')->with('alice')->willReturn(true);
		$this->members->method('findMembershipOrNull')->willReturn(null);
		$this->members->method('countByWorkspace')->willReturn(0);
		$this->members->expects($this->once())->method('insert')->willReturnCallback(
			static function ($m) {
				return $m;
			},
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$service = new WorkspaceService(
			$this->workspaces,
			$this->members,
			$this->createMock(AccessControlService::class),
			$this->db,
			$this->time,
			$l10n,
			$this->users,
			$this->groups,
			$circles,
		);

		$result = $service->importMembersFromCircle(1, 'circ-a', 'member', true, 'actor');
		$this->assertSame(1, $result['added']);
		$this->assertSame(1, $result['skipped']);
		$this->assertSame('Team A', $result['circleName']);
	}
}
