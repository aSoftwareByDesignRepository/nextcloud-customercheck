<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\MobileAppLinks;
use PHPUnit\Framework\TestCase;

final class MobileAppLinksTest extends TestCase
{
	public function testPlayStoreUrlIsCanonicalPackageAndNotListed(): void
	{
		$links = new MobileAppLinks();
		self::assertSame(
			'https://play.google.com/store/apps/details?id=de.softwarebydesign.customercheck',
			$links->playStoreUrl(),
		);
		self::assertSame('de.softwarebydesign.customercheck', $links->playStorePackageId());
		self::assertFalse($links->playListed());
		self::assertFalse(MobileAppLinks::PLAY_LISTED);
	}

	public function testProductAndPrivacyUrlsAreHttpsOnVendorOrigin(): void
	{
		$links = new MobileAppLinks();
		$enProduct = $links->productPageUrl('en');
		$deProduct = $links->productPageUrl('de_DE');
		$enPrivacy = $links->privacyPageUrl('en');
		$dePrivacy = $links->privacyPageUrl('de');

		foreach ([$enProduct, $deProduct, $enPrivacy, $dePrivacy] as $url) {
			self::assertStringStartsWith('https://nextcloud.software-by-design.de/', $url);
		}
		self::assertStringContainsString('/en/apps/customercheck.html', $enProduct);
		self::assertStringContainsString('/de/apps/customercheck.html', $deProduct);
		self::assertStringContainsString('privacy-customercheck-mobile', $enPrivacy);
		self::assertStringContainsString('datenschutz-customercheck-mobile', $dePrivacy);
	}

	public function testGermanLocaleDetection(): void
	{
		$links = new MobileAppLinks();
		self::assertTrue($links->isGermanLocale('de'));
		self::assertTrue($links->isGermanLocale('de_DE'));
		self::assertFalse($links->isGermanLocale('en'));
		self::assertFalse($links->isGermanLocale('den'));
	}
}
