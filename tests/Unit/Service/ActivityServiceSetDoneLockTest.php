<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\VTodoService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/** PSD §4.8 — setDone requires updatedAt + SQL CAS (no silent overwrite). */
final class ActivityServiceSetDoneLockTest extends TestCase
{
	public function testSetDoneRejectsMissingUpdatedAt(): void
	{
		$mapper = $this->createMock(ActivityMapper::class);
		$activity = new Activity();
		$activity->setId(3);
		$activity->setUpdatedAt(new \DateTime('2026-07-27T10:00:00+00:00'));
		$mapper->method('findById')->with(3)->willReturn($activity);

		$time = $this->createMock(ITimeFactory::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$existingUsers0 = $this->createMock(\OCP\IUserManager::class);
		$existingUsers0->method('userExists')->willReturn(true);
		$svc = new ActivityService(
			$mapper,
			$this->createMock(AccessControlService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(VTodoService::class),
			$time,
			$l,
			$this->createMock(IDBConnection::class),
			$this->createMock(DealMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(PersonMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\WorkspaceMemberMapper::class),
			$this->createMock(\OCA\CustomerCheck\Service\NotificationService::class),
			$existingUsers0,
		);

		$this->expectException(ConflictException::class);
		$svc->setDone(3, true, 'admin', null);
	}

	public function testSetDoneSucceedsWithMatchingUpdatedAt(): void
	{
		$mapper = $this->createMock(ActivityMapper::class);
		$activity = new Activity();
		$activity->setId(3);
		$activity->setUpdatedAt(new \DateTime('2026-07-27T10:00:00+00:00'));

		$after = new Activity();
		$after->setId(3);
		$after->setDoneAt(new \DateTime('2026-07-27T12:00:00+00:00'));
		$after->setUpdatedAt(new \DateTime('2026-07-27T12:00:00+00:00'));

		$mapper->method('findById')->willReturnOnConsecutiveCalls($activity, $after);
		$mapper->expects(self::once())->method('updateDoneCas')->willReturn(1);
		$mapper->expects(self::never())->method('update');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-27T12:00:00+00:00'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$existingUsers1 = $this->createMock(\OCP\IUserManager::class);
		$existingUsers1->method('userExists')->willReturn(true);
		$svc = new ActivityService(
			$mapper,
			$this->createMock(AccessControlService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(VTodoService::class),
			$time,
			$l,
			$this->createMock(IDBConnection::class),
			$this->createMock(DealMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(PersonMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\WorkspaceMemberMapper::class),
			$this->createMock(\OCA\CustomerCheck\Service\NotificationService::class),
			$existingUsers1,
		);

		$out = $svc->setDone(3, true, 'admin', '2026-07-27T10:00:00+00:00');
		self::assertNotNull($out->getDoneAt());
	}

	public function testSetDoneConflictsWhenCasMisses(): void
	{
		$mapper = $this->createMock(ActivityMapper::class);
		$activity = new Activity();
		$activity->setId(3);
		$activity->setUpdatedAt(new \DateTime('2026-07-27T10:00:00+00:00'));
		$mapper->method('findById')->willReturn($activity);
		$mapper->expects(self::once())->method('updateDoneCas')->willReturn(0);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-27T12:00:00+00:00'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$existingUsers2 = $this->createMock(\OCP\IUserManager::class);
		$existingUsers2->method('userExists')->willReturn(true);
		$svc = new ActivityService(
			$mapper,
			$this->createMock(AccessControlService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(VTodoService::class),
			$time,
			$l,
			$this->createMock(IDBConnection::class),
			$this->createMock(DealMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(PersonMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\WorkspaceMemberMapper::class),
			$this->createMock(\OCA\CustomerCheck\Service\NotificationService::class),
			$existingUsers2,
		);

		$this->expectException(ConflictException::class);
		$svc->setDone(3, true, 'admin', '2026-07-27T10:00:00+00:00');
	}
}
