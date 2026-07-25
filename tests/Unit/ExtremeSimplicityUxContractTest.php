<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Extreme-simplicity UX contracts: chips, progressive 360, demoted slug, live filters.
 */
final class ExtremeSimplicityUxContractTest extends TestCase
{
	public function testCompanyFormDemotesSlugToAdvanced(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$this->assertStringContainsString('crm-advanced', $html);
		$this->assertStringContainsString('crm-company-slug', $html);
		$this->assertLessThan(
			strpos($html, 'crm-company-slug'),
			strpos($html, 'crm-company-name'),
		);
		$this->assertStringContainsString('Just enter a name', $html);
		$this->assertStringContainsString('companyStatus', $html);
	}

	public function testCompaniesExposeOneClickQuickFilters(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/companies.php');
		$this->assertStringContainsString('crm-quick-filters', $html);
		$this->assertStringContainsString('?status=active', $html);
		$this->assertStringContainsString('?archived=1', $html);
		$this->assertStringContainsString('data-crm-live-filter', $html);
	}

	public function testCompanyDetailCollapsesEmptyLinkedApps(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-linked-bundle', $html);
		$this->assertStringContainsString('Linked apps', $html);
		$this->assertStringContainsString('Mail & log hours', $html);
		$this->assertStringContainsString('data-crm-partner-session', $html);
		foreach (['id="crm-tickets"', 'id="crm-invoices"', 'id="crm-projects"', 'id="crm-timeline"'] as $needle) {
			$this->assertStringContainsString($needle, $html);
		}
	}

	public function testMoneyAgreementsActivitiesHaveQuickFilters(): void
	{
		foreach (['money.php', 'agreements.php', 'activities.php'] as $file) {
			$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
			$this->assertStringContainsString('crm-quick-filters', $html, $file);
			$this->assertStringContainsString('data-crm-live-filter', $html, $file);
		}
	}

	public function testTodaySupportsInlineDone(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$this->assertStringContainsString('data-crm-complete-activity', $html);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/today.js');
		$this->assertStringContainsString('/activities/', $js);
		$this->assertStringContainsString('crm-list__item--done', $js);
	}

	public function testFilterLiveScriptIsRegistered(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FrontEndAssetService.php');
		$this->assertStringContainsString("addScript(Application::APP_ID, 'common/filter-live')", $src);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/filter-live.js');
		$this->assertStringContainsString('requestSubmit', $js);
		$this->assertStringContainsString('crm-filter-panel__form', $js);
	}

	public function testJumpNavRevealsCollapsedLinkedBundle(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/jump-nav.js');
		$this->assertStringContainsString('revealAncestorDetails', $js);
		$this->assertStringContainsString("tagName === 'DETAILS'", $js);
	}

	public function testTouchTargetsBumpOnMobile(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('crm-quick-filter', $css);
		$this->assertStringContainsString('@media (max-width: 767px)', $css);
		$this->assertStringContainsString('min-height: 2.75rem', $css);
	}
}
