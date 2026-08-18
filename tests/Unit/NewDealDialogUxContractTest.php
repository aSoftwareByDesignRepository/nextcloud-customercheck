<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * New deal dialog: simple company list (no mega-select, no status/%s spam).
 * Form markup lives in js/common/new-deal-form.js; pipeline.js delegates to it.
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
		$form = (string)file_get_contents($this->root . '/js/common/new-deal-form.js');
		$this->assertStringContainsString('crm-company-choice', $form);
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
		$pipeline = (string)file_get_contents($this->root . '/js/pages/pipeline.js');
		$this->assertStringContainsString('CustomerCheckNewDealForm', $pipeline);
		$this->assertStringContainsString("mode: 'pipeline'", $pipeline);
		$this->assertStringContainsString('pipelineHasCompanies', $pipeline);
		$this->assertStringContainsString('offerCreateCompanyFirst', $pipeline);
		$this->assertStringContainsString('companyNewUrl', $pipeline);
		$this->assertStringContainsString("t('Create company')", $pipeline);
		$this->assertStringContainsString('confirmDialog', $pipeline);
		$this->assertStringContainsString('async function offerCreateCompanyFirst', $pipeline);
		$this->assertGreaterThan(
			0,
			substr_count($pipeline, 'await offerCreateCompanyFirst()'),
		);
		$this->assertStringNotContainsString(
			"alertDialog({ title: t('No companies yet')",
			$pipeline,
		);
		$this->assertStringNotContainsString('crm-pipeline-companies', $pipeline);
		$this->assertStringNotContainsString('Type to narrow the list', $pipeline);
		$this->assertStringNotContainsString('Showing %s companies', $pipeline);
		$this->assertStringNotContainsString('crm-form--new-deal', $pipeline);

		$form = (string)file_get_contents($this->root . '/js/common/new-deal-form.js');
		$this->assertStringContainsString('crm-deal-company-picker', $form);
		$this->assertStringContainsString('mountListPanel', $form);
		$this->assertStringContainsString('crm-company-list', $form);
		$this->assertStringContainsString("title: t('New deal')", $form);
		$this->assertStringContainsString('content: html', $form);
		$this->assertStringContainsString("size: 'md'", $form);
		$this->assertStringContainsString('crm-dialog--new-deal', $form);
		$this->assertStringContainsString('onClose', $form);
		$this->assertStringContainsString('crm-company-choice', $form);
		$this->assertStringContainsString('crm-form--new-deal', $form);
		$this->assertStringContainsString("t('Change company')", $form);
		$this->assertStringContainsString("t('Search by company name…')", $form);
		$this->assertStringContainsString("t('Filter companies')", $form);
		$this->assertStringContainsString('crm-advanced__body', $form);
		$this->assertStringContainsString('name="notes"', $form);
		$this->assertStringContainsString('name="expectedClose"', $form);
		$this->assertStringContainsString('name="stage"', $form);
		$this->assertStringContainsString('name="ownerUserId"', $form);
		$this->assertStringContainsString('name="forecastBucket"', $form);
		$this->assertStringContainsString('name="personId"', $form);
		$this->assertStringContainsString('name="leadSource"', $form);
		$this->assertStringContainsString('crm-deal-form-stage-options', (string)file_get_contents($this->root . '/templates/pipeline.php'));
		$this->assertStringContainsString('OPEN_STAGES', $form);
		$this->assertStringContainsString("t('A title is enough. Add notes, value, or a close date if you have them.')", $form);
		$this->assertStringContainsString("t('No companies yet')", $pipeline);
		$this->assertStringContainsString("t('Create a company first, then add a deal.')", $pipeline);
		$this->assertDoesNotMatchRegularExpression(
			'/select[^>]*name=["\']companyId["\']/',
			$form,
			'new deal must not use a mega company <select>',
		);
		$this->assertStringContainsString('crm-form crm-form--stack', $form);
		$this->assertDoesNotMatchRegularExpression(
			'/class="crm-form form/',
			$form,
			'no stray form class on dialog shell',
		);
	}

	public function testCompanyPickerCssImported(): void
	{
		$appCss = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString("common/company-picker.css", $appCss);
		$this->assertStringContainsString("common/new-deal-form.css", $appCss);
		$css = (string)file_get_contents($this->root . '/css/common/company-picker.css');
		$this->assertStringContainsString('crm-company-list', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString('crm-company-choice__card', $css);
		$dealCss = (string)file_get_contents($this->root . '/css/common/new-deal-form.css');
		$this->assertStringContainsString('crm-form--new-deal', $dealCss);
		$this->assertStringContainsString('crm-form-section', $dealCss);
		$this->assertStringContainsString('min-height: var(--crm-touch, 44px)', $dealCss);
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
			'A title is enough. Add notes, value, or a close date if you have them.',
			'Leave empty to assign the deal to yourself.',
			'Not set',
			'Primary contact',
			'Lead source',
			'Deal created! Add a next step for your Today board?',
			'Add to Today',
		] as $key) {
			$this->assertArrayHasKey($key, $tr, 'missing l10n key: ' . $key);
		}
	}
}
