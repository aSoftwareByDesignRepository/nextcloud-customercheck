<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Case-insensitive company name key helpers for duplicate detection.
 *
 * Names are not unique in the schema (only slug is). Import flags ≥2 exact
 * name hits as needs_decision; the companies list uses these keys to surface
 * existing twins and point users at Merge into….
 */
final class CompanyDuplicateLogic
{
	public static function nameKey(string $name): string
	{
		return mb_strtolower(trim($name));
	}

	/**
	 * @param list<string> $names
	 * @return array<string, true> lowercased keys that appear more than once
	 */
	public static function duplicateKeysFromNames(array $names): array
	{
		$counts = [];
		foreach ($names as $name) {
			$key = self::nameKey((string)$name);
			if ($key === '') {
				continue;
			}
			$counts[$key] = ($counts[$key] ?? 0) + 1;
		}
		$out = [];
		foreach ($counts as $key => $count) {
			if ($count > 1) {
				$out[$key] = true;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, true> $duplicateKeys
	 */
	public static function isDuplicateName(string $name, array $duplicateKeys): bool
	{
		$key = self::nameKey($name);
		return $key !== '' && isset($duplicateKeys[$key]);
	}
}
