<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\DealFreshnessLogic;
use PHPUnit\Framework\TestCase;

final class DealFreshnessLogicTest extends TestCase
{
	public function testIsRottingUsesInclusiveThreshold(): void
	{
		$today = new \DateTimeImmutable('2026-08-24');
		$changed = new \DateTimeImmutable('2026-08-10'); // 14 days idle
		self::assertTrue(DealFreshnessLogic::isRotting('quoted', $changed, 14, $today));
		self::assertFalse(DealFreshnessLogic::isRotting('quoted', new \DateTimeImmutable('2026-08-11'), 14, $today));
		self::assertFalse(DealFreshnessLogic::isRotting('won', $changed, 14, $today));
		self::assertFalse(DealFreshnessLogic::isRotting('quoted', null, 14, $today));
	}

	public function testAgeIdleAndStageDays(): void
	{
		$today = new \DateTimeImmutable('2026-08-24');
		self::assertSame(10, DealFreshnessLogic::ageDays(new \DateTimeImmutable('2026-08-14'), $today));
		self::assertSame(3, DealFreshnessLogic::idleDays(new \DateTimeImmutable('2026-08-21'), $today));
		self::assertSame(14, DealFreshnessLogic::stageIdleDays(new \DateTimeImmutable('2026-08-10'), $today));
		self::assertNull(DealFreshnessLogic::ageDays(null, $today));
	}

	public function testNudgeIndexAndSnapKey(): void
	{
		self::assertSame(0, DealFreshnessLogic::nudgeIndex(13, 14));
		self::assertSame(1, DealFreshnessLogic::nudgeIndex(14, 14));
		self::assertSame(2, DealFreshnessLogic::nudgeIndex(28, 14));
		$changed = new \DateTimeImmutable('2026-08-01');
		self::assertSame('2026-08-01#1', DealFreshnessLogic::snapKey($changed, 1));
		self::assertSame('2026-08-01#2', DealFreshnessLogic::snapKey($changed, 2));
	}

	public function testSnapshotMarksRottingConsistently(): void
	{
		$today = new \DateTimeImmutable('2026-08-24T12:00:00+00:00');
		$snap = DealFreshnessLogic::snapshot(
			new \DateTimeImmutable('2026-07-01'),
			new \DateTimeImmutable('2026-08-20'),
			new \DateTimeImmutable('2026-08-10'),
			'negotiation',
			14,
			$today,
		);
		self::assertTrue($snap['stale']);
		self::assertTrue($snap['rotting']);
		self::assertSame(1, $snap['nudgeIndex']);
		self::assertSame(14, $snap['stageIdleDays']);
		self::assertSame(4, $snap['idleDays']);
	}
}
