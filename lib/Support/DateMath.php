<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Calendar-day arithmetic for the lifecycle dates of PSD D3.
 *
 * All lifecycle dates are dates without a time-of-day, so every comparison is
 * done on UTC midnight anchors. Diffing two `DateTime` objects that still carry
 * a local time (or that straddle a DST switch) can be off by a day, and an
 * off-by-one here would fire a renewal reminder on the wrong date.
 */
final class DateMath
{
	/**
	 * Midnight anchor in UTC for the calendar day of the given moment.
	 */
	public static function toUtcMidnight(\DateTimeInterface $moment): \DateTimeImmutable
	{
		return new \DateTimeImmutable(
			$moment->format('Y-m-d') . ' 00:00:00',
			new \DateTimeZone('UTC'),
		);
	}

	/**
	 * Whole calendar days from `$from` to `$to`; positive when `$to` is later.
	 */
	public static function daysBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
	{
		$a = self::toUtcMidnight($from);
		$b = self::toUtcMidnight($to);

		return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
	}

	public static function addDays(\DateTimeInterface $date, int $days): \DateTimeImmutable
	{
		$anchor = self::toUtcMidnight($date);
		$interval = new \DateInterval('P' . abs($days) . 'D');

		return $days < 0 ? $anchor->sub($interval) : $anchor->add($interval);
	}

	public static function isSameDay(\DateTimeInterface $a, \DateTimeInterface $b): bool
	{
		return $a->format('Y-m-d') === $b->format('Y-m-d');
	}

	/**
	 * Strict `YYYY-MM-DD` parsing (§4.8): rejects impossible dates such as
	 * `2026-02-30`, which PHP would otherwise roll over to March.
	 */
	public static function parseStrictDate(string $value): ?\DateTimeImmutable
	{
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
		if ($parsed === false) {
			return null;
		}
		if ($parsed->format('Y-m-d') !== $value) {
			return null;
		}

		return $parsed;
	}

	/**
	 * ISO-8601 week key (`YYYY-Www`) used as the weekly-digest idempotency key.
	 */
	public static function isoWeekKey(\DateTimeInterface $moment): string
	{
		return $moment->format('o') . '-W' . $moment->format('W');
	}

	private function __construct()
	{
	}
}
