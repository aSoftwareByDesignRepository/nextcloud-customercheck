<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * New deal dialog: simple company list (no mega-select, no status/%s spam).
 */
final class NewDealDialogUxContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testCompanyPickerRegisteredBeforeComponents(): void
	{
		$assets = (string)file_get_contents($this->root . '/lib/Service/FrontEndAssetService.php');
		$pickerPos = strpos($assets, "addScript(Application::APP_ID, 'common/company-picker')");
		$componentsPos = strpos($assets, "addScript(Application::APP_ID, 'common/components')");
		$this->assertNotFalse($pickerPos);
		$this->assertNotFalse($componentsPos);
		$this->assertTrue($pickerPos < $componentsPos, 'company-picker must load before components');
		$boot = (string)file_get_contents($this->root . '/js/common/company-picker.js');
		$this->assertStringContainsString('mountListPanel', $boot);
		$this->assertStringContainsString('CustomerCheckCompanyPicker', $boot);
		$this->assertStringContainsString('filterMinCount', $boot);
		$this->assertStringContainsString('showChoice', $boot);
		$this->assertStringContainsString('crm-company-choice', (string)file_get_contents($this->root . '/js/pages/pipeline.js'));
		$this->assertStringNotContainsString('Showing %s companies', $boot);
		$this->assertStringNotContainsString('Selected: %s', $boot);
	}

	public function testPipelineUsesHasCompaniesNotMegaOptionsDump(): void
	{
		$ctrl = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'hasCompanies' => \$companyCount > 0,", $ctrl);
		$this->assertStringNotContainsString("'companyOptions' =>", $ctrl);

		$tpl = (string)file_get_contents($this->root . '/templates/pipeline.php');
		$this->assertStringContainsString('crm-pipeline-meta', $tpl);
		$this->assertStringContainsString('data-has-companies', $tpl);
		$this->assertStringNotContainsString('crm-pipeline-companies', $tpl);
		$this->assertStringNotContainsString('$companyOptions', $tpl);
	}

	public function testNewDealDialogUsesListPanelNotSelectDump(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/pipeline.js');
		$this->assertStringContainsString('crm-deal-company-picker', $js);
		$this->assertStringContainsString('mountListPanel', $js);
		$this->assertStringContainsString('crm-company-list', $js);
		$this->assertStringContainsString("title: t('New deal'),\n        content: html,\n        size: 'md',", $js);
		$this->assertStringContainsString('crm-dialog--new-deal', $js);
		$this->assertStringContainsString('onClose', $js);
		$this->assertStringContainsString('pipelineHasCompanies', $js);
		$this->assertStringContainsString('crm-company-choice', $js);
		$this->assertStringContainsString('crm-form--new-deal', $js);
		$this->assertStringContainsString("t('Change company')", $js);
		$this->assertStringContainsString("t('Search by company name…')", $js);
		$this->assertStringContainsString("t('Filter companies')", $js);
		$this->assertStringContainsString('crm-advanced__body', $js);
		$this->assertStringContainsString("t('No companies yet')", $js);
		$this->assertStringContainsString("t('Create a company first, then add a deal.')", $js);
		$this->assertStringContainsString('offerCreateCompanyFirst', $js);
		$this->assertStringContainsString('companyNewUrl', $js);
		$this->assertStringContainsString("t('Create company')", $js);
		$this->assertStringContainsString('confirmDialog', $js);
		$this->assertStringContainsString('async function offerCreateCompanyFirst', $js);
		$this->assertGreaterThan(
			0,
			substr_count($js, 'await offerCreateCompanyFirst()'),
		);
		$this->assertStringNotContainsString(
			"alertDialog({ title: t('No companies yet')",
			$js,
		);
		$this->assertStringNotContainsString(
			"alertDialog({\n          title: t('No companies yet')",
			$js,
		);
		$this->assertStringNotContainsString('crm-pipeline-companies', $js);
		$this->assertStringNotContainsString('Type to narrow the list', $js);
		$this->assertStringNotContainsString('Showing %s companies', $js);
		$this->assertDoesNotMatchRegularExpression(
			'/select[^>]*name=["\']companyId["\']/',
			$js,
			'new deal must not use a mega company <select>',
		);
		$this->assertStringContainsString('crm-form crm-form--stack', $js);
		$this->assertDoesNotMatchRegularExpression(
			'/class="crm-form form/',
			$js,
			'no stray form class on dialog shell',
		);
	}

	public function testCompanyPickerCssImported(): void
	{
		$appCss = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString("common/company-picker.css", $appCss);
		$css = (string)file_get_contents($this->root . '/css/common/company-picker.css');
		$this->assertStringContainsString('crm-company-list', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString('crm-company-choice__card', $css);
		$this->assertStringContainsString('crm-form--new-deal', $css);
		$this->assertStringContainsString('crm-dialog--new-deal', $css);
		$this->assertStringContainsString('min(42rem', $css);
		$this->assertStringContainsString('calc(100vw - 1rem)', $css);
		$this->assertStringContainsString('crm-company-picker__filter-wrap[hidden]', $css);
	}

	public function testNewDealStringsExistInCatalog(): void
	{
		$en = json_decode((string)file_get_contents($this->root . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
		$tr = $en['translations'] ?? [];
		foreach ([
			'No matching companies.',
			'Change company',
			'e.g. Quote for workshop tools',
			'Search by company name…',
			'Filter companies',
			'Could not load companies',
			'Loading…',
			'No companies yet',
			'Create a company first, then add a deal.',
			'Create company',
			'Choose a company and enter a title.',
		] as $key) {
			$this->assertArrayHasKey($key, $tr, 'missing l10n key: ' . $key);
		}
	}
}
