<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Filter panels must match ArbeitszeitCheck chrome:
 * *-filter-panel__head + bare h2 + *-filter-panel__intro + *-filter-panel__body.
 * Never reuse crm-card__header / crm-card__title / crm-card__lead inside a filter panel.
 */
final class FilterPanelLayoutContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	/** @return list<string> */
	private function filterTemplates(): array
	{
		return [
			'templates/companies.php',
			'templates/agreements.php',
			'templates/activities.php',
			'templates/money.php',
			'templates/pipeline.php',
		];
	}

	public function testEveryFilterPanelUsesHeadIntroBodyChrome(): void
	{
		foreach ($this->filterTemplates() as $rel) {
			$html = (string)file_get_contents($this->root . '/' . $rel);
			$this->assertStringContainsString('crm-card crm-filter-panel', $html, $rel . ' missing panel root');
			$this->assertMatchesRegularExpression(
				'/<section[^>]*\bcrm-filter-panel\b[^>]*>[\s\S]*?<\/section>/',
				$html,
				$rel . ' missing filter panel section'
			);
			preg_match_all('/<section[^>]*\bcrm-filter-panel\b[^>]*>[\s\S]*?<\/section>/', $html, $blocks);
			$this->assertNotEmpty($blocks[0], $rel . ' missing filter panel section');
			foreach ($blocks[0] as $i => $block) {
				$label = $rel . ' panel#' . $i;
				$this->assertStringContainsString('crm-filter-panel__head', $block, $label . ' missing __head');
				$this->assertStringContainsString('crm-filter-panel__intro', $block, $label . ' missing __intro');
				$this->assertStringContainsString('crm-filter-panel__body', $block, $label . ' missing __body');
				$this->assertStringContainsString('crm-filter-panel__form', $block, $label . ' missing __form');
				$this->assertStringContainsString('crm-filter-grid', $block, $label . ' missing filter grid');
				$this->assertStringNotContainsString('crm-card__header', $block, $label . ' must not use crm-card__header');
				$this->assertStringNotContainsString('crm-card__title', $block, $label . ' must not use crm-card__title');
				$this->assertStringNotContainsString('crm-card__lead', $block, $label . ' must not use crm-card__lead');
				$this->assertStringNotContainsString('crm-card__body', $block, $label . ' must not use crm-card__body');
			}
		}
	}

	public function testPagePatternsDefineFilterHeadChrome(): void
	{
		$css = (string)file_get_contents($this->root . '/css/common/page-patterns.css');
		$this->assertStringContainsString('.crm-filter-panel__head', $css);
		$this->assertStringContainsString('.crm-filter-panel__intro', $css);
		$this->assertStringContainsString('.crm-filter-panel__body', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-filter-panel\s*\{[^}]*padding:\s*0/s',
			$css,
			'filter panel root must zero card padding so __head/__body own spacing'
		);
		$this->assertStringContainsString('border-left: 4px solid', $css);
	}

	/**
	 * Bachus: structured cards + Filter soft chrome at #content specificity
	 * so a future AZ content-surface padding rule cannot recreate the inset trap.
	 */
	public function testStructuredCardsShareFilterSoftChromeAtContentSpecificity(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/#content\.app-customercheck\s+#app-content\s+\.crm-card:has\(\.crm-card__header\)[\s\S]*?padding:\s*0/s',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/#content\.app-customercheck\s+#app-content\s+\.crm-card__header[\s\S]*?background:\s*var\(--crm-bg-soft/s',
			$css
		);
		$this->assertStringContainsString('Bachus / AZ parity (defense)', $css);
	}
}
