<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus: Company 360 Pipedrive-style focus tabs — security + sync contracts.
 */
final class Company360FocusContractTest extends TestCase
{
	public function testFocusTabsReplaceJumpNavAndZones(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('data-crm-360-focus="1"', $html);
		$this->assertStringContainsString('crm-360-focus-tabs.php', $html);
		$this->assertStringNotContainsString('crm-jump-nav.php', $html);
		$this->assertStringNotContainsString('data-crm-zone="now"', $html);
		$this->assertStringNotContainsString('1 · Talk & people', $html);
		$this->assertStringNotContainsString('How to use this page', $html);
		foreach (['today', 'deals', 'money', 'more'] as $tab) {
			$this->assertStringContainsString('data-crm-focus-panel="' . $tab . '"', $html);
		}
		$tabsPartial = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/crm-360-focus-tabs.php');
		$this->assertStringContainsString('data-crm-focus-tab="<?php p($tabId); ?>"', $tabsPartial);
		$this->assertStringContainsString('$tabId === \'today\'', $tabsPartial);
		$this->assertStringContainsString('aria-orientation="horizontal"', $tabsPartial);
	}

	public function testDealsAndMorePanelsStartHidden(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertMatchesRegularExpression(
			'/id="crm-360-panel-deals"[^>]*hidden/',
			$html,
		);
		$this->assertMatchesRegularExpression(
			'/id="crm-360-panel-money"[^>]*hidden/',
			$html,
		);
		$this->assertMatchesRegularExpression(
			'/id="crm-360-panel-more"[^>]*hidden/',
			$html,
		);
	}

	public function testFocusScriptIsClassicIifeWithoutDynamicImport(): void
	{
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'common/company360-focus'", $controller);
		$this->assertStringNotContainsString("'common/jump-nav'", $controller);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/company360-focus.js');
		$this->assertStringContainsString('data-crm-360-focus="1"', $js);
		$this->assertStringContainsString('aria-selected', $js);
		$this->assertStringContainsString('function revealAllPanels(root)', $js);
		$this->assertStringContainsString('resolveTabForElement', $js);
		$this->assertStringContainsString('jumpGeneration', $js);
		$this->assertStringContainsString("document.addEventListener(", $js);
		// Classic Util::addScript cannot load ESM — dynamic import would leave panels stuck hidden.
		$this->assertStringNotContainsString("import('./company360-focus-core.js')", $js);
		$this->assertStringNotContainsString('import(', $js);
		$core = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/company360-focus-core.js');
		$this->assertStringContainsString("'crm-billing': 'money'", $core);
		$this->assertStringContainsString('resolveTabForElement', $core);
		$this->assertStringContainsString('isJumpCurrent', $core);
	}

	public function testBootstrapHelpersStayInSyncWithCore(): void
	{
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/company360-focus.js');
		$core = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/company360-focus-core.js');
		foreach ([
			"'crm-communication': 'today'",
			"'crm-deals': 'deals'",
			"'crm-billing': 'money'",
			"'crm-timeline': 'more'",
			"'crm-identity-links': 'more'",
		] as $needle) {
			$this->assertStringContainsString($needle, $boot, 'bootstrap map missing ' . $needle);
			$this->assertStringContainsString($needle, $core, 'core map missing ' . $needle);
		}
		$this->assertStringContainsString("if (id.indexOf('/') !== -1 || id.indexOf('%') !== -1 || id.indexOf(':') !== -1) {", $boot);
		$this->assertStringContainsString("if (id.indexOf('/') !== -1 || id.indexOf('%') !== -1 || id.indexOf(':') !== -1) {", $core);
		$this->assertStringContainsString('isFocusDeepLinkSectionId', $boot);
		$this->assertStringContainsString('isFocusDeepLinkSectionId', $core);
		$this->assertStringContainsString('return tabForSectionId(sectionId) !== null;', $boot);
		$this->assertStringContainsString('return tabForSectionId(sectionId) !== null;', $core);
		$this->assertStringContainsString('/^[A-Za-z][A-Za-z0-9_-]*$/', $boot);
		$this->assertStringContainsString('/^[A-Za-z][A-Za-z0-9_-]*$/', $core);
		$this->assertStringNotContainsString('if (false && (id.indexOf', $boot);
		$this->assertSame(2, substr_count($boot, 'if (companyId && /^\\d+$/.test(companyId)) {'));
		$this->assertStringContainsString('data-crm-focus-ready', $boot);
		$this->assertStringContainsString('data-crm-focus-degraded', $boot);
		$this->assertSame(2, substr_count($boot, "root.setAttribute('data-crm-focus-degraded', '1');"));
		$this->assertStringContainsString('Number.isFinite(rootScrollTop)', $boot);
		$this->assertStringContainsString('Number.isFinite(rootScrollTop)', $core);
		$this->assertStringContainsString('orderedPresentTabIds', $boot);
		$this->assertStringContainsString('orderedPresentTabIds', $core);
		$this->assertStringContainsString('hashBelongsToOtherTab', $boot);
		$this->assertStringContainsString('hashBelongsToOtherTab', $core);
		$this->assertStringContainsString('ignoreHashChange', $boot);
		$this->assertStringContainsString('clearStaleHashForTab', $boot);
		$this->assertStringContainsString('withQuietHash', $boot);
		$this->assertStringContainsString('quietHashSeq', $boot);
		$this->assertStringContainsString('Promise.resolve().then', $boot);
		$this->assertStringNotContainsString('window.setTimeout(function () {\n          ignoreHashChange = false;', $boot);
		$this->assertStringContainsString('orderedPresentTabIds(tabs.keys(), ALLOWED_FOCUS_TABS)', $boot);
		$this->assertStringContainsString('hashBelongsToOtherTab(hash, tabId, section)', $boot);
		$this->assertSame(1, substr_count($boot, 'clearStaleHashForTab(tabId);'));
		$this->assertSame(1, substr_count($boot, 'clearStaleHashForTab(nextId);'));
		$this->assertStringContainsString('function bootFocusTabs()', $boot);
		$this->assertStringContainsString('bootFocusTabs();', $boot);
		$this->assertGreaterThanOrEqual(2, substr_count($boot, 'return false;'));
		$this->assertStringContainsString("let ignoreHashChange = false;", $boot);

		$bootMap = $this->extractSectionTabMap($boot);
		$coreMap = $this->extractSectionTabMap($core);
		$this->assertNotSame([], $bootMap, 'bootstrap SECTION_TAB_MAP must parse');
		$this->assertSame($coreMap, $bootMap, 'SECTION_TAB_MAP must be structurally identical in bootstrap and core');
		$this->assertArrayHasKey('crm-tickets', $bootMap);
		$this->assertSame('money', $bootMap['crm-tickets']);
	}

