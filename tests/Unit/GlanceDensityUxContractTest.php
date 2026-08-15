<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Contract: glance density is granny-readable KPI rows, not warning-on-zero tiles. */
final class GlanceDensityUxContractTest extends TestCase
{
	public function testCompanyDetailUsesGlanceKpiMarkup(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$list = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-glance-density-list.php');
		$this->assertStringContainsString('crm-glance-kpi', $list);
		$this->assertStringContainsString('crm-glance-density__title', $tpl);
		$this->assertStringContainsString('Also in linked apps', $tpl);
		$this->assertStringContainsString('GlanceDensityPresenter::tiles', $tpl);
		$this->assertStringContainsString('GlanceDensityPresenter::needsAttention', $tpl);
		$this->assertStringContainsString('crm-glance-kpi__cta', $list);
		$this->assertStringContainsString('crm-glance-density--calm', $tpl);
		$this->assertStringContainsString('crm-glance-density--attention', $tpl);
		$this->assertStringContainsString('crm-glance-density__chevron', $tpl);
		$this->assertStringContainsString('crm-glance-density-list.php', $tpl);
		$this->assertStringContainsString('$densityNeedsAttention', $tpl);
		$this->assertStringContainsString('Projects, tickets, and invoices from your linked apps.', $tpl);
		$this->assertStringNotContainsString('crm-stat-tile--warning', $tpl);
		$this->assertStringNotContainsString('crm-stat-strip', $tpl);
		// Calm branch must not coach with the attention lead (avoids “big number” when all zeros).
		$calmPos = strpos($tpl, 'crm-glance-density--calm');
		$this->assertNotFalse($calmPos);
		$calmChunk = substr($tpl, (int)$calmPos, 900);
		$this->assertStringContainsString('Projects, tickets, and invoices from your linked apps.', $calmChunk);
		$this->assertStringNotContainsString('Big number first', $calmChunk);
	}

	public function testCssDefinesTouchCtaAndCalmAttentionOffline(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-glance-kpi', $css);
		$this->assertStringContainsString('.crm-glance-kpi--calm', $css);
		$this->assertStringContainsString('.crm-glance-kpi--attention', $css);
		$this->assertStringContainsString('.crm-glance-kpi--offline', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString('.crm-glance-kpi:focus-visible', $css);
		$this->assertStringContainsString('.crm-glance-density--calm', $css);
		$this->assertStringContainsString('.crm-glance-density__chevron', $css);
		$this->assertStringContainsString('.crm-glance-kpi--calm .crm-glance-kpi__cta', $css);
		$this->assertStringContainsString('.crm-deal-notes-preview', $css);
		$this->assertStringContainsString('.crm-360-role .crm-callout__title', $css);
		// Glance density list matches crm-stat-strip / peer metric grids (auto-fit columns).
		$this->assertMatchesRegularExpression(
			'/\.crm-glance-density__list\s*\{[^}]*grid-template-columns:\s*repeat\(auto-fit/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-glance-kpi\s*\{[^}]*flex-direction:\s*column/s',
			$css
		);
		// Selected focus tab must not use double-line inset underline chrome.
		$this->assertStringNotContainsString('inset 0 -4px 0', $css);
	}

	public function testPageControllerNeverSendsZeroUnpaidToOverdueList(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('elseif ($unpaidInvoiceCount > 0)', $src);
		$this->assertStringContainsString("\$density['invoicesHref'] = '#crm-invoices'", $src);
		$this->assertStringContainsString('GlanceDensityPresenter::normalizeInvoiceHref', $src);
	}
}
