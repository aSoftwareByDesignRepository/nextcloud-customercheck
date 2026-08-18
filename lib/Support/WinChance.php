<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Constants;

/**
 * Win-chance percents (25/50/75/100) stored as forecast buckets.
 *
 * 100% is "almost sure" — never won. Coaching treats commit + certain as high confidence.
 */
final class WinChance
{
	public const INVALID = '__invalid__';

	/** @var array<string, string> */
	private const PERCENT_TO_BUCKET = [
		'25' => 'pipeline',
		'50' => 'upside',
		'75' => 'commit',
		'100' => 'certain',
	];

	/**
	 * @return string|null Canonical bucket, or null when unset. {@see INVALID} when unknown.
	 */
	public static function normalize(mixed $raw): ?string
	{
		if ($raw === null) {
			return null;
		}
		if (is_bool($raw)) {
			return self::INVALID;
		}
		if (is_int($raw) || is_float($raw)) {
			if (is_float($raw) && $raw != floor($raw)) {
				return self::INVALID;
			}
			$key = (string)(int)$raw;
		} else {
			$key = strtolower(trim((string)$raw));
			$key = rtrim($key, " \t%");
		}
		if ($key === '' || $key === 'none' || $key === 'not set' || $key === '0') {
			return null;
		}
		if (isset(self::PERCENT_TO_BUCKET[$key])) {
			return self::PERCENT_TO_BUCKET[$key];
		}
		if (in_array($key, Constants::FORECAST_BUCKETS, true)) {
			return $key;
		}

		return self::INVALID;
	}

	public static function isKnown(?string $bucket): bool
	{
		return $bucket !== null && $bucket !== '' && in_array($bucket, Constants::FORECAST_BUCKETS, true);
	}

	public static function isHighConfidence(?string $bucket): bool
	{
		return $bucket === 'commit' || $bucket === 'certain';
	}

	/** Locale-invariant display label — never pass bare "%" through IL10N::t (vsprintf). */
	public static function percentLabel(int $percent): string
	{
		if ($percent < 0 || $percent > 100) {
			return (string)$percent;
		}

		return $percent . '%';
	}

	private function __construct()
	{
	}
}
