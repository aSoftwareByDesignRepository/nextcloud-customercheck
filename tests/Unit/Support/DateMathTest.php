<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\DateMath;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle dates are calendar days. Off-by-one here fires reminders on the wrong day.
 */
final class DateMathTest extends TestCase
{
	public function testParseStrictDateAcceptsRealIsoDays(): void
	{
		$parsed = DateMath::parseStrictDate('2026-08-18');
		$this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
		$this->assertSame('2026-08-18', $parsed->format('Y-m-d'));
		$this->assertSame('UTC', $parsed->getTimezone()->getName());
	}

	public function testParseStrictDateRejectsImpossibleAndEuropean(): void
	{
		$this->assertNull(DateMath::parseStrictDate(''));
		$this->assertNull(DateMath::parseStrictDate('2026-02-30'));
		$this->assertNull(DateMath::parseStrictDate('2026-13-01'));
		$this->assertNull(DateMath::parseStrictDate('2023-02-29'));
		$this->assertNull(DateMath::parseStrictDate('18.08.2026'));
		$this->assertNull(DateMath::parseStrictDate('2026/08/18'));
		$this->assertNull(DateMath::parseStrictDate('not-a-date'));
	}

	public function testParseStrictDateAcceptsLeapDay(): void
	{
		$parsed = DateMath::parseStrictDate('2024-02-29');
		$this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
		$this->assertSame('2024-02-29', $parsed->format('Y-m-d'));
	}

	public function testDaysBetweenIgnoresTimeOfDay(): void
	{
		$from = new \DateTimeImmutable('2026-08-18 23:00:00', new \DateTimeZone('Europe/Berlin'));
		$to = new \DateTimeImmutable('2026-08-19 01:00:00', new \DateTimeZone('Europe/Berlin'));
		$this->assertSame(1, DateMath::daysBetween($from, $to));
		$this->assertSame(0, DateMath::daysBetween($from, $from));
	}

	public function testAddDaysStaysOnUtcMidnight(): void
	{
		$anchor = DateMath::parseStrictDate('2026-03-29');
		$this->assertNotNull($anchor);
		$next = DateMath::addDays($anchor, 1);
		$this->assertSame('2026-03-30', $next->format('Y-m-d'));
		$prev = DateMath::addDays($anchor, -1);
		$this->assertSame('2026-03-28', $prev->format('Y-m-d'));
		$this->assertTrue(DateMath::isSameDay($anchor, $anchor));
		$this->assertFalse(DateMath::isSameDay($anchor, $next));
	}

	public function testIsoWeekKey(): void
	{
		$d = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));
		$this->assertSame('2026-W01', DateMath::isoWeekKey($d));
	}
}
