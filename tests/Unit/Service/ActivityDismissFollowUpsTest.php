<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\VTodoService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
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
			$this->createMock(IDBConnection::class),
			$this->createMock(DealMapper::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(PersonMapper::class),
		);
	}

	public function testDismissRemainingFollowUpsOnlyClosesSequenceOriginsWithCas(): void
	{
		$seqQuoted = $this->activity(1, 'sequence_quoted');
		$seqWon = $this->activity(2, 'sequence_won');
		$seqContacted = $this->activity(5, 'sequence_contacted');
		$seqNegotiation = $this->activity(6, 'sequence_negotiation');
		$userCreated = $this->activity(3, 'user');
		$webhook = $this->activity(4, 'webhook');

		$this->mapper->expects($this->once())
			->method('search')
			->with(['dealId' => 42, 'openOnly' => true], 200)
			->willReturn([$seqQuoted, $seqWon, $seqContacted, $seqNegotiation, $userCreated, $webhook]);

		$casIds = [];
		$this->mapper->expects($this->exactly(4))
			->method('updateDoneCas')
			->willReturnCallback(function (int $id) use (&$casIds): int {
				$casIds[] = $id;
				return 1;
			});
		$this->mapper->expects($this->never())->method('update');

		$result = $this->service->dismissRemainingFollowUps(42, 'alice');
		$this->assertSame(4, $result['dismissed']);
		$this->assertSame([1, 2, 5, 6], $casIds);
		$this->assertContains(5, $casIds, 'sequence_contacted must be dismissed');
		$this->assertContains(6, $casIds, 'sequence_negotiation must be dismissed');
	}

	public function testDismissSkipsCasMissWithoutCounting(): void
	{
		$seq = $this->activity(9, 'sequence_quoted');
		$this->mapper->method('search')->willReturn([$seq]);
		$this->mapper->expects($this->once())->method('updateDoneCas')->willReturn(0);

		$result = $this->service->dismissRemainingFollowUps(42, 'alice');
		$this->assertSame(0, $result['dismissed']);
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
		$activity->setUpdatedAt(new \DateTime('2026-07-24 08:00:00'));
		return $activity;
	}
}
