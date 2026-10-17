<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PSD §9 — vertical page-stack shell, wide default, no Vue primary chrome.
 */
final class CrmPageShellLayoutTest extends TestCase
{
	public function testPageStartUsesCrmShellAndStackContract(): void
	{
		$start = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/page-start.php');
		$this->assertStringContainsString('crm-app', $start);
		$this->assertStringContainsString('crm-main-content', $start);
		$this->assertStringContainsString('crm-page-header', $start);
		$this->assertStringContainsString('customercheck-app', $start);
	}

	public function testAppLayoutCssTargetsCustomerCheckWrapper(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/app-layout.css');
		$this->assertStringContainsString('#customercheck-app', $css);
		$this->assertStringContainsString('.customercheck-app', $css);
		$this->assertStringNotContainsString('#arbeitszeitcheck-app', $css);
		$this->assertStringNotContainsString('.arbeitszeitcheck-app', $css);
	}

	public function testAppLayoutSetsFlexOnWrapperOutsideMediaQuery(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/app-layout.css');
		// Non-media block must set display:flex — otherwise #content overflow:hidden clips #app-content.
		$this->assertMatchesRegularExpression(
			'/#content\.app-customercheck\s+#customercheck-app[\s\S]*?\{[\s\S]*?display:\s*flex;/m',
			$css,
			'Wrapper must be display:flex at all breakpoints'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/#content\.app-customercheck\s+#customercheck-app[\s\S]*?\{[\s\S]*?display:\s*block;/m',
			$css
		);
	}

	public function testAppCssReinforcesShellLayoutAfterImports(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression('/@import\\s+url\\(/', $css);
		$lastImportUrl = 0;
		$offset = 0;
		while (preg_match('/@import\\s+url\\(/', $css, $m, PREG_OFFSET_CAPTURE, $offset)) {
			$lastImportUrl = (int)$m[0][1];
			$offset = $lastImportUrl + 1;
		}
		$afterImports = substr($css, $lastImportUrl);
		// Marker must appear after the final @import url(...) so a 404 cannot drop flex.
		$this->assertStringContainsString('CRITICAL SHELL LAYOUT', $afterImports);
		$this->assertMatchesRegularExpression(
			'/#content\.app-customercheck\s+#customercheck-app[\s\S]*?display:\s*flex\s*!important;/m',
			$afterImports,
			'Post-import shell rules required so a failed @import cannot drop flex layout'
		);
	}

	public function testAllAppCssImportsResolveToExistingFiles(): void
	{
		$root = dirname(__DIR__, 2) . '/css';
		$appCss = (string)file_get_contents($root . '/app.css');
		preg_match_all("/@import\\s+url\\('([^']+)'\\)/", $appCss, $matches);
		$this->assertNotEmpty($matches[1], 'app.css must declare @import urls');
		foreach ($matches[1] as $rel) {
			$path = $root . '/' . $rel;
			$this->assertFileExists($path, 'Broken @import 404 breaks shell CSS cascade: ' . $rel);
			$this->assertGreaterThan(0, filesize($path), $rel . ' must not be empty');
		}
	}

	public function testListPagesUseVerticalPageStack(): void
	{
		$root = dirname(__DIR__, 2) . '/templates';
		foreach ([
			'today.php', 'companies.php', 'pipeline.php', 'company-detail.php', 'import.php', 'settings.php',
			'app-settings.php',
			'money.php', 'activities.php', 'agreements.php',
			'pulse.php', 'brief.php', 'team.php', 'strategy.php', 'my-accounts.php', 'mail-triage.php',
		] as $file) {
			$html = (string)file_get_contents($root . '/' . $file);
			$this->assertStringContainsString('crm-page-stack', $html, $file);
			$this->assertStringContainsString('page-start.php', $html, $file);
			$this->assertStringContainsString('page-end.php', $html, $file);
			$this->assertStringNotContainsString('NcAppNavigation', $html, $file);
			$this->assertStringNotContainsString('@nextcloud/vue', $html, $file);
		}
	}

