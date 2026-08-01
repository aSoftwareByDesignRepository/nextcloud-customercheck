<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * WP-S1-CUC-PARITY — AZC shell parity gate for CustomerCheck hub chrome.
 */
final class AzcShellParityContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testShellFilesExist(): void
	{
		foreach ([
			'/css/common/tokens.css',
			'/css/common/app-layout.css',
			'/css/common/page-patterns.css',
			'/css/navigation.css',
			'/js/common/navigation.js',
			'/templates/common/page-start.php',
			'/templates/common/navigation.php',
			'/templates/common/page-end.php',
		] as $rel) {
			$this->assertFileExists($this->root . $rel, $rel);
		}
	}

	public function testNavigationIsSidebarWithToolsSubnav(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		foreach (['nav-menu', 'nav-item-has-children', 'tools-subnav', 'sidebar-header', 'app-brand'] as $token) {
			$this->assertStringContainsString($token, $nav, $token);
		}
		foreach (['Today', 'Companies', 'Money', 'Pipeline'] as $label) {
			$this->assertStringContainsString($label, $nav, $label);
		}
	}

	public function testPageChromeHasBreadcrumbLiveRegionsAndSkip(): void
	{
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		foreach ([
			'crm-breadcrumb',
			'crm-page-header__icon',
			'crm-scope-strip',
			'crm-live-region',
			'crm-alert-region',
			'crm-skip-link',
			'crm-shell',
		] as $token) {
			$this->assertStringContainsString($token, $start, $token);
		}
	}

	public function testValuePagesUsePageStackShell(): void
	{
		foreach ([
			'/templates/today.php',
			'/templates/companies.php',
			'/templates/money.php',
			'/templates/company-detail.php',
			'/templates/pulse.php',
			'/templates/brief.php',
			'/templates/team.php',
			'/templates/strategy.php',
			'/templates/my-accounts.php',
			'/templates/mail-triage.php',
			'/templates/prepare-meeting.php',
		] as $rel) {
			$html = (string)file_get_contents($this->root . $rel);
			$this->assertStringContainsString('page-start.php', $html, $rel);
			$this->assertStringContainsString('crm-page-stack', $html, $rel);
			$this->assertStringNotContainsString('NcAppNavigation', $html, $rel);
		}
	}

	public function testNavigationActiveIsSolidPrimaryPillLikeAzc(): void
	{
		$css = (string)file_get_contents($this->root . '/css/navigation.css');
		$this->assertStringContainsString('color-primary-element-text', $css);
		$this->assertStringContainsString('background-color: var(--color-primary-element)', $css);
		$this->assertDoesNotMatchRegularExpression(
			'/li\.active > a[^{]*\{[^}]*inset 4px 0 0/s',
			$css,
			'Active nav must be solid primary fill, not inset bar',
		);
	}

	public function testLayoutCssPinsDesktopNavWidth(): void
	{
		$layout = (string)file_get_contents($this->root . '/css/common/app-layout.css');
		$this->assertStringContainsString('#content.app-customercheck', $layout);
		// Token-backed nav width (design-system → Nextcloud theme), with 280px fallback.
		$this->assertMatchesRegularExpression(
			'/flex:\s*0 0 var\(--crm-nav-width(?:,\s*280px)?\)/',
			$layout,
		);
	}

	public function testSkipLinkIsAbsolutelyPositionedOffscreen(): void
	{
		$candidates = [
			$this->root . '/css/common/shell-chrome.css',
			$this->root . '/css/common/base.css',
			$this->root . '/css/common/accessibility.css',
			$this->root . '/css/navigation.css',
			$this->root . '/css/app.css',
		];
		$joined = '';
		foreach ($candidates as $path) {
			if (is_file($path)) {
				$joined .= (string)file_get_contents($path);
			}
		}
		$this->assertNotSame('', $joined);
		$this->assertMatchesRegularExpression(
			'/\.skip-link[^{]*\{[^}]*position:\s*absolute/s',
			$joined,
			'Skip link must be absolute so it does not become a flex column',
		);
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		$this->assertStringContainsString('skip-link', $start);
		$this->assertStringContainsString('crm-skip-link', $start);
	}

	public function testFormControlsMeetTouchTargetMinimum(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/components.css');
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString('WCAG', $css);
	}
}
