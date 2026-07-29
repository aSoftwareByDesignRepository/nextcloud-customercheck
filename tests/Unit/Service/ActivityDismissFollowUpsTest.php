<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\VTodoService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ActivityDismissFollowUpsTest extends TestCase
{
	private ActivityMapper&MockObject $mapper;
	private ITimeFactory&MockObject $time;
	private ActivityService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = $this->createMock(ActivityMapper::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getDateTime')->willReturn(new \DateTime('2026-07-25 12:00:00'));
		$this->service = new ActivityService(
			$this->mapper,
			$this->createMock(AccessControlService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(VTodoService::class),
			$this->time,
			$this->createMock(IL10N::class),
		);
	}

	public function testDismissRemainingFollowUpsOnlyClosesSequenceOrigins(): void
	{
		$seqQuoted = $this->activity(1, 'sequence_quoted');
		$seqWon = $this->activity(2, 'sequence_won');
		$userCreated = $this->activity(3, 'user');
		$webhook = $this->activity(4, 'webhook');

		$this->mapper->expects($this->once())
			->method('search')
			->with(['dealId' => 42, 'openOnly' => true], 200)
			->willReturn([$seqQuoted, $seqWon, $userCreated, $webhook]);

		$updatedIds = [];
		$this->mapper->expects($this->exactly(2))
			->method('update')
			->willReturnCallback(function (Activity $activity) use (&$updatedIds) {
				$updatedIds[] = (int)$activity->getId();
				$this->assertNotNull($activity->getDoneAt());
				return $activity;
			});

		$result = $this->service->dismissRemainingFollowUps(42, 'alice');
		$this->assertSame(2, $result['dismissed']);
		$this->assertSame([1, 2], $updatedIds);
	}

	public function testDismissWithInvalidDealIdReturnsZero(): void
	{
		$this->mapper->expects($this->never())->method('search');
		$this->assertSame(['dismissed' => 0], $this->service->dismissRemainingFollowUps(0, 'alice'));
	}

	private function activity(int $id, string $origin): Activity
	{
		$activity = new Activity();
		$activity->setId($id);
		$activity->setCompanyId(1);
		$activity->setDealId(42);
		$activity->setType('call');
		$activity->setTitle('Follow up');
		$activity->setOrigin($origin);
		return $activity;
	}
}
