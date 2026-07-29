<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\SeatRank;
use PHPUnit\Framework\TestCase;

/**
 * Seat ranking is the licence-downgrade fairness rule: oldest assignment wins,
 * id breaks ties, newest seats fall out of limit first.
 */
final class SeatRankTest extends TestCase
{
	public function testRanksOrderByAssignedAtThenId(): void
	{
		$seats = [
			['id' => 30, 'assignedAt' => 300],
			['id' => 10, 'assignedAt' => 100],
			['id' => 21, 'assignedAt' => 200],
			['id' => 20, 'assignedAt' => 200],
		];
		$this->assertSame([10 => 1, 20 => 2, 21 => 3, 30 => 4], SeatRank::ranks($seats));
	}

	public function testRanksOfEmptyListIsEmpty(): void
	{
		$this->assertSame([], SeatRank::ranks([]));
	}

	public function testWithinLimitBoundaryExactlyAtLimit(): void
	{
		$seats = [
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 200],
			['id' => 3, 'assignedAt' => 300],
		];
		$this->assertTrue(SeatRank::isWithinLimit($seats, 1, 2));
		$this->assertTrue(SeatRank::isWithinLimit($seats, 2, 2));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 3, 2));
		$this->assertTrue(SeatRank::isWithinLimit($seats, 3, 3));
	}

	public function testZeroOrNegativeLimitDisablesEverySeat(): void
	{
		$seats = [['id' => 1, 'assignedAt' => 100]];
		$this->assertFalse(SeatRank::isWithinLimit($seats, 1, 0));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 1, -5));
	}

	public function testUnknownSeatIdIsNeverWithinLimit(): void
	{
		$seats = [['id' => 1, 'assignedAt' => 100]];
		$this->assertFalse(SeatRank::isWithinLimit($seats, 999, 10));
	}

	public function testTieOnAssignedAtBreaksByLowerIdFirst(): void
	{
		$seats = [
			['id' => 7, 'assignedAt' => 100],
			['id' => 3, 'assignedAt' => 100],
		];
		$this->assertTrue(SeatRank::isWithinLimit($seats, 3, 1));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 7, 1));
	}

	public function testDowngradeDropsNewestSeatFirst(): void
	{
		$seats = [
			['id' => 1, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 200],
			['id' => 3, 'assignedAt' => 300],
		];
		// Licence downgraded from 3 → 2 seats: newest assignment (id 3) loses.
		foreach ([1, 2] as $keptId) {
			$this->assertTrue(SeatRank::isWithinLimit($seats, $keptId, 2));
		}
		$this->assertFalse(SeatRank::isWithinLimit($seats, 3, 2));
	}
}
