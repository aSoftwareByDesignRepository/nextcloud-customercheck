<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Company 360 glance summary must use design-system KPI tiles (crm-stat-strip),
 * never Gap jargon, and keep utils ↔ browser bootstrap in sync.
 */
final class GlanceSummaryContractTest extends TestCase
{
	public function testCompanyDetailRegistersGlanceSummaryBeforePageScript(): void
	{
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString(
			"registerFrontEndAssets('pages/company-detail', null, ['common/identity-links'], ['common/jump-nav', 'common/folder-picker', 'common/glance-summary', 'common/company360-present', 'pages/persona'])",
			$controller,
		);
	}

	public function testCompanyDetailPaintsViaSharedHelperAndKeepsRetry(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('CustomerCheckGlanceSummary', $js);
		$this->assertStringContainsString('paintGlanceSummary', $js);
		$this->assertStringContainsString('commandCenterGeneration', $js);
		$this->assertStringContainsString('crm-glance-summary', $js);
		$this->assertStringContainsString("t('Needs links')", $js);
		$this->assertStringNotContainsString("t('Gap')", $js);
		$this->assertStringContainsString("crm-callout--danger", $js);
		$this->assertStringContainsString("t('Try again')", $js);
		$this->assertStringContainsString("[t('Company'), dto.name || '—', '']", $js);
	}

	public function testBrowserBootstrapExposesBuildersAndSafeRender(): void
	{
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/glance-summary.js');
		$this->assertStringContainsString('buildGlanceSummaryTiles', $boot);
		$this->assertStringContainsString('renderGlanceSummaryTiles', $boot);
		$this->assertStringContainsString('crm-stat-strip', $boot);
		$this->assertStringContainsString('crm-stat-tile__label', $boot);
		$this->assertStringContainsString('textContent', $boot);
		$this->assertStringContainsString("t('Needs links')", $boot);
		$this->assertStringNotContainsString("t('Gap')", $boot);
		$this->assertStringContainsString('sanitizeGlanceTone', $boot);
	}

	public function testUtilsAndBootstrapShareCriticalCoverageBranch(): void
	{
		$utils = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/glance-summary.js');
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/glance-summary.js');
		$needle = "c.gap ? t('Needs links') : (c.covered ? t('Covered') : t('Coverage'))";
		$this->assertStringContainsString($needle, $utils);
		$this->assertStringContainsString($needle, $boot);
		$this->assertStringContainsString("t('%s days left'", $utils);
		$this->assertStringContainsString("t('%s days left'", $boot);
		$this->assertStringContainsString(
			"['primary', 'success', 'warning', 'danger', 'neutral'].includes(tone)",
			$utils,
		);
		$this->assertStringContainsString(
			": 'neutral';",
			$utils,
		);
	}

	public function testCssStylesGlanceDlAsStatTileGrid(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.crm-glance-dl\s*\{[^}]*grid-template-columns:/s',
			$css,
		);
		$this->assertStringContainsString('.crm-glance-dl > .crm-stat-tile', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-glance-dl\s+dt[^{]*\{[^}]*text-align:\s*start/s',
			$css,
		);
		$this->assertStringContainsString('forced-colors', $css);
	}

	public function testDensityTemplateStillBansStatStripOnSiblingKpis(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringNotContainsString('crm-stat-strip', $tpl);
		$this->assertStringContainsString('crm-glance-kpi', $tpl);
		$this->assertStringContainsString('id="crm-glance-summary"', $tpl);
	}
}
