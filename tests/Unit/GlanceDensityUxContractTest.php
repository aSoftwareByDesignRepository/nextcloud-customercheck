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
		$this->assertStringContainsString('crm-glance-kpi', $tpl);
		$this->assertStringContainsString('crm-glance-density__title', $tpl);
		$this->assertStringContainsString('Also in linked apps', $tpl);
		$this->assertStringContainsString('GlanceDensityPresenter::tiles', $tpl);
		$this->assertStringContainsString('crm-glance-kpi__cta', $tpl);
		$this->assertStringContainsString('crm-glance-density--calm', $tpl);
		$this->assertStringContainsString('$densityNeedsAttention', $tpl);
		$this->assertStringNotContainsString('crm-stat-tile--warning', $tpl);
		$this->assertStringNotContainsString('crm-stat-strip', $tpl);
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
		$this->assertStringContainsString('.crm-deal-notes-preview', $css);
		$this->assertStringContainsString('.crm-360-role .crm-callout__title', $css);
	}

	public function testPageControllerNeverSendsZeroUnpaidToOverdueList(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('elseif ($unpaidInvoiceCount > 0)', $src);
		$this->assertStringContainsString("\$density['invoicesHref'] = '#crm-invoices'", $src);
		$this->assertStringContainsString('GlanceDensityPresenter::normalizeInvoiceHref', $src);
	}
}
