<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Sales Inbox — inline company create + assign without leaving Unassigned. */
final class SalesInboxInlineCreateContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testPageLoadsInboxCreateCompanyCoreAsset(): void
	{
		$controller = (string)file_get_contents($this->root . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'common/inbox-create-company-core'", $controller);
	}

	public function testTemplateExposesInlineCreateBlock(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/sales-inbox.php');
		$this->assertStringContainsString('id="crm-sales-inbox-create-wrap"', $html);
		$this->assertStringContainsString('id="crm-sales-inbox-create-name"', $html);
		$this->assertStringContainsString('id="crm-sales-inbox-create-assign"', $html);
		$this->assertStringContainsString('Company not listed? Create one here', $html);
		$this->assertStringContainsString('Create and assign', $html);
		$this->assertStringContainsString('autocomplete="organization"', $html);
		$this->assertStringContainsString('role="alert"', $html);
	}

	public function testSalesInboxJsWiresCreateAndAssignFlow(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('async function createCompanyAndAssign', $js);
		$this->assertStringContainsString('CustomerCheckInboxCreateCompanyCore', $js);
		$this->assertStringContainsString("Api.post('/companies'", $js);
		$this->assertStringContainsString("'/assign'", $js);
		$this->assertStringContainsString('prefillInlineCreateCompany', $js);
		$this->assertStringContainsString('onEmptyResults', $js);
		$this->assertStringContainsString('resetInlineCreateCompany', $js);
		$this->assertStringContainsString('syncInlineCreateSubmitEnabled', $js);
		$this->assertStringContainsString('crm-sales-inbox-create-assign', $js);
	}

	public function testCompanyPickerSupportsEmptyResultsCallback(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/company-picker.js');
		$this->assertStringContainsString('onEmptyResults', $js);
		$this->assertStringContainsString('opts.onEmptyResults(q)', $js);
	}

	public function testCssStylesInlineCreateTouchTargets(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.crm-sales-inbox__create-company', $css);
		$this->assertStringContainsString('#crm-sales-inbox-create-assign', $css);
		$this->assertStringContainsString('var(--crm-touch-lg)', $css);
	}
}
