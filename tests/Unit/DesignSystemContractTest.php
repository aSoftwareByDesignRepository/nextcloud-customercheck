<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * planning/design-system checklist — CustomerCheck must stay on crm-* tokens.
 */
final class DesignSystemContractTest extends TestCase
{
	public function testTokensFileDefinesCrmSpaceScale(): void
	{
		$tokens = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/tokens.css');
		foreach (['--crm-space-1', '--crm-space-5', '--crm-space-8', '--crm-radius-sm', '--crm-radius-md', '--crm-radius-lg'] as $tok) {
			$this->assertStringContainsString($tok, $tokens);
		}
		$this->assertStringContainsString('4px', $tokens);
		$this->assertStringContainsString('24px', $tokens);
	}

	public function testCommandCenterCssUsesTokensNotRawHexFallbacks(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$m5Start = strpos($css, 'Command Center M5');
		$this->assertNotFalse($m5Start);
		$m5 = substr($css, $m5Start);
		$this->assertDoesNotMatchRegularExpression('/#ddd|#999|#f5f5f5/i', $m5);
		$this->assertStringContainsString('var(--crm-space-2)', $m5);
		$this->assertStringContainsString('var(--crm-radius-md)', $m5);
		// WCAG 2.5.5 target size is defined in CSS px; 2.75rem shrank to 41.25px under NC's 15px root font.
		$this->assertStringContainsString('min-height: 44px', $m5);
		$this->assertStringContainsString('prefers-reduced-motion', $m5);
		$this->assertStringContainsString(':focus-visible', $m5);
		$this->assertStringContainsString('crm-glance-pin', $m5);
	}

	public function testPageChromeHasSkipLinkAndLiveRegions(): void
	{
		$start = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/page-start.php');
		$this->assertStringContainsString('crm-skip-link', $start);
		$this->assertStringContainsString('crm-main-content', $start);
		$this->assertStringContainsString('crm-live-region', $start);
		$this->assertMatchesRegularExpression('/aria-live=["\']polite["\']/', $start);
	}

	public function testBadgesAreNotColourOnly(): void
	{
		$badges = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/badges.css');
		// Shared pill badges must keep text + shape (dot or left border), not colour alone.
		$this->assertTrue(
			str_contains($badges, 'border-radius') || str_contains($badges, 'pill'),
			'Badges need a shape cue beyond colour',
		);
	}

	public function testCompanyDetailUsesDesignSystemSectionStack(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-page-stack', $html);
		$this->assertStringContainsString('crm-card', $html);
		$this->assertStringContainsString('crm-btn--primary', $html);
		$this->assertStringContainsString('table--hover', $html);
		$this->assertStringContainsString('scope="col"', $html);
	}

	public function testDefinitionListsNeutraliseNextcloudCoreDtChrome(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.crm-dl(?:__row)?\s+dt[^{]*\{[^}]*text-align:\s*start/s',
			$css,
			'CRM DLs must force text-align: start against core dt end-align',
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-dl(?:__row)?\s+dt[^{]*\{[^}]*width:\s*auto/s',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-glance-dl\s+dt[^{]*\{[^}]*text-align:\s*start/s',
			$css,
		);
	}
}
