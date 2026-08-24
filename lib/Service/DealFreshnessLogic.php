<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Support\DateMath;

/**
 * Pure deal freshness / rotting rules — age, idle, stage-stuck, nudge snaps.
 *
 * No I/O. Unit + mutation tested so off-by-one cannot drift from ChaseLogic.
 */
final class DealFreshnessLogic
{
	/**
	 * Open deal is rotting when the stage has been idle ≥ staleDays.
	 * Terminal stages never rot.
	 */
	public static function isRotting(
		string $stage,
		?\DateTimeInterface $stageChangedAt,
		int $staleDays,
		\DateTimeInterface $today,
	): bool {
		if (in_array($stage, Constants::DEAL_TERMINAL_STAGES, true)) {
			return false;
		}
		if ($stageChangedAt === null) {
			return false;
		}
		$threshold = max(1, $staleDays);

		return self::daysIdle($stageChangedAt, $today) >= $threshold;
	}

	public static function ageDays(?\DateTimeInterface $createdAt, \DateTimeInterface $today): ?int
	{
		if ($createdAt === null) {
			return null;
		}

		return max(0, self::daysIdle($createdAt, $today));
	}

	public static function idleDays(?\DateTimeInterface $updatedAt, \DateTimeInterface $today): ?int
	{
		if ($updatedAt === null) {
			return null;
		}

		return max(0, self::daysIdle($updatedAt, $today));
	}

	public static function stageIdleDays(?\DateTimeInterface $stageChangedAt, \DateTimeInterface $today): ?int
	{
		if ($stageChangedAt === null) {
			return null;
		}

		return max(0, self::daysIdle($stageChangedAt, $today));
	}

	/**
	 * 1 = first rotting threshold, 2 = second full period, …
	 * 0 = not yet rotting.
	 */
	public static function nudgeIndex(?int $stageIdleDays, int $staleDays): int
	{
		if ($stageIdleDays === null || $stageIdleDays < 1) {
			return 0;
		}
		$threshold = max(1, $staleDays);

		return intdiv($stageIdleDays, $threshold);
	}

	/**
	 * Exactly-once ledger key for one nudge wave in a stage stretch.
	 * Format: `{stageChangedYmd}#{nudgeIndex}` (index ≥ 1).
	 */
	public static function snapKey(\DateTimeInterface $stageChangedAt, int $nudgeIndex): string
	{
		$n = max(1, $nudgeIndex);

		return $stageChangedAt->format('Y-m-d') . '#' . $n;
	}

	/**
	 * @return array{
	 *   createdAt: ?string,
	 *   ageDays: ?int,
	 *   idleDays: ?int,
	 *   stageIdleDays: ?int,
	 *   stale: bool,
	 *   rotting: bool,
	 *   nudgeIndex: int
	 * }
	 */
	public static function snapshot(
		?\DateTimeInterface $createdAt,
		?\DateTimeInterface $updatedAt,
		?\DateTimeInterface $stageChangedAt,
		string $stage,
		int $staleDays,
		\DateTimeInterface $today,
	): array {
		$stageIdle = self::stageIdleDays($stageChangedAt, $today);
		$rotting = self::isRotting($stage, $stageChangedAt, $staleDays, $today);

		return [
			'createdAt' => $createdAt?->format(\DateTimeInterface::ATOM),
			'ageDays' => self::ageDays($createdAt, $today),
			'idleDays' => self::idleDays($updatedAt, $today),
			'stageIdleDays' => $stageIdle,
			'stale' => $rotting,
			'rotting' => $rotting,
			'nudgeIndex' => self::nudgeIndex($stageIdle, $staleDays),
		];
	}

	private static function daysIdle(\DateTimeInterface $from, \DateTimeInterface $to): int
	{
		return DateMath::daysBetween($from, $to);
	}
}
