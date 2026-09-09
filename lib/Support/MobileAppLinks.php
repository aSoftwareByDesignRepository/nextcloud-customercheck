<?php

declare(strict_types=1);

/**
 * Canonical store / product links for the in-app “Get the App” page.
 *
 * CustomerCheck Mobile exists; the Play listing is not public yet.
 * PLAY_LISTED stays false so the page never presents a live store badge.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

namespace OCA\CustomerCheck\Support;

final class MobileAppLinks
{
	public const PLAY_STORE_PACKAGE_ID = 'de.softwarebydesign.customercheck';
	public const PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=de.softwarebydesign.customercheck';
	public const PRODUCT_PAGE_PATH = '/en/apps/customercheck.html';
	public const PRODUCT_PAGE_PATH_DE = '/de/apps/customercheck.html';
	public const PRIVACY_PAGE_PATH = '/en/privacy-customercheck-mobile.html';
	public const PRIVACY_PAGE_PATH_DE = '/de/datenschutz-customercheck-mobile.html';
	public const PLAY_LISTED = false;

	public function playStoreUrl(): string
	{
		return self::PLAY_STORE_URL;
	}

	public function playStorePackageId(): string
	{
		return self::PLAY_STORE_PACKAGE_ID;
	}

	public function playListed(): bool
	{
		return self::PLAY_LISTED;
	}

	public function productPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode)
			? self::PRODUCT_PAGE_PATH_DE
			: self::PRODUCT_PAGE_PATH;
		return SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function privacyPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode)
			? self::PRIVACY_PAGE_PATH_DE
			: self::PRIVACY_PAGE_PATH;
		return SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function isGermanLocale(string $languageCode): bool
	{
		$lang = strtolower(str_replace('_', '-', trim($languageCode)));
		return $lang === 'de' || str_starts_with($lang, 'de-');
	}
}
