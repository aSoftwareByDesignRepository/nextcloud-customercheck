<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Strict boolean coercion for request/transport payloads.
 *
 * JSON and form transports can deliver the literal string "false" (or "0"),
 * which `!empty()` treats as truthy — the classic checkbox coercion hole
 * (dutycheck class; live-probed on workspace member canMailSend). Only
 * explicit truthy tokens enable; anything else — including unknown strings —
 * is false so an ambiguous value can never silently grant a capability or
 * record a consent flag.
 */
final class StrictBool
{
	private const TRUTHY = ['1', 'true', 'yes', 'on'];

	public static function of(mixed $v): bool
	{
		if (is_bool($v)) {
			return $v;
		}
		if (is_int($v) || is_float($v)) {
			return (float)$v !== 0.0;
		}
		if (is_string($v)) {
			return in_array(strtolower(trim($v)), self::TRUTHY, true);
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function field(array $data, string $key, bool $default = false): bool
	{
		if (!array_key_exists($key, $data)) {
			return $default;
		}

		return self::of($data[$key]);
	}
}
