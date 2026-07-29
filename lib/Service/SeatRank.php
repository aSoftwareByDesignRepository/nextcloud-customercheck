<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Deterministic seat-rank rule (same contract as ProjectCheck / AZC seats).
 *
 * A seat is within limit iff its rank ordered by (assigned_at ASC, id ASC)
 * is ≤ mobileSeats of the currently stored key. Downgrading a key therefore
 * predictably disables the *newest* seats first. Pure and unit-tested.
 */
class SeatRank
{
	/**
	 * @param list<array{id: int, assignedAt: int}> $seats unordered seat rows
	 * @return array<int, int> seat id → 1-based rank
	 */
	public static function ranks(array $seats): array
	{
		usort($seats, static function (array $a, array $b): int {
			if ($a['assignedAt'] !== $b['assignedAt']) {
				return $a['assignedAt'] <=> $b['assignedAt'];
			}

			return $a['id'] <=> $b['id'];
		});
		$ranks = [];
		$rank = 1;
		foreach ($seats as $seat) {
			$ranks[$seat['id']] = $rank;
			$rank++;
		}

		return $ranks;
	}

	/**
	 * @param list<array{id: int, assignedAt: int}> $seats
	 */
	public static function isWithinLimit(array $seats, int $seatId, int $limit): bool
	{
		if ($limit <= 0) {
			return false;
		}
		$ranks = self::ranks($seats);
		if (!isset($ranks[$seatId])) {
			return false;
		}

		return $ranks[$seatId] <= $limit;
	}
}
