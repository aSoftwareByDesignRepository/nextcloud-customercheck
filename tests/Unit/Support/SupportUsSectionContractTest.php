<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Static contract: Support & Us templates keep AZC-family CTA hierarchy and security attributes.
 */
final class SupportUsSectionContractTest extends TestCase
{
	private function section(): string
	{
		$path = dirname(__DIR__, 3) . '/templates/parts/support-us-section.php';
		$src = file_get_contents($path);
		self::assertNotFalse($src);
		return $src;
	}

	public function testSectionUsesCustomerCheckSupportUsLinks(): void
	{
		$src = $this->section();
		self::assertStringContainsString('OCA\\CustomerCheck\\Support\\SupportUsLinks', $src);
		self::assertStringNotContainsString('OCA\\ArbeitszeitCheck\\Support\\SupportUsLinks', $src);
		self::assertStringContainsString("getL10N('customercheck')", $src);
	}

	public function testPrimaryCtaIsPartnerMailtoNotSponsors(): void
	{
		$src = $this->section();
		$partnerPos = strpos($src, 'partnerMailto');
		$sponsorsPos = strpos($src, 'sponsorsUrl');
		self::assertNotFalse($partnerPos);
		self::assertNotFalse($sponsorsPos);
		self::assertLessThan($sponsorsPos, $partnerPos, 'Partner CTA must appear before Sponsors');
		self::assertStringContainsString('data-support-us="1"', $src);
	}

	public function testExternalLinksUseNoopenerNoreferrer(): void
	{
		$src = $this->section();
		self::assertSame(
			substr_count($src, 'target="_blank"'),
			substr_count($src, 'rel="noopener noreferrer"')
		);
		self::assertGreaterThanOrEqual(2, substr_count($src, 'rel="noopener noreferrer"'));
	}

	public function testNoHardCodedPrices(): void
	{
		$src = $this->section();
		self::assertStringNotContainsString('490', $src);
		self::assertStringNotContainsString('990', $src);
		self::assertStringNotContainsString('€', $src);
		self::assertStringNotContainsString('EUR', $src);
	}

	public function testAccessibilityHooksPresent(): void
	{
		$src = $this->section();
		self::assertStringContainsString('aria-labelledby', $src);
		self::assertStringContainsString('aria-describedby', $src);
		self::assertStringContainsString('role="group"', $src);
		self::assertStringContainsString('Support & us', $src);
		self::assertStringContainsString('(opens in a new tab)', $src);
		self::assertStringContainsString('support-us__new-tab', $src);
		self::assertStringContainsString("'Setup & training'", $src);
		self::assertStringContainsString("'Ask about setup or training'", $src);
	}

	public function testDedicatedPageMatchesAzcLayout(): void
	{
		$page = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/support-us.php');
		self::assertStringContainsString('crm-support-us-page__hero', $page);
		self::assertStringContainsString('crm-support-us-page__hero-main', $page);
		self::assertStringContainsString('data-crm-support-us-layout="offer-grid"', $page);
		self::assertStringContainsString('support-us-section.php', $page);
		self::assertStringContainsString("supportUsPresentation = 'page'", $page);
		self::assertStringContainsString('OCA\\CustomerCheck\\Support\\SupportUsLinks', $page);
		self::assertStringContainsString('CustomerCheck', $page);
		self::assertStringContainsString('vendor-logo-mark.png', $page);
		self::assertStringContainsString('crm-support-us-page__wordmark-by', $page);
	}

	public function testCssContractTargetsSupportUsPageClass(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/admin-support-us.css');
		self::assertStringContainsString('#app-content.crm-app--support-us', $css);
		self::assertStringNotContainsString('crm-app--admin-support-us', $css);
		self::assertStringContainsString('crm-support-us-page', $css);
		self::assertStringContainsString(':focus-visible', $css);
		self::assertStringContainsString(':focus:not(:focus-visible)', $css);
		self::assertStringContainsString('prefers-reduced-motion', $css);
		self::assertStringContainsString('prefers-contrast', $css);
		self::assertStringContainsString('forced-colors', $css);
		self::assertStringContainsString('max-width: none', $css);
		self::assertStringContainsString('a.crm-support-us__cta.crm-btn--primary', $css);
		self::assertStringContainsString('minmax(min(100%, 16rem)', $css);
		self::assertStringContainsString('@media (max-width: 767.98px)', $css);
		self::assertStringContainsString('min-width: 44px', $css);
		self::assertStringContainsString('crm-support-us__new-tab', $css);
	}

	public function testVendorMarkAssetExists(): void
	{
		$path = dirname(__DIR__, 3) . '/img/vendor-logo-mark.png';
		self::assertFileExists($path);
		self::assertGreaterThan(1000, filesize($path));
	}
}
