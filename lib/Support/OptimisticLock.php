<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Exception\ConflictException;

/**
 * Optimistic locking on `updated_at` (PSD §4.8).
 *
 * Clients echo the `updatedAt` they loaded (body field or `If-Match-Updated-At`
 * header). A mismatch means somebody else saved in between, which is answered
 * with 409 `CONFLICT_STALE` and a reload hint rather than silently overwriting
 * the other writer's work.
 *
 * A missing precondition on a user-facing edit is treated as stale as well:
 * "I did not look at the current row" is exactly the situation the lock exists
 * for. Internal/system callers pass `$required = false`.
 */
final class OptimisticLock
{
	/**
	 * @throws ConflictException when the precondition is missing or does not match
	 */
	public static function assertFresh(
		?\DateTimeInterface $stored,
		?string $clientValue,
		bool $required = true,
	): void {
		$clientValue = $clientValue === null ? '' : trim($clientValue);

		if ($clientValue === '') {
			if (!$required) {
				return;
			}
			throw new ConflictException('missing_updated_at_precondition');
		}

		if ($stored === null) {
			// Nothing to compare against: the row predates timestamping.
			return;
		}

		$clientTime = self::parse($clientValue);
		if ($clientTime === null) {
			throw new ConflictException('unparsable_updated_at_precondition');
		}

		if ($clientTime->getTimestamp() !== $stored->getTimestamp()) {
			throw new ConflictException('stale_updated_at_precondition');
		}
	}

	/**
	 * Serialise a stored timestamp for the client to echo back.
	 */
	public static function format(?\DateTimeInterface $stored): ?string
	{
		return $stored?->format(\DateTimeInterface::ATOM);
	}

	private static function parse(string $value): ?\DateTimeImmutable
	{
		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}

	private function __construct()
	{
	}
}
