<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\SupportUsLinks;
use PHPUnit\Framework\TestCase;

/**
 * Security + locale contracts for SupportUsLinks.
 */
final class SupportUsLinksTest extends TestCase
{
	public function testForLocaleUsesCustomerCheckSubjects(): void
	{
		$links = new SupportUsLinks('CustomerCheck');
		$payload = $links->forLocale('en');
		self::assertSame('CustomerCheck', $payload['appDisplayName']);
		self::assertFalse($payload['hasOfficialMobileLicenses']);
		self::assertNull($payload['licensePageUrl']);
		self::assertStringContainsString('CustomerCheck: partner / care retainer', urldecode($payload['partnerMailto']));
		self::assertStringStartsWith('https://nextcloud.software-by-design.de/', $payload['supportPageUrl']);
		self::assertSame('https://github.com/sponsors/aSoftwareByDesignRepository', $payload['sponsorsUrl']);
	}

	public function testGermanLocaleSwitchesPartnerSubject(): void
	{
		$links = new SupportUsLinks('CustomerCheck');
		$payload = $links->forLocale('de');
		self::assertTrue($payload['isGerman']);
		self::assertStringContainsString('Partner / Care Retainer', urldecode($payload['partnerMailto']));
		self::assertStringContainsString('/de/support.html', $payload['supportPageUrl']);
	}

	public function testRejectsUnsafeDisplayName(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks("Evil\r\nBcc: attacker@example.com");
	}

	public function testRejectsLicenseWithoutSafeUrl(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks('CustomerCheck', true, 'javascript:alert(1)');
	}
}
