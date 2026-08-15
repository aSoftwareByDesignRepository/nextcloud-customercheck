<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Service\ValueMapLogic;

/**
 * Resolve postal address from structured CRM keys or free-text website-order blobs.
 *
 * Free-text heuristic targets common DE/AT/CH mail blocks
 * (street line + "PLZ Ort"); unknown shapes fall back safely without inventing data.
 */
final class PostalAddressParser
{
	/**
	 * @param array<string,mixed> $payload top-level import / webhook object
	 * @param array<string,mixed> $company nested company object when present
	 * @return array{addrStreet:?string,addrZip:?string,addrCity:?string,country:?string,parsed:bool}
	 */
	public static function fromImportPayload(array $payload, array $company = []): array
	{
		$street = self::firstNonEmptyString([
			$company['addrStreet'] ?? null,
			$payload['addrStreet'] ?? null,
			$company['street'] ?? null,
			$payload['street'] ?? null,
			$payload['addressStreet'] ?? null,
			// Website OrderProcessor::toCrmPayload (structured billing address).
			$payload['streetAddress'] ?? null,
			$company['streetAddress'] ?? null,
		]);
		$line2 = self::firstNonEmptyString([
			$payload['addressLine2'] ?? null,
			$company['addressLine2'] ?? null,
		]);
		if ($street !== null && $line2 !== null) {
			$street = $street . "\n" . $line2;
		} elseif ($street === null && $line2 !== null) {
			$street = $line2;
		}
		$zip = self::firstNonEmptyString([
			$company['addrZip'] ?? null,
			$payload['addrZip'] ?? null,
			$company['zip'] ?? null,
			$payload['zip'] ?? null,
			$payload['postalCode'] ?? null,
			$payload['postcode'] ?? null,
		]);
		$city = self::firstNonEmptyString([
			$company['addrCity'] ?? null,
			$payload['addrCity'] ?? null,
			$company['city'] ?? null,
			$payload['city'] ?? null,
		]);
		$countryRaw = self::firstNonEmptyString([
			$company['country'] ?? null,
			$payload['country'] ?? null,
		]);
		$country = $countryRaw !== null ? ValueMapLogic::builtinCountryCanonical($countryRaw) : null;

		$companyName = trim((string)($company['name'] ?? $company['companyName'] ?? $payload['companyName'] ?? $payload['name'] ?? ''));
		$freeText = self::firstNonEmptyString([
			$payload['address'] ?? null,
			$company['address'] ?? null,
		]);

		$parsed = false;
		// Only parse free-text when structured street/zip/city are all absent.
		// Website dual-write often sends streetAddress + postalCode + city together;
		// zip/city alone must not suppress streetAddress (that was a silent data-loss bug).
		if (($street === null && $zip === null && $city === null) && $freeText !== null) {
			$fromText = self::parseFreeText($freeText, $companyName);
			$street = $fromText['addrStreet'];
			$zip = $fromText['addrZip'];
			$city = $fromText['addrCity'];
			if ($country === null && $fromText['country'] !== null) {
				$country = $fromText['country'];
			}
			$parsed = $street !== null || $zip !== null || $city !== null;
		} else {
			$parsed = $street !== null || $zip !== null || $city !== null;
		}

		return [
			'addrStreet' => self::clip($street, 255),
			'addrZip' => self::clip($zip, 16),
			'addrCity' => self::clip($city, 128),
			'country' => $country,
			'parsed' => $parsed,
		];
	}

	/**
	 * @return array{addrStreet:?string,addrZip:?string,addrCity:?string,country:?string}
	 */
	public static function parseFreeText(string $raw, string $companyName = ''): array
	{
		$raw = str_replace(["\r\n", "\r"], "\n", $raw);
		$lines = [];
		foreach (explode("\n", $raw) as $line) {
			$trim = trim($line);
			if ($trim === '') {
				continue;
			}
			$lines[] = $trim;
		}
		if ($lines === []) {
			return ['addrStreet' => null, 'addrZip' => null, 'addrCity' => null, 'country' => null];
		}

		$companyNorm = self::normalizeComparable($companyName);
		$filtered = [];
		foreach ($lines as $line) {
			if ($companyNorm !== '' && self::normalizeComparable($line) === $companyNorm) {
				continue;
			}
			$filtered[] = $line;
		}
		if ($filtered === []) {
			$filtered = $lines;
		}

		$country = null;
		$last = $filtered[count($filtered) - 1];
		$maybeCountry = ValueMapLogic::builtinCountryCanonical($last);
		if ($maybeCountry !== null && !preg_match('/\d/', $last)) {
			$country = $maybeCountry;
			array_pop($filtered);
		}

		$zip = null;
		$city = null;
		$street = null;
		if ($filtered !== []) {
			$tail = $filtered[count($filtered) - 1];
			if (preg_match('/^(\d{4,5})\s+(.+)$/u', $tail, $m) === 1) {
				$zip = $m[1];
				$city = trim($m[2]);
				array_pop($filtered);
			} elseif (preg_match('/^(.+?)\s+(\d{4,5})$/u', $tail, $m) === 1
				&& preg_match('/\d/', $m[1]) !== 1) {
				// "Kiel 24106" rare variant
				$city = trim($m[1]);
				$zip = $m[2];
				array_pop($filtered);
			}
		}

		if ($filtered !== []) {
			// Website-order blocks often repeat the company name above the street.
			// When a PLZ/city line was found and multiple lines remain, keep the last as street.
			if ($zip !== null && count($filtered) >= 2) {
				$street = $filtered[count($filtered) - 1];
			} else {
				$street = implode(', ', $filtered);
			}
		}

		return [
			'addrStreet' => $street !== null && $street !== '' ? $street : null,
			'addrZip' => $zip,
			'addrCity' => $city !== null && $city !== '' ? $city : null,
			'country' => $country,
		];
	}

	/**
	 * @param list<mixed> $candidates
	 */
	private static function firstNonEmptyString(array $candidates): ?string
	{
		foreach ($candidates as $value) {
			if ($value === null || is_array($value) || is_object($value)) {
				continue;
			}
			$trim = trim((string)$value);
			if ($trim !== '') {
				return $trim;
			}
		}

		return null;
	}

	private static function clip(?string $value, int $max): ?string
	{
		if ($value === null) {
			return null;
		}
		$trim = trim($value);
		if ($trim === '') {
			return null;
		}

		return mb_substr($trim, 0, $max);
	}

	private static function normalizeComparable(string $value): string
	{
		$value = mb_strtolower(trim($value));
		$value = preg_replace('/\s+/u', ' ', $value) ?? $value;

		return $value;
	}
}
