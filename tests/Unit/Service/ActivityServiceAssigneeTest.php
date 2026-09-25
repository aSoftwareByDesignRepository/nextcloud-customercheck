<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\VTodoService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Activity assignee membership + notify + default-to-actor. */
final class ActivityServiceAssigneeTest extends TestCase
{
	private ActivityMapper&MockObject $mapper;
	private CompanyMapper&MockObject $companies;
	private WorkspaceMemberMapper&MockObject $members;
	private NotificationService&MockObject $notifications;
	private TimelineService&MockObject $timeline;
	private IDBConnection&MockObject $db;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(ActivityMapper::class);
		$this->companies = $this->createMock(CompanyMapper::class);
		$this->members = $this->createMock(WorkspaceMemberMapper::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');
	}

	private function service(): ActivityService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-24T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$existingUsers0 = $this->createMock(\OCP\IUserManager::class);
		$existingUsers0->method('userExists')->willReturn(true);
		return new ActivityService(
			$this->mapper,
			$this->createMock(AccessControlService::class),
			$this->timeline,
			$this->createMock(VTodoService::class),
			$time,
			$l10n,
			$this->db,
			$this->createMock(DealMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(PersonMapper::class),
			$this->companies,
			$this->members,
			$this->notifications,
			$existingUsers0,
		);
	}

	private function companyInWorkspace(): Company
	{
		$c = new Company();
		$c->setId(1);
		$c->setName('Acme');
		$c->setWorkspaceId(9);

		return $c;
	}

	public function testCreateDefaultsAssigneeToActor(): void
	{
		$this->companies->method('findById')->with(1)->willReturn($this->companyInWorkspace());
		$member = new WorkspaceMember();
		$this->members->method('findMembershipOrNull')->with(9, 'alice')->willReturn($member);

		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			function (Activity $a): Activity {
				$this->assertSame('alice', $a->getAssigneeUserId());
				$a->setId(42);
				return $a;
			},
		);
		// Self-assign must not notify.
		$this->notifications->expects($this->never())->method('notifyUser');

		$out = $this->service()->create([
			'companyId' => 1,
			'title' => 'Call back',
			'type' => 'todo',
			'dueDate' => '2026-08-25',
		], 'alice');
		$this->assertSame(42, (int)$out->getId());
	}

	public function testCreateRejectsNonMemberAssignee(): void
	{
		$this->companies->method('findById')->with(1)->willReturn($this->companyInWorkspace());
		$this->members->method('findMembershipOrNull')->with(9, 'eve')->willReturn(null);
		$this->mapper->expects($this->never())->method('insert');

		try {
			$this->service()->create([
				'companyId' => 1,
				'title' => 'Call back',
				'type' => 'todo',
				'dueDate' => '2026-08-25',
				'assigneeUserId' => 'eve',
			], 'alice');
			$this->fail('non-member must fail');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('assigneeUserId', $e->getFields());
		}
	}

	public function testCreateNotifiesColleagueAssignee(): void
	{
		$this->companies->method('findById')->with(1)->willReturn($this->companyInWorkspace());
		$member = new WorkspaceMember();
		$this->members->method('findMembershipOrNull')->with(9, 'lara')->willReturn($member);

		$this->mapper->method('insert')->willReturnCallback(static function (Activity $a): Activity {
			$a->setId(7);
			return $a;
		});
		$this->notifications->expects($this->once())->method('notifyUser')->with(
			'lara',
			Constants::NOTIFY_ACTIVITY_ASSIGNED,
			'activity',
			'7',
			$this->callback(static function (array $p): bool {
				return ($p['title'] ?? '') === 'Call back'
					&& (int)($p['workspaceId'] ?? 0) === 9;
			}),
		);
		$this->timeline->expects($this->once())->method('append')->with(
			1,
			'activity.assigned',
			'alice',
			$this->callback(static fn (array $p): bool => ($p['assigneeUserId'] ?? '') === 'lara'),
		);

		$this->service()->create([
			'companyId' => 1,
			'title' => 'Call back',
			'type' => 'todo',
			'dueDate' => '2026-08-25',
			'assigneeUserId' => 'lara',
		], 'alice');
	}

	public function testUpdateNotifiesOnReassign(): void
	{
		$existing = new Activity();
		$existing->setId(3);
		$existing->setCompanyId(1);
		$existing->setTitle('Follow up');
		$existing->setType('todo');
		$existing->setDueDate(new \DateTime('2026-08-26'));
		$existing->setAssigneeUserId('alice');
		$existing->setUpdatedAt(new \DateTime('2026-08-24T10:00:00+00:00'));
		$existing->setCreatedBy('alice');
		$existing->setOrigin('user');

		$this->mapper->method('lockRow')->with(3)->willReturn(true);
		$this->mapper->method('findById')->with(3)->willReturn($existing);
		$this->mapper->method('update')->willReturnCallback(static fn (Activity $a): Activity => $a);

		$this->companies->method('findById')->with(1)->willReturn($this->companyInWorkspace());
		$member = new WorkspaceMember();
		$this->members->method('findMembershipOrNull')->with(9, 'lara')->willReturn($member);

		$this->notifications->expects($this->once())->method('notifyUser')->with(
			'lara',
			Constants::NOTIFY_ACTIVITY_ASSIGNED,
			'activity',
			'3',
			$this->anything(),
		);

		$out = $this->service()->update(3, [
			'assigneeUserId' => 'lara',
			'updatedAt' => '2026-08-24T10:00:00+00:00',
		], 'alice', '2026-08-24T10:00:00+00:00');
		$this->assertSame('lara', $out->getAssigneeUserId());
	}

	public function testUpdateClearAssigneeIsSharedQueue(): void
	{
		$existing = new Activity();
		$existing->setId(3);
		$existing->setCompanyId(1);
		$existing->setTitle('Follow up');
		$existing->setType('todo');
		$existing->setDueDate(new \DateTime('2026-08-26'));
		$existing->setAssigneeUserId('lara');
		$existing->setUpdatedAt(new \DateTime('2026-08-24T10:00:00+00:00'));
		$existing->setCreatedBy('alice');
		$existing->setOrigin('user');

		$this->mapper->method('lockRow')->with(3)->willReturn(true);
		$this->mapper->method('findById')->with(3)->willReturn($existing);
		$this->mapper->method('update')->willReturnCallback(static fn (Activity $a): Activity => $a);
		$this->notifications->expects($this->never())->method('notifyUser');

		$out = $this->service()->update(3, [
			'assigneeUserId' => '',
			'updatedAt' => '2026-08-24T10:00:00+00:00',
		], 'alice', '2026-08-24T10:00:00+00:00');
		$this->assertNull($out->getAssigneeUserId());
	}
}
