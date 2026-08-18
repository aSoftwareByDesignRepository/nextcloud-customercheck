<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Constants;

/**
 * Canonical lead-source values for deals (Salesforce Lead Source parity).
 */
final class LeadSource
{
	/** @var array<string, string> Common webhook / import aliases → canonical slug. */
	private const ALIASES = [
		'web' => 'website',
		'form' => 'website',
		'inbound' => 'website',
		'inbound_web' => 'website',
		'trade_show' => 'event',
		'conference' => 'event',
		'cold' => 'cold_outreach',
		'cold_call' => 'cold_outreach',
		'upsell' => 'existing_customer',
		'renewal' => 'existing_customer',
	];

	public static function normalize(?string $raw, bool $defaultManual = false): ?string
	{
		$trimmed = trim((string)($raw ?? ''));
		if ($trimmed === '') {
			return $defaultManual ? 'manual' : null;
		}
		$slug = strtolower($trimmed);
		$slug = str_replace([' ', '-'], '_', $slug);
		if (in_array($slug, Constants::LEAD_SOURCES, true)) {
			return $slug;
		}
		if (isset(self::ALIASES[$slug])) {
			return self::ALIASES[$slug];
		}

		return 'other';
	}

	public static function isKnown(?string $slug): bool
	{
		return $slug !== null && $slug !== '' && in_array($slug, Constants::LEAD_SOURCES, true);
	}

	private function __construct()
	{
	}
}
