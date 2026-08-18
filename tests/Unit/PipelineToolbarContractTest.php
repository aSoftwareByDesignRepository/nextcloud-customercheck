<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pipeline toolbar must sit directly above table/board with filters underneath.
 */
final class PipelineToolbarContractTest extends TestCase
{
	public function testToolbarLivesInMainContentNotPageHeader(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertStringContainsString('id="crm-pipeline-toolbar"', $html);
		$this->assertStringContainsString('id="crm-pipeline-controls"', $html);
		$this->assertStringNotContainsString('crm-page-actions-source', $html);
		$this->assertStringNotContainsString('header-actions', $html);

		$toolbarPos = (int)strpos($html, 'crm-pipeline-toolbar');
		$filterPos = (int)strpos($html, 'id="crm-pipeline-filter"');
		$tablePos = (int)strpos($html, 'id="crm-pipeline-table"');
		$this->assertLessThan($filterPos, $toolbarPos);
		$this->assertLessThan($tablePos, $filterPos);
	}

	public function testFilterToggleControlsPanelBelowToolbar(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertStringContainsString('id="crm-pipeline-filter-toggle"', $html);
		$this->assertStringContainsString('aria-controls="crm-pipeline-filter"', $html);
		$this->assertStringContainsString('crm-pipeline-filter', $html);
	}

	public function testPipelineJsUsesSharedListToolbar(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/pipeline.js');
		$this->assertStringContainsString('CustomerCheckListToolbar', $js);
		$this->assertStringContainsString('syncPipelineControlsOpen()', $js);
		$this->assertStringNotContainsString('filterToggle.addEventListener', $js);
	}

	public function testPipelineViewSwitchesInPlaceWithoutReload(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/pipeline.js');
		$this->assertStringContainsString('CustomerCheckPipelineView', $js);
		$this->assertStringContainsString("if (PipelineView && typeof PipelineView.bind === 'function' && Api && typeof Api.put === 'function')", $js);
		$this->assertStringNotContainsString('if (false && PipelineView', $js);
		$start = strpos($js, 'CustomerCheckPipelineView');
		$this->assertNotFalse($start);
		$chunk = substr($js, $start, 900);
		$this->assertStringNotContainsString('location.reload()', $chunk);
		$this->assertStringContainsString("Api.put('/settings/me', { pipelineView: view })", $chunk);

		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pipeline.php');
		$this->assertStringContainsString('aria-pressed="', $html);
		$this->assertStringContainsString('data-crm-pipeline-view="table"', $html);

		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'common/pipeline-view-core'", $ctrl);
	}

	public function testPipelineToolbarCssConnectsFilterPanel(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/page-patterns.css');
		$this->assertStringContainsString('.crm-pipeline-toolbar', $css);
		$this->assertStringContainsString('.crm-pipeline-controls--filter-open', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-pipeline-controls\s+#crm-pipeline-filter\.crm-pipeline-filter\s*\{[^}]*border-top:\s*0/s',
			$css,
		);
	}
}
