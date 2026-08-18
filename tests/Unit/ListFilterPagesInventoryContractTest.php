<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Inventory of every CustomerCheck filter surface and which toolbar pattern it uses.
 */
final class ListFilterPagesInventoryContractTest extends TestCase
{
	/** @return list<string> */
	private function templateRoot(): array
	{
		return [dirname(__DIR__, 2) . '/templates'];
	}

	public function testExpandableFilterPagesUseInlineToolbarNotHeaderRelocate(): void
	{
		$pages = [
			'pipeline.php' => ['controls' => 'crm-pipeline-controls', 'toolbar' => 'crm-pipeline-toolbar', 'filter' => 'crm-pipeline-filter'],
			'companies.php' => ['controls' => 'crm-companies-controls', 'toolbar' => 'crm-companies-toolbar', 'filter' => 'crm-companies-filter'],
			'agreements.php' => ['controls' => 'crm-agreements-controls', 'toolbar' => 'crm-agreements-toolbar', 'filter' => 'crm-agreements-filter'],
			'money.php' => ['controls' => 'crm-money-controls', 'toolbar' => 'crm-money-toolbar', 'filter' => 'crm-money-filter'],
			'activities.php' => ['controls' => 'crm-activities-controls', 'toolbar' => 'crm-activities-toolbar', 'filter' => 'crm-activities-filter'],
		];
		foreach ($pages as $file => $ids) {
			$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
			$this->assertStringNotContainsString('crm-page-actions-source', $html, $file);
			foreach ($ids as $key => $id) {
				$this->assertStringContainsString('id="' . $id . '"', $html, $file . ' missing ' . $key);
			}
			$filterPos = (int)strpos($html, 'id="' . $ids['filter'] . '"');
			$this->assertNotFalse($filterPos, $file . ': filter panel id missing');
			$resultsPos = strpos($html, 'id="crm-pipeline-table"');
			if ($resultsPos === false) {
				$resultsPos = strpos($html, 'data-crm-filter-swap="main"');
			}
			if ($resultsPos === false) {
				$resultsPos = strpos($html, 'id="crm-pipeline-board"');
			}
			$this->assertNotFalse($resultsPos, $file . ': results anchor missing');
			$this->assertLessThan($resultsPos, $filterPos, $file . ': filter must precede results');
		}
	}

	public function testSalesInboxKeepsFiltersAboveMessageList(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/sales-inbox.php');
		$this->assertStringContainsString('crm-sales-inbox__toolbar', $html);
		$this->assertStringContainsString('crm-sales-inbox__list-tools', $html);
		$this->assertStringNotContainsString('crm-page-actions-source', $html);
		$toolsPos = (int)strpos($html, 'crm-sales-inbox__list-tools');
		$listPos = (int)strpos($html, 'id="crm-sales-inbox-list"');
		$this->assertLessThan($listPos, $toolsPos);
	}

	public function testPersonaShellPagesDoNotUseHeaderFilterRelocate(): void
	{
		foreach (['review.php', 'mail-triage.php', 'conflicts.php', 'my-accounts.php'] as $file) {
			$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
			$this->assertStringNotContainsString('filter-toggle', $html, $file);
			if ($file === 'my-accounts.php') {
				$this->assertStringContainsString('crm-page-actions-source', $html);
				$this->assertStringNotContainsString('crm-accounts-filter', $html);
			} else {
				$this->assertStringNotContainsString('crm-page-actions-source', $html, $file);
			}
		}
	}

	public function testAllFilterPageScriptsUseSharedListToolbarCore(): void
	{
		foreach (['pipeline.js', 'companies.js', 'agreements.js', 'money.js', 'activities.js'] as $file) {
			$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/' . $file);
			$this->assertStringContainsString('CustomerCheckListToolbar', $js, $file);
		}
	}

	public function testListToolbarCoreCoversEveryExpandableFilterPanelId(): void
	{
		$core = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/list-toolbar-core.js');
		foreach ([
			'crm-companies-filter',
			'crm-agreements-filter',
			'crm-money-filter',
			'crm-activities-filter',
			'crm-pipeline-filter',
		] as $panelId) {
			$this->assertStringContainsString("'" . $panelId . "'", $core, $panelId);
		}
	}
}
