<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Signature logo URL policy (AS-18 / Argus SF-01).
 *
 * Allow:
 * - any https:// image URL (length-capped; no javascript/data/vbscript)
 * - http:// only for this Nextcloud's public share download paths (HTTP instances /
 *   local Docker). Third-party http logos stay blocked (tracking / mixed-content).
 *
 * Pure / DI-free — mirrored in js/common/signature-logo-core.js for the editor.
 */
final class SignatureLogoUrlPolicy
{
	private const MAX_LEN = 2048;

	/**
	 * @param list<string> $allowedHttpHosts hosts with optional :port (e.g. localhost:8081)
	 */
	public static function isAllowedSrc(string $src, array $allowedHttpHosts = []): bool
	{
		$src = trim($src);
		if ($src === '' || strlen($src) > self::MAX_LEN) {
			return false;
		}
		if (preg_match('#^\s*(javascript|data|vbscript)\s*:#i', $src) === 1) {
			return false;
		}
		if (preg_match('#^https://#i', $src) === 1) {
			return true;
		}
		if (preg_match('#^http://#i', $src) !== 1) {
			return false;
		}
		$parts = parse_url($src);
		if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
			return false;
		}
		if (!self::isNextcloudPublicSharePath((string)$parts['path'])) {
			return false;
		}
		$host = strtolower((string)$parts['host']);
		if (isset($parts['port'])) {
			$host .= ':' . (int)$parts['port'];
		}
		foreach ($allowedHttpHosts as $allowed) {
			$allowed = strtolower(trim((string)$allowed));
			if ($allowed !== '' && $allowed === $host) {
				return true;
			}
		}

		return false;
	}

	public static function isNextcloudPublicSharePath(string $path): bool
	{
		// /s/{token}, /s/{token}/download, /index.php/s/{token}[/download]
		// Tokens are alphanumeric; allow _- for forward-compat with core generators.
		return preg_match(
			'#^/(?:index\.php/)?s/[A-Za-z0-9_-]+(?:/download)?/?$#',
			$path,
		) === 1;
	}

	/**
	 * Append /download so mail clients fetch image bytes from a public share URL.
	 */
	public static function publicShareToImageSrc(string $shareUrl, array $allowedHttpHosts = []): string
	{
		$u = trim($shareUrl);
		if ($u === '') {
			return '';
		}
		$u = rtrim($u, '/');
		if (!preg_match('#/download$#i', $u)) {
			$u .= '/download';
		}
		return self::isAllowedSrc($u, $allowedHttpHosts) ? $u : '';
	}
}
