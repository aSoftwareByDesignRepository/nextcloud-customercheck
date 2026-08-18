<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Theme / WCAG 2.1 AA layout contracts: NC variables, high-contrast, OAuth HTML.
 */
final class ThemeAdaptabilityContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testRadiusLgMapsToNextcloudBorderRadiusLarge(): void
	{
		$tokens = (string)file_get_contents($this->root . '/css/common/tokens.css');
		$this->assertStringContainsString('--crm-radius-sm: 6px', $tokens);
		$this->assertStringContainsString('--crm-radius-md: 12px', $tokens);
		$this->assertStringContainsString('--crm-radius-lg: var(--border-radius-large, 16px)', $tokens);
		$this->assertStringContainsString('--crm-radius-pill: var(--border-radius-pill, 999px)', $tokens);
		$this->assertStringContainsString('--crm-scrim: color-mix(in srgb, var(--color-main-text)', $tokens);
		$this->assertDoesNotMatchRegularExpression('/--crm-scrim:\s*#[0-9a-fA-F]/', $tokens);
	}

	public function testUtilityRadiusMdFollowsDesignSystemTwelvePx(): void
	{
		$utilities = (string)file_get_contents($this->root . '/css/common/utilities.css');
		$this->assertStringContainsString('--radius-md: var(--crm-radius-md, 12px)', $utilities);
		$this->assertStringNotContainsString('--radius-md: var(--crm-radius-sm', $utilities);
	}

	public function testHighContrastCoversDialogsAndSettingsPanels(): void
	{
		$a11y = (string)file_get_contents($this->root . '/css/common/accessibility.css');
		$this->assertStringContainsString('dialog.crm-native-dialog .crm-btn', $a11y);
		$this->assertStringContainsString('dialog.crm-native-dialog :focus-visible', $a11y);
		$this->assertStringContainsString('.crm-nc-admin-settings .crm-btn', $a11y);
		$this->assertStringContainsString('.crm-nc-settings-panel :focus-visible', $a11y);
		$this->assertStringContainsString('@media (prefers-contrast: more)', $a11y);
		$this->assertStringNotContainsString('@media (prefers-contrast: high)', $a11y);
	}

	public function testModalAndLoadingScrimsUseToken(): void
	{
		$components = (string)file_get_contents($this->root . '/css/common/components.css');
		$this->assertMatchesRegularExpression(
			'/\.modal-backdrop\s*\{[^}]*background-color:\s*var\(--crm-scrim/s',
			$components,
		);
		$this->assertMatchesRegularExpression(
			'/\.loading-overlay\s*\{[^}]*background-color:\s*var\(--crm-scrim/s',
			$components,
		);
		$colors = (string)file_get_contents($this->root . '/css/common/colors.css');
		$this->assertStringContainsString(
			'--arbeitszeitcheck-color-background-overlay: var(--crm-scrim',
			$colors,
		);
	}

	public function testKeyboardFocusOutlineIsNotStrippedOnInputs(): void
	{
		$components = (string)file_get_contents($this->root . '/css/common/components.css');
		$this->assertStringContainsString('.form-input:focus:not(:focus-visible)', $components);
		$focusBlock = $this->extractRule($components, ".form-input:focus,\n.form-group input:focus");
		$this->assertStringNotContainsString('outline: none', $focusBlock);
	}

	public function testStackedTablesDoNotForceSixHundredPxMinWidth(): void
	{
		$components = (string)file_get_contents($this->root . '/css/common/components.css');
		$this->assertStringContainsString(
			'.table-responsive .table:not(.crm-table--responsive)',
			$components,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.table-responsive \.table,\s*\n\s*\.table-container \.table,\s*\n\s*\.table-responsive \.grid-table/s',
			$components,
		);
	}

	public function testNativeOAuthCallbackHtmlUsesSystemColorsNotHex(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('color-scheme:light dark', $ctrl);
		$this->assertStringContainsString('color:CanvasText', $ctrl);
		$this->assertStringContainsString('background:Canvas', $ctrl);
		$this->assertStringContainsString('getLanguageCode', $ctrl);
		$this->assertStringNotContainsString('<html lang="en">', $ctrl);
		$this->assertDoesNotMatchRegularExpression('/color\s*:\s*#111\b/', $ctrl);
		$this->assertStringNotContainsString("color:#111", $ctrl);
	}

	private function extractRule(string $css, string $startNeedle): string
	{
		$pos = strpos($css, $startNeedle);
		$this->assertNotFalse($pos, 'rule start missing: ' . $startNeedle);
		$open = strpos($css, '{', $pos);
		$this->assertNotFalse($open);
		$close = strpos($css, '}', $open);
		$this->assertNotFalse($close);

		return substr($css, $pos, $close - $pos + 1);
	}
}
