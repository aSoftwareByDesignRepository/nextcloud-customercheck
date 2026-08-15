<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Companies list: always-visible search + multi-field backend match + workspaceId preserved. */
final class CompaniesListSearchUxContractTest extends TestCase
{
	public function testTemplateExposesAlwaysVisibleSearchPreservingWorkspace(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/companies.php');
		$this->assertStringContainsString('class="crm-companies-search"', $html);
		$this->assertStringContainsString('id="crm-companies-q"', $html);
		$this->assertStringContainsString('Name, contact, owner, status, callback', $html);
		$this->assertStringContainsString('Finds company name, people, owner, status, open callbacks, and recent talk.', $html);
		$this->assertStringContainsString("name=\"workspaceId\"", $html);
		$this->assertStringContainsString('crmCompaniesHref', $html);
		$this->assertStringContainsString('No companies match', $html);
		$this->assertStringContainsString('aria-describedby="crm-companies-q-hint"', $html);
		// Search sits below quick filters and above More-filters / the table.
		$quickPos = strpos($html, 'crm-quick-filters');
		$searchPos = strpos($html, 'class="crm-companies-search"');
		$panelPos = strpos($html, 'id="crm-companies-filter"');
		$this->assertNotFalse($quickPos);
		$this->assertNotFalse($searchPos);
		$this->assertNotFalse($panelPos);
		$this->assertLessThan($searchPos, $quickPos);
		$this->assertLessThan($panelPos, $searchPos);
	}

	public function testMapperSearchCoversContactsOwnerStatusCallbacksAndEmail(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Db/CompanyMapper.php');
		$this->assertStringContainsString('*PREFIX*crm_person` p', $src);
		$this->assertStringContainsString('*PREFIX*crm_activity` a', $src);
		$this->assertStringContainsString('*PREFIX*crm_email_touch` e', $src);
		$this->assertStringContainsString('statusSearchCodes', $src);
		$this->assertStringContainsString('ownerUserIds', $src);
		$this->assertStringContainsString('matchOpenCallbacks', $src);
		$this->assertStringContainsString('CompanyListSearch::MAX_LEN', $src);
	}

	public function testPageControllerEnrichesSearchFilters(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$start = (int)strpos($src, 'public function companies()');
		$chunk = substr($src, $start, 2200);
		$this->assertStringContainsString('CompanyListSearch::normalize', $chunk);
		$this->assertStringContainsString('matchingStatusCodes', $chunk);
		$this->assertStringContainsString('matchingOwnerIds', $chunk);
		$this->assertStringContainsString('wantsOpenCallbacks', $chunk);
		$this->assertStringContainsString("'workspaceId' => \$wsId", $chunk);
	}

	public function testCssDefinesTouchFriendlySearchBar(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-companies-search', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-companies-search__input\s*\{[^}]*min-height:\s*48px/s',
			$css
		);
	}
}
