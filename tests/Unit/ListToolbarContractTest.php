<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * List toolbar: quick filters + filter toggle directly above list content.
 */
final class ListToolbarContractTest extends TestCase
{
	/** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
	private function listPages(): array
	{
		return [
			'companies' => ['companies.php', 'crm-companies-controls', 'crm-companies-toolbar', 'crm-companies-filter'],
			'agreements' => ['agreements.php', 'crm-agreements-controls', 'crm-agreements-toolbar', 'crm-agreements-filter'],
			'money' => ['money.php', 'crm-money-controls', 'crm-money-toolbar', 'crm-money-filter'],
			'activities' => ['activities.php', 'crm-activities-controls', 'crm-activities-toolbar', 'crm-activities-filter'],
		];
	}

	public function testListPagesUseToolbarAboveFilterAndResults(): void
	{
		foreach ($this->listPages() as $label => [$file, $controlsId, $toolbarId, $filterId]) {
			$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
			$this->assertStringContainsString('crm-list-controls', $html, $label);
			$this->assertStringContainsString('id="' . $controlsId . '"', $html, $label);
			$this->assertStringContainsString('id="' . $toolbarId . '"', $html, $label);
			$this->assertStringContainsString('crm-list-toolbar', $html, $label);
			$this->assertStringNotContainsString('crm-page-actions-source', $html, $label);
			$this->assertStringContainsString('data-crm-filter-swap="nav"', $html, $label);

			$toolbarPos = (int)strpos($html, 'crm-list-toolbar');
			$filterPos = (int)strpos($html, 'id="' . $filterId . '"');
			$resultsPos = (int)strpos($html, 'data-crm-filter-swap="main"');
			$this->assertLessThan($filterPos, $toolbarPos, $label . ': toolbar before filter');
			$this->assertLessThan($resultsPos, $filterPos, $label . ': filter before results');
		}
	}

	public function testFragmentModeStillRendersQuickFilterNav(): void
	{
		foreach ($this->listPages() as $label => [$file]) {
			$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
			$this->assertStringContainsString('$crmListFragment', $html, $label);
			$this->assertStringContainsString('crm-quick-filters', $html, $label);
			$this->assertMatchesRegularExpression(
				'/\<\?php endif; \?\>[\s\S]*crm-quick-filters/s',
				$html,
				$label . ': quick-filter nav must stay available for list fragments',
			);
		}
	}

	public function testListToolbarCoreRegisteredAndUsedByPages(): void
	{
		$assets = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FrontEndAssetService.php');
		$this->assertStringContainsString("addScript(Application::APP_ID, 'common/list-toolbar-core')", $assets);

		foreach (['companies.js', 'agreements.js', 'money.js', 'activities.js'] as $pageJs) {
			$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/' . $pageJs);
			$this->assertStringContainsString('CustomerCheckListToolbar', $js, $pageJs);
			$this->assertStringContainsString('ListToolbar.bind', $js, $pageJs);
		}
	}

	public function testListToolbarCssConnectsFilterPanel(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/page-patterns.css');
		$this->assertStringContainsString('.crm-list-controls', $css);
		$this->assertStringContainsString('.crm-list-toolbar', $css);
		$this->assertStringContainsString('.crm-list-controls--filter-open', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-list-controls\s>\s\.crm-filter-panel,\s*\n\s*#app-content\.crm-app\s\.crm-pipeline-controls/s',
			$css,
		);
		$this->assertStringContainsString('border-top: 0;', $css);
	}

	public function testFilterLiveSyncsPanelFromUrl(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/filter-live.js');
		$this->assertStringContainsString('syncFilterPanelsFromUrl(url.href)', $js);
	}
}
