<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Shared new-deal form module — pipeline + company 360 parity.
 */
final class NewDealFormUxContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testNewDealFormModuleRegisteredOnDealPages(): void
	{
		$pipeline = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'common/person-picker', 'common/new-deal-form'", $pipeline);

		$company = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'common/person-picker', 'common/new-deal-form', 'common/company360-focus'", $company);
	}

	public function testNewDealFormJsExportsOpenAndNextStep(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/new-deal-form.js');
		$this->assertStringContainsString('CustomerCheckNewDealForm', $js);
		$this->assertStringContainsString('openNewDealForm', $js);
		$this->assertStringContainsString('offerNextStep', $js);
		$this->assertStringContainsString('name="personId"', $js);
		$this->assertStringContainsString('name="leadSource"', $js);
		$this->assertStringContainsString('crm-form-section crm-new-deal__section', $js);
		$this->assertStringContainsString('crm-form-section__legend', $js);
		$this->assertStringContainsString("Api.post('/activities'", $js);
		$this->assertStringContainsString("'lead'", $js);
		$this->assertStringContainsString("'negotiation'", $js);
		$this->assertStringNotContainsString("'won'", $js);
		$this->assertStringContainsString("label: '25%'", $js);
		$this->assertStringContainsString("label: '50%'", $js);
		$this->assertStringContainsString("label: '75%'", $js);
		$this->assertStringContainsString("label: '100%'", $js);
		$this->assertStringContainsString("percent: 25", $js);
		$this->assertStringContainsString("percent: 100", $js);
		$this->assertStringContainsString("escapeHtml(o.label)", $js);
		$this->assertStringNotContainsString('t(o.label)', $js);
		$this->assertSame(1, substr_count($js, "const parts = ['<option value=\"\">' + escapeHtml(t('Not set')) + '</option>'];"));
		$this->assertStringContainsString("value: 'certain'", $js);
		$this->assertStringNotContainsString('This month likely', $js);
	}

	public function testPersonPickerLoadsCompanyPersons(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/person-picker.js');
		$this->assertStringContainsString("'/companies/'", $js);
		$this->assertStringContainsString('/persons', $js);
		$this->assertStringContainsString('if (p && p.isPrimary)', $js);
	}

	public function testPipelineUsesSharedNewDealForm(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/pipeline.js');
		$this->assertStringContainsString('CustomerCheckNewDealForm', $js);
		$this->assertStringContainsString("mode: 'pipeline'", $js);
		$this->assertStringNotContainsString('crm-form--new-deal\'>', $js);
	}

	public function testCompanyDetailUsesSharedNewDealForm(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString('CustomerCheckNewDealForm', $js);
		$this->assertStringContainsString("mode: 'company360'", $js);
		$this->assertStringContainsString('crm-deal-form-company-meta', $js);
		$this->assertStringContainsString('data-crm-trigger="crm-add-deal"', $js);
	}

	public function testDealFormMetaScriptsOnTemplates(): void
	{
		$pipeline = (string)file_get_contents($this->root . '/templates/pipeline.php');
		$this->assertStringContainsString('crm-deal-form-stage-options', $pipeline);
		$this->assertStringContainsString('crm-deal-form-lead-source-options', $pipeline);

		$detail = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-deal-form-company-meta', $detail);
	}

	public function testDealEntityAndMigrationExposePersonAndLeadSource(): void
	{
		$migration = (string)file_get_contents($this->root . '/lib/Migration/Version1020Date20260818193000.php');
		$this->assertStringContainsString('person_id', $migration);
		$this->assertStringContainsString('lead_source', $migration);

		$deal = (string)file_get_contents($this->root . '/lib/Db/Deal.php');
		$this->assertStringContainsString('personId', $deal);
		$this->assertStringContainsString('leadSource', $deal);

		$constants = (string)file_get_contents($this->root . '/lib/Constants.php');
		$this->assertStringContainsString('LEAD_SOURCES', $constants);
	}

	public function testNewDealStringsExistInCatalog(): void
	{
		$en = json_decode((string)file_get_contents($this->root . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
		$tr = $en['translations'] ?? [];
		foreach ([
			'Primary contact',
			'Lead source',
			'Basics',
			'Add to Today',
			'Next step',
			'Deal created! Add a next step for your Today board?',
			'Pick a contact that belongs to this company.',
			'Unknown lead source.',
			'Manual entry',
			'How sure are you this deal will close? 100%% is almost sure — it is not marked won.',
			'Pick 25%%, 50%%, 75%%, or 100%%.',
			'Not set',
		] as $key) {
			$this->assertArrayHasKey($key, $tr, 'missing l10n key: ' . $key);
		}
	}
}
