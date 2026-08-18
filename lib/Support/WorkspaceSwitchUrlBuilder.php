<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Safe workspace-switch href builder — never emits external or scheme-relative URLs.
 *
 * Entity pages fall back to list routes so a foreign object id cannot leak across books.
 */
final class WorkspaceSwitchUrlBuilder
{
	/** @var array<string, string> */
	public const ENTITY_FALLBACK = [
		'company-detail' => 'companies',
		'company-form' => 'companies',
		'agreement-form' => 'agreements',
	];

	/**
	 * @param array<string, string> $urls shell route map from PageShellTrait
	 * @param list<array{id?: int}> $workspaces
	 * @return array<int, string> workspaceId => relative href
	 */
	public static function buildMap(
		string $pageId,
		array $urls,
		array $workspaces,
		string $currentRequestUri = '',
	): array {
		$fallbackKey = self::ENTITY_FALLBACK[$pageId] ?? null;
		if ($fallbackKey !== null) {
			$base = (string)($urls[$fallbackKey] ?? $urls['today'] ?? '#');
		} else {
			$base = self::sanitizeRequestPath($currentRequestUri);
			if ($base === '') {
				$base = (string)($urls[self::urlKeyForPage($pageId)] ?? $urls['today'] ?? '#');
			}
		}

		$out = [];
		foreach ($workspaces as $ws) {
			$id = (int)($ws['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$out[$id] = self::withWorkspaceId($base, $id);
		}

		return $out;
	}

	public static function urlKeyForPage(string $pageId): string
	{
		return match ($pageId) {
			'my-accounts' => 'myAccounts',
			'mail-triage' => 'mailTriage',
			'support-us' => 'supportUs',
			'get-the-app' => 'getTheApp',
			'app-settings' => 'appSettings',
			'company-detail', 'company-form' => 'companies',
			'agreement-form' => 'agreements',
			default => $pageId,
		};
	}

	public static function sanitizeRequestPath(string $uri): string
	{
		if ($uri === '') {
			return '';
		}
		if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $uri) === 1 || str_starts_with($uri, '//')) {
			return '';
		}
		$parts = parse_url($uri);
		if (!is_array($parts)) {
			return '';
		}
		if (isset($parts['scheme']) || isset($parts['host'])) {
			return '';
		}
		$path = (string)($parts['path'] ?? '');
		if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
			return '';
		}
		$query = (string)($parts['query'] ?? '');
		return $query !== '' ? ($path . '?' . $query) : $path;
	}

	public static function withWorkspaceId(string $base, int $workspaceId): string
	{
		if ($workspaceId <= 0 || $base === '' || $base === '#') {
			return '#';
		}
		if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $base) === 1 || str_starts_with($base, '//')) {
			return '#';
		}
		$parts = parse_url($base);
		if (!is_array($parts)) {
			return '#';
		}
		if (isset($parts['scheme']) || isset($parts['host'])) {
			return '#';
		}
		$path = (string)($parts['path'] ?? '');
		if ($path === '' || str_starts_with($path, '//')) {
			return '#';
		}
		// Relative paths without leading slash (rare) — reject for predictability.
		if (!str_starts_with($path, '/')) {
			if (str_starts_with($base, '?')) {
				parse_str(ltrim($base, '?'), $query);
				$query['workspaceId'] = (string)$workspaceId;
				return '?' . http_build_query($query);
			}
			return '#';
		}
		parse_str((string)($parts['query'] ?? ''), $query);
		$query['workspaceId'] = (string)$workspaceId;
		return $path . '?' . http_build_query($query);
	}
}