	public function testCompanyDetailHasRequired360Sections(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		foreach (['crm-contracts', 'crm-persons', 'crm-activities', 'crm-timeline', 'crm-tickets', 'crm-glance', 'crm-communication', 'crm-documents', 'crm-open-tasks'] as $id) {
			$this->assertStringContainsString('id="' . $id . '"', $html);
		}
		$this->assertStringContainsString('crm-emails-section.php', $html);
		$emails = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('id="crm-emails"', $emails);
		$this->assertStringContainsString('crm-360-focus-tabs.php', $html);
		$this->assertStringContainsString('data-crm-focus-panel="money"', $html);
		// Empty vs data-bearing linked-apps branches are mutually exclusive at render time;
		// each branch keeps section anchors (#crm-projects|#crm-tickets|#crm-invoices).
		foreach (['crm-projects', 'crm-tickets', 'crm-invoices'] as $linkedId) {
			$this->assertSame(
				2,
				substr_count($html, 'id="' . $linkedId . '"'),
				$linkedId . ' must appear once in the collapsed roster and once in the peer-card branch',
			);
		}
	}

	public function testNavigationCssAlsoEnforcesShellFlex(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/navigation.css');
		$this->assertMatchesRegularExpression(
			'/#content\.app-customercheck\s+#customercheck-app[\s\S]*?display:\s*flex\s*!important;/m',
			$css,
			'navigation.css is a separate link — must also force flex to survive stale app.css caches'
		);
	}

	public function testAppVersionBumpedForCssCacheBust(): void
	{
		$info = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml');
		$this->assertSame(1, preg_match('/<version>(\d+)\.(\d+)\.(\d+)<\/version>/', $info, $m));
		$version = sprintf('%d.%d.%d', (int)$m[1], (int)$m[2], (int)$m[3]);
		$this->assertTrue(
			version_compare($version, '1.0.1', '>='),
			'App version must stay above the 1.0.0 CSS-cache-bust floor, got ' . $version,
		);
	}

	public function testLegacyVersionFileMatchesInfoXml(): void
	{
		$versionFile = dirname(__DIR__, 2) . '/appinfo/version';
		if (!file_exists($versionFile)) {
			$this->addToAssertionCount(1);
			return;
		}
		$info = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml');
		$this->assertSame(1, preg_match('/<version>([\d.]+)<\/version>/', $info, $m));
		$this->assertSame(
			$m[1],
			trim((string)file_get_contents($versionFile)),
			'appinfo/version must match info.xml — a drifting version breaks the asset '
			. 'cache-buster and lets browsers execute stale JS/CSS against new markup',
		);
	}

	public function testContentLinksUsePrimaryElementForAaContrast(): void
	{
		$base = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/base.css');
		$a11y = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/accessibility.css');
		foreach ([$base, $a11y] as $css) {
			$this->assertMatchesRegularExpression(
				'/#app-content-wrapper a:not\(\.btn\):not\(\.crm-btn\)\s*\{[^}]*color:\s*var\(--color-primary-element/s',
				$css,
				'Table/content links must use --color-primary-element (AA on dark themes)'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/#app-content-wrapper a:not\(\.btn\):not\(\.crm-btn\)\s*\{[^}]*color:\s*var\(--arbeitszeitcheck-color-primary\)/s',
				$css,
				'Must not use brand --color-primary alone (fails AA on dark #171717)'
			);
		}
	}

	public function testNoNativeAlertPromptInPageScripts(): void
	{
		$dir = dirname(__DIR__, 2) . '/js/pages';
		foreach (glob($dir . '/*.js') as $path) {
			$js = (string)file_get_contents($path);
			$base = basename((string)$path);
			// Every page script must use in-app Confirmation UI — no blocking
			// native dialogs (native_browser_dialog defect class); typed SHARE
			// / disconnect confirmations run through confirmDialog.
			if ($base === 'settings.js') {
				$this->assertStringContainsString('Type SHARE', $js);
			}
			$this->assertDoesNotMatchRegularExpression('/(?<!\.)\b(?:window\.)?(?:alert|prompt|confirm)\s*\(/', $js, $path);
		}
	}

	public function testCrmMutedUtilitySetsThemeSafeInk(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/#app-content\.crm-app\s+\.crm-muted[\s\S]*?color:\s*var\(--crm-muted/s',
			$css,
			'.crm-muted must set muted ink (WCAG AA on light shells)',
		);
		$this->assertMatchesRegularExpression(
			'/#crm-forecast-disclaimer\s*\{[\s\S]*?color:\s*var\(--crm-muted/s',
			$css,
			'Pipeline forecast disclaimer must not inherit inverse/white ink',
		);
	}
}
