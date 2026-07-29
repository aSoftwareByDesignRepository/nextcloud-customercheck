<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;

/**
 * OOO cover rules — product §13.4.
 *
 * Ownership never changes. Overlaps rejected. Max 60 days.
 */
final class OooLogic
{
	/**
	 * @param list<array{primaryUserId: string, startsOn: string, endsOn: string, id?: int}> $existing
	 */
	public static function validate(
		string $primaryUserId,
		string $backupUserId,
		\DateTimeInterface $startsOn,
		\DateTimeInterface $endsOn,
		array $existing,
		?int $ignoreId = null,
	): ?string {
		$primaryUserId = trim($primaryUserId);
		$backupUserId = trim($backupUserId);
		if ($primaryUserId === '' || $backupUserId === '') {
			return Constants::ERR_VALIDATION;
		}
		if (hash_equals($primaryUserId, $backupUserId)) {
			return Constants::ERR_VALIDATION;
		}

		$start = $startsOn->format('Y-m-d');
		$end = $endsOn->format('Y-m-d');
		if ($end < $start) {
			return Constants::ERR_VALIDATION;
		}

		$days = (int)\DateTimeImmutable::createFromFormat('Y-m-d', $start)
			->diff(\DateTimeImmutable::createFromFormat('Y-m-d', $end))
			->format('%a');
		if ($days > Constants::OOO_MAX_DAYS) {
			return Constants::ERR_OOO_TOO_LONG;
		}

		foreach ($existing as $row) {
			if ($ignoreId !== null && isset($row['id']) && (int)$row['id'] === $ignoreId) {
				continue;
			}
			if ((string)$row['primaryUserId'] !== $primaryUserId) {
				continue;
			}
			$es = (string)$row['startsOn'];
			$ee = (string)$row['endsOn'];
			// Overlap if ranges intersect (inclusive).
			if ($start <= $ee && $end >= $es) {
				return Constants::ERR_OOO_OVERLAP;
			}
		}

		return null;
	}

	/**
	 * Active cover: today within [starts, ends] inclusive.
	 */
	public static function isActive(
		\DateTimeInterface $startsOn,
		\DateTimeInterface $endsOn,
		\DateTimeInterface $today,
	): bool {
		$t = $today->format('Y-m-d');
		return $t >= $startsOn->format('Y-m-d') && $t <= $endsOn->format('Y-m-d');
	}
}
