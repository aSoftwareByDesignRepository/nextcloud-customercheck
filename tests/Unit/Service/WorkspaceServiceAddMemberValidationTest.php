<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
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

	/** @var ITimeFactory&MockObject */
	private ITimeFactory $time;

	private WorkspaceService $service;

	protected function setUp(): void
	{
		$this->users = $this->createMock(IUserManager::class);
		$this->members = $this->createMock(WorkspaceMemberMapper::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$this->service = new WorkspaceService(
			$this->createMock(WorkspaceMapper::class),
			$this->members,
			$this->createMock(AccessControlService::class),
			$this->createMock(IDBConnection::class),
			$this->time,
			$l10n,
			$this->users,
		);
	}

	public function testAddMemberRejectsUnknownUser(): void
	{
		$this->users->expects($this->once())->method('userExists')->with('ghost')->willReturn(false);
		$this->members->expects($this->never())->method('insert');
		$this->members->expects($this->never())->method('update');

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

		try {
			$this->service->addMember(1, 'new', 'member', true, 'actor');
			$this->fail('Expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_WS_MEMBER_LIMIT, $e->getErrorCode());
		}
	}
}
