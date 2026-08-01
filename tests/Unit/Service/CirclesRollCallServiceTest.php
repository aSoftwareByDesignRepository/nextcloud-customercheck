<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Service\BriefLogic;
use OCA\CustomerCheck\Service\BriefService;
use OCA\CustomerCheck\Service\CirclesRollCallService;
use OCA\CustomerCheck\Service\WorkspaceService;
use PHPUnit\Framework\TestCase;

final class CirclesRollCallServiceTest extends TestCase
{
	public function testNeverIncludesOverdueMoneyColumn(): void
	{
		$ws = Workspace::fromRow([
			'id' => 3,
			'slug' => 'partner',
			'name' => 'Partner Success',
			'is_default' => 0,
			'archived_at' => null,
			'created_by' => null,
			'created_at' => '2026-07-01 00:00:00',
			'updated_at' => '2026-07-01 00:00:00',
		]);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('listAll')->willReturn([$ws]);

		$brief = $this->createMock(BriefService::class);
		$brief->method('build')->willReturn([
			'weekKey' => '2026-W31',
			'wipUsed' => 2,
			'wipLimit' => 5,
			'commitments' => [
				['status' => 'committed'],
				['status' => 'done'],
				['status' => 'missed'],
			],
			'lastWeek' => [
				['status' => 'done'],
				['status' => 'done'],
				['status' => 'missed'],
			],
			'cash' => ['overdueCents' => 99999],
		]);

		$svc = new CirclesRollCallService($workspaces, $brief);
		$rows = $svc->build(new \DateTimeImmutable('2026-07-28'));
		$this->assertCount(1, $rows);
		$row = $rows[0];
		$this->assertSame(3, $row['workspaceId']);
		$this->assertSame(0.67, $row['completeness']);
		$this->assertSame('medium', $row['confidence']);
		foreach (array_keys($row) as $key) {
			$this->assertDoesNotMatchRegularExpression('/overdue|cents|money|euro/i', $key);
		}
		$encoded = json_encode($row, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString('99999', $encoded);
	}

	public function testEmptyLastWeekIsUnknownConfidence(): void
	{
		$ws = Workspace::fromRow([
			'id' => 1,
			'slug' => 'default',
			'name' => 'Default',
			'is_default' => 1,
			'archived_at' => null,
			'created_by' => null,
			'created_at' => '2026-07-01 00:00:00',
			'updated_at' => '2026-07-01 00:00:00',
		]);
		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('listAll')->willReturn([$ws]);
		$brief = $this->createMock(BriefService::class);
		$brief->method('build')->willReturn([
			'wipUsed' => 0,
			'wipLimit' => 5,
			'commitments' => [],
			'lastWeek' => [],
		]);
		$svc = new CirclesRollCallService($workspaces, $brief);
		$rows = $svc->build(new \DateTimeImmutable('2026-07-28'));
		$this->assertSame('unknown', $rows[0]['confidence']);
		$this->assertSame(0.0, $rows[0]['completeness']);
		$this->assertSame(BriefLogic::isoWeekKey(new \DateTimeImmutable('2026-07-28')), $rows[0]['weekKey']);
	}
}
