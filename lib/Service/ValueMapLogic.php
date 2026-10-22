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
		'united states of america' => 'US',
		'usa' => 'US',
		'united kingdom' => 'GB',
		'uk' => 'GB',
		'great britain' => 'GB',
		'brazil' => 'BR',
		'brasil' => 'BR',
		'brasilien' => 'BR',
		'france' => 'FR',
		'frankreich' => 'FR',
		'spain' => 'ES',
		'spanien' => 'ES',
		'italy' => 'IT',
		'italien' => 'IT',
		'netherlands' => 'NL',
		'niederlande' => 'NL',
		'holland' => 'NL',
		'poland' => 'PL',
		'polen' => 'PL',
		'portugal' => 'PT',
		'belgium' => 'BE',
		'belgien' => 'BE',
		'luxembourg' => 'LU',
		'luxemburg' => 'LU',
		'denmark' => 'DK',
		'dänemark' => 'DK',
		'sweden' => 'SE',
		'schweden' => 'SE',
		'norway' => 'NO',
		'norwegen' => 'NO',
		'finland' => 'FI',
		'finnland' => 'FI',
		'ireland' => 'IE',
		'irland' => 'IE',
		'iceland' => 'IS',
		'island' => 'IS',
		'greece' => 'GR',
		'griechenland' => 'GR',
		'czechia' => 'CZ',
		'czech republic' => 'CZ',
		'tschechien' => 'CZ',
		'slovakia' => 'SK',
		'slowakei' => 'SK',
		'slovenia' => 'SI',
		'slowenien' => 'SI',
		'croatia' => 'HR',
		'kroatien' => 'HR',
		'hungary' => 'HU',
		'ungarn' => 'HU',
		'romania' => 'RO',
		'rumänien' => 'RO',
		'bulgaria' => 'BG',
		'bulgarien' => 'BG',
		'estonia' => 'EE',
		'estland' => 'EE',
		'latvia' => 'LV',
		'lettland' => 'LV',
		'lithuania' => 'LT',
		'litauen' => 'LT',
		'malta' => 'MT',
		'cyprus' => 'CY',
		'zypern' => 'CY',
		'turkey' => 'TR',
		'türkiye' => 'TR',
		'türkei' => 'TR',
		'canada' => 'CA',
		'kanada' => 'CA',
		'mexico' => 'MX',
		'mexiko' => 'MX',
		'argentina' => 'AR',
		'argentinien' => 'AR',
		'chile' => 'CL',
		'colombia' => 'CO',
		'kolumbien' => 'CO',
		'peru' => 'PE',
		'uruguay' => 'UY',
		'paraguay' => 'PY',
		'bolivia' => 'BO',
		'bolivien' => 'BO',
		'ecuador' => 'EC',
		'venezuela' => 'VE',
		'china' => 'CN',
		'japan' => 'JP',
		'india' => 'IN',
		'indien' => 'IN',
		'south korea' => 'KR',
		'südkorea' => 'KR',
		'australia' => 'AU',
		'australien' => 'AU',
		'new zealand' => 'NZ',
		'neuseeland' => 'NZ',
		'south africa' => 'ZA',
		'südafrika' => 'ZA',
		'egypt' => 'EG',
		'ägypten' => 'EG',
		'israel' => 'IL',
		'russia' => 'RU',
		'russland' => 'RU',
		'ukraine' => 'UA',
		'belarus' => 'BY',
		'weißrussland' => 'BY',
		'moldova' => 'MD',
		'moldau' => 'MD',
		'serbia' => 'RS',
		'serbien' => 'RS',
		'bosnia' => 'BA',
		'bosnien' => 'BA',
		'bosnia and herzegovina' => 'BA',
		'bosnien und herzegowina' => 'BA',
		'kosovo' => 'XK',
		'albania' => 'AL',
		'albanien' => 'AL',
		'north macedonia' => 'MK',
		'nordmazedonien' => 'MK',
		'montenegro' => 'ME',
		'monaco' => 'MC',
		'liechtenstein' => 'LI',
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
