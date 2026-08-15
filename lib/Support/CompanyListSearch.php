<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCP\IL10N;

/**
 * Normalize and enrich the companies-list free-text search (granny UX + ACL-safe).
 *
 * Field matching lives in CompanyMapper; this helper only prepares:
 * - length-capped query text
 * - status codes that match localized labels (Aktiv → active)
 * - owner UIDs whose display name matches (workspace-scoped roster only)
 * - whether the query clearly asks for open callbacks / calls
 */
final class CompanyListSearch
{
	public const MAX_LEN = 100;

	public static function normalize(?string $q): string
	{
		$q = trim((string)$q);
		if ($q === '') {
			return '';
		}
		if (mb_strlen($q) > self::MAX_LEN) {
			return mb_substr($q, 0, self::MAX_LEN);
		}

		return $q;
	}

	/**
	 * @param callable(string): string $labelForCode
	 * @return list<string>
	 */
	public static function matchingStatusCodes(string $q, callable $labelForCode): array
	{
		$q = self::normalize($q);
		if ($q === '') {
			return [];
		}
		$out = [];
		foreach (['lead', 'active', 'paused', 'churned'] as $code) {
			$label = trim((string)$labelForCode($code));
			if (mb_stripos($code, $q) !== false
				|| ($label !== '' && mb_stripos($label, $q) !== false)) {
				$out[] = $code;
			}
		}

		return $out;
	}

	/**
	 * @param list<array{id?: string, displayName?: string}> $owners
	 * @return list<string>
	 */
	public static function matchingOwnerIds(string $q, array $owners): array
	{
		$q = self::normalize($q);
		if ($q === '') {
			return [];
		}
		$out = [];
		foreach ($owners as $row) {
			$id = trim((string)($row['id'] ?? ''));
			if ($id === '') {
				continue;
			}
			$display = trim((string)($row['displayName'] ?? ''));
			if (mb_stripos($id, $q) !== false
				|| ($display !== '' && mb_stripos($display, $q) !== false)) {
				$out[$id] = $id;
			}
		}

		return array_values($out);
	}

	/**
	 * True when the query clearly targets open callbacks / calls (not short prefixes).
	 */
	public static function wantsOpenCallbacks(string $q, IL10N $l10n): bool
	{
		$q = mb_strtolower(self::normalize($q));
		if ($q === '' || mb_strlen($q) < 4) {
			return false;
		}
		$needles = [
			'callback',
			'callbacks',
			'rückruf',
			'anruf',
			mb_strtolower($l10n->t('Callbacks')),
			mb_strtolower($l10n->t('Call')),
		];
		foreach ($needles as $needle) {
			$needle = trim($needle);
			if ($needle === '') {
				continue;
			}
			if ($q === $needle || str_contains($needle, $q) || str_contains($q, $needle)) {
				return true;
			}
		}

		return false;
	}
}