	public function testSectionTabMapTargetsExistInCompanyDetailTree(): void
	{
		$root = dirname(__DIR__, 2);
		$boot = (string)file_get_contents($root . '/js/common/company360-focus.js');
		$map = $this->extractSectionTabMap($boot);
		$this->assertNotSame([], $map);
		$corpus = $this->loadTemplateCorpus($root . '/templates/company-detail.php', $root . '/templates');
		foreach (array_keys($map) as $sectionId) {
			$this->assertMatchesRegularExpression(
				'/\\bid=["\']' . preg_quote((string)$sectionId, '/') . '["\']/',
				$corpus,
				'SECTION_TAB_MAP id missing from company-detail template tree: ' . $sectionId,
			);
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function extractSectionTabMap(string $src): array
	{
		if (!preg_match('/SECTION_TAB_MAP\s*=\s*Object\.freeze\(\{([\s\S]*?)\}\)/', $src, $m)) {
			return [];
		}
		preg_match_all("/'([^']+)'\s*:\s*'([^']+)'/", $m[1], $pairs, PREG_SET_ORDER);
		$out = [];
		foreach ($pairs as $row) {
			$out[$row[1]] = $row[2];
		}
		ksort($out);
		return $out;
	}

	/**
	 * Flatten company-detail.php plus one level of common/*.php includes.
	 */
	private function loadTemplateCorpus(string $entry, string $templatesRoot): string
	{
		$html = (string)file_get_contents($entry);
		if (preg_match_all("/include\\s+__DIR__\\s*\\.\\s*'(\\/common\\/[^']+\\.php)'/", $html, $m)) {
			foreach ($m[1] as $rel) {
				$path = $templatesRoot . $rel;
				if (is_file($path)) {
					$html .= "\n" . (string)file_get_contents($path);
				}
			}
		}
		return $html;
	}

	public function testFocusCssIsStickyAndAccessible(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-360-focus__chrome', $css);
		$this->assertStringContainsString('.crm-360-focus__tab[aria-selected="true"]', $css);
		$this->assertStringContainsString('.crm-360-focus__tab:focus-visible', $css);
		$this->assertStringContainsString('.crm-360-focus__panel [id]', $css);
		$this->assertStringContainsString('min-height: 48px', $css);
		$this->assertStringContainsString('forced-colors', $css);
		$this->assertStringContainsString('prefers-reduced-motion', $css);
		$this->assertStringContainsString('prefers-reduced-transparency', $css);
		$this->assertStringContainsString('border-width: 3px', $css);
		// Selected tab uses thicker border (WCAG 1.4.1 non-color cue) — not double-line inset underline chrome.
		$this->assertStringNotContainsString('inset 0 -4px 0', $css);
		$this->assertStringContainsString('position: sticky', $css);
		$this->assertStringContainsString('@media (scripting: none)', $css);
		$this->assertStringContainsString('.crm-360-focus__panel[hidden] {', $css);
	}
}
