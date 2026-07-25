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
		foreach (['today.php', 'companies.php', 'pipeline.php', 'company-detail.php', 'import.php', 'settings.php', 'money.php', 'activities.php', 'agreements.php'] as $file) {
			$html = (string)file_get_contents($root . '/' . $file);
			$this->assertStringContainsString('crm-page-stack', $html, $file);
			$this->assertStringNotContainsString('NcAppNavigation', $html, $file);
			$this->assertStringNotContainsString('@nextcloud/vue', $html, $file);
		}
	}

	public function testCompanyDetailHasRequired360Sections(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		foreach (['crm-agreements', 'crm-persons', 'crm-activities', 'crm-timeline', 'crm-tickets'] as $id) {
			$this->assertStringContainsString('id="' . $id . '"', $html);
		}
		$this->assertStringContainsString('crm-emails-section.php', $html);
		$emails = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-emails-section.php');
		$this->assertStringContainsString('id="crm-emails"', $emails);
		$this->assertStringContainsString('crm-jump-nav.php', $html);
		$this->assertStringContainsString("jumpNavLayout = 'bar'", $html);
		$this->assertSame(1, substr_count($html, 'id="crm-tickets"'), 'Tickets section id must be unique');
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
		$this->assertMatchesRegularExpression('/<version>1\\.0\\.[1-9]\\d*<\\/version>/', $info);
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
			$this->assertDoesNotMatchRegularExpression('/\balert\s*\(/', $js, $path);
			$this->assertDoesNotMatchRegularExpression('/\bprompt\s*\(/', $js, $path);
			$this->assertDoesNotMatchRegularExpression('/\bconfirm\s*\(/', $js, $path);
		}
	}
}
