<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\VTodoService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ActivityServiceMobileAccessTest extends TestCase
{
	private ActivityMapper&MockObject $mapper;
	private AccessControlService&MockObject $access;
	private ActivityService $service;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(ActivityMapper::class);
		$this->access = $this->createMock(AccessControlService::class);
		$time = $this->createMock(ITimeFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->service = new ActivityService(
			$this->mapper,
			$this->access,
			$this->createMock(TimelineService::class),
			$this->createMock(VTodoService::class),
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$this->createMock(DealMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(PersonMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\CompanyMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\WorkspaceMemberMapper::class),
			$this->createMock(\OCA\CustomerCheck\Service\NotificationService::class),
		);
	}

	public function testSearchForMobileScopesNonAdminsToOwnActivities(): void
	{
		$this->access->method('isAppAdmin')->with('alice')->willReturn(false);
		$this->mapper->expects($this->once())
			->method('search')
			->with(['companyId' => 3, 'openOnly' => true, 'createdByOrAssignee' => 'alice'], 50, 10)
			->willReturn([]);

		$this->service->searchForMobile('alice', ['companyId' => 3, 'openOnly' => true], 50, 10);
	}

	public function testSearchForMobileLeavesAdminQueriesUnfiltered(): void
	{
		$this->access->method('isAppAdmin')->with('admin')->willReturn(true);
		$this->mapper->expects($this->once())
			->method('search')
			->with(['companyId' => 3, 'openOnly' => true], 25, 0)
			->willReturn([]);

		$this->service->searchForMobile('admin', ['companyId' => 3, 'openOnly' => true], 25, 0);
	}

	public function testRequireMobileAccessAllowsOwner(): void
	{
		$activity = new Activity();
		$activity->setId(9);
		$activity->setCreatedBy('alice');
		$this->mapper->method('findById')->with(9)->willReturn($activity);
		$this->access->method('isAppAdmin')->with('alice')->willReturn(false);

		$this->assertSame($activity, $this->service->requireMobileAccess(9, 'alice'));
	}

	public function testRequireMobileAccessAllowsAssignee(): void
	{
		$activity = new Activity();
		$activity->setId(9);
		$activity->setCreatedBy('alice');
		$activity->setAssigneeUserId('bob');
		$this->mapper->method('findById')->with(9)->willReturn($activity);
		$this->access->method('isAppAdmin')->with('bob')->willReturn(false);

		$this->assertSame($activity, $this->service->requireMobileAccess(9, 'bob'));
	}

	public function testRequireMobileAccessRejectsOtherUser(): void
	{
		$activity = new Activity();
		$activity->setId(9);
		$activity->setCreatedBy('alice');
		$activity->setAssigneeUserId('carol');
		$this->mapper->method('findById')->with(9)->willReturn($activity);
		$this->access->method('isAppAdmin')->with('bob')->willReturn(false);

		$this->expectException(AccessDeniedException::class);
		$this->service->requireMobileAccess(9, 'bob');
	}
}
