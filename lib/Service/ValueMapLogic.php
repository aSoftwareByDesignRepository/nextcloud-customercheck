<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Workspace value-map normalisation — GNQ §7.
 */
final class ValueMapLogic
{
	public static function normalizeRaw(string $raw): string
	{
		$raw = mb_strtolower(trim($raw));
		$raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;

		return $raw;
	}

	/** @var array<string, string> common DE/EN country aliases → ISO alpha-2 */
	private const COUNTRY_ALIASES = [
		'deutschland' => 'DE',
		'germany' => 'DE',
		'österreich' => 'AT',
		'austria' => 'AT',
		'schweiz' => 'CH',
		'switzerland' => 'CH',
		'united states' => 'US',
		'usa' => 'US',
		'united kingdom' => 'GB',
		'uk' => 'GB',
		'great britain' => 'GB',
	];

	public static function isValidIsoCountry(string $code): bool
	{
		$code = strtoupper(trim($code));
		if (!preg_match('/^[A-Z]{2}$/', $code)) {
			return false;
		}

		return true;
	}

	public static function builtinCountryCanonical(string $raw): ?string
	{
		$norm = self::normalizeRaw($raw);
		if (isset(self::COUNTRY_ALIASES[$norm])) {
			return self::COUNTRY_ALIASES[$norm];
		}
		$upper = strtoupper(trim($raw));
		if (self::isValidIsoCountry($upper)) {
			return $upper;
		}

		return null;
	}
}
