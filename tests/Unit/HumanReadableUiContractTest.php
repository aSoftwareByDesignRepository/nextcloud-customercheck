<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: templates and page JS must not force humans to type/read CRM or sibling PKs.
 */
final class HumanReadableUiContractTest extends TestCase
{
	public function testCompanyFormHasNoNumericSiblingIdInputs(): void
	{
		$form = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$this->assertStringNotContainsString('type="number"', $form);
		$this->assertStringNotContainsString('customer id', mb_strtolower($form));
		$this->assertStringContainsString('pcCustomerOptions', $form);
		$this->assertStringContainsString('tcCustomerOptions', $form);
		$this->assertStringContainsString('Search by name', $form);
	}

	public function testImportJsNeverRendersHashCompanyId(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/import.js');
		$this->assertStringNotContainsString("#' +", $js);
		$this->assertStringNotContainsString('→ #', $js);
		$this->assertStringContainsString('matchLabel', $js);
	}

	public function testAgreementsAndActivitiesShowCompanyNameColumn(): void
	{
		$agreements = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/agreements.php');
		$this->assertStringContainsString("companyName", $agreements);
		$this->assertStringNotContainsString("Open company", $agreements);

		$activities = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/activities.php');
		$this->assertStringContainsString("companyName", $activities);
		$this->assertStringNotContainsString('Open company', $activities);
	}

	public function testCompanyDetailFallbacksAreNotHashIds(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringNotContainsString("#' .", $detail);
		$this->assertStringContainsString('Unlabelled ticket', $detail);
		$this->assertStringContainsString('Unlabelled invoice', $detail);
		$this->assertStringContainsString('Unlabelled project', $detail);
	}

	public function testTodayServiceNeverFormatsHashCompanyId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/TodayService.php');
		$this->assertStringNotContainsString("'#' .", $src);
		$this->assertStringContainsString('DisplayLabelService', $src);
		$this->assertStringContainsString('companyName', $src);
	}

	public function testPipelineOrphanUsesDisplayLabels(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringNotContainsString("'#' . \$row['companyId']", $src);
		$this->assertStringContainsString('unknownCompany', $src);
		$this->assertStringContainsString('siblingLinkOptions', $src);
	}

	public function testCompanyFeedUsesHumanKindLabels(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('feedKindLabel', $js);
		$this->assertStringContainsString("t('Shared mail')", $js);
		$this->assertStringContainsString("t('Logged email')", $js);
		$this->assertStringNotContainsString('badge.textContent = item.kind || \'\'', $js);
	}
}
