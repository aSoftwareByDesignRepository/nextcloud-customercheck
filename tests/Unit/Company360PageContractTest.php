<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Company 360 page — security, empty states, billing strip, zone UX contracts.
 */
final class Company360PageContractTest extends TestCase
{
	public function testCompanyDetailRegistersPresentHelpers(): void
	{
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString(
			"registerFrontEndAssets('pages/company-detail', null, ['common/identity-links'], ['common/jump-nav', 'common/folder-picker', 'common/glance-summary', 'common/company360-present', 'pages/persona'])",
			$controller,
		);
	}

	public function testIdentityIsInitializedBeforeProjectsTicketsUseIt(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$identityInit = strpos($html, "\$identity = \$_['identityLinks']");
		$projects = strpos($html, 'id="crm-projects"');
		$tickets = strpos($html, 'id="crm-tickets"');
		$this->assertNotFalse($identityInit);
		$this->assertNotFalse($projects);
		$this->assertNotFalse($tickets);
		$this->assertLessThan($projects, $identityInit);
		$this->assertLessThan($tickets, $identityInit);
		$this->assertSame(1, substr_count($html, "\$identity = \$_['identityLinks']"));
	}

	public function testPersonsTableHasBalancedMarkup(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$start = strpos($html, 'id="crm-persons"');
		$end = strpos($html, 'id="crm-deals"');
		$chunk = substr($html, (int)$start, (int)$end - (int)$start);
		$this->assertStringContainsString('crm-persons-table-wrap', $chunk);
		$this->assertDoesNotMatchRegularExpression(
			'/<\/div>\s*<\/div>\s*<\?php endif; \?>\s*<\/div>\s*<\/section>/',
			$chunk,
			'Extra closing div after persons table must stay gone',
		);
	}

	public function testSiblingEmptiesUseGrannyEmptyStates(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		foreach (['crm-projects', 'crm-tickets', 'crm-invoices', 'crm-documents'] as $id) {
			$pos = strpos($html, 'id="' . $id . '"');
			$this->assertNotFalse($pos, $id);
			$chunk = substr($html, $pos, 2200);
			$this->assertStringContainsString('crm-empty-state__title', $chunk, $id . ' needs empty title');
		}
		$this->assertStringContainsString('Open support tickets from TicketCheck when linked.', $html);
		$this->assertStringNotContainsString('id="crm-communication-more"', $html);
	}

	public function testJumpNavIncludesInvoices(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$start = strpos($html, '$jumpNavItems');
		$end = strpos($html, 'crm-jump-nav.php', (int)$start);
		$chunk = substr($html, (int)$start, (int)$end - (int)$start);
		$this->assertStringContainsString("'#crm-invoices'", $chunk);
	}

	public function testTasksRenderEscapesTitlesAndSanitizesDeepLinks(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('escapeHtml(row.title || \'\')', $js);
		$this->assertStringContainsString('safeInternalHref(row.tasksDeepLink', $js);
		$this->assertStringNotContainsString("html += '<tr><td>' + (row.title || '')", $js);
		$this->assertStringContainsString('generation = ++promisesGeneration;', $js);
		$this->assertStringContainsString('generation = ++accountPlanGeneration;', $js);
		$this->assertStringContainsString('paintBillingSummary', $js);
		$this->assertStringContainsString('crm-feed__subject', $js);
		$this->assertStringContainsString('crm-empty-state__title', $js);
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/company360-present.js');
		$this->assertStringContainsString('crm-billing-strip', $boot);
	}

	/**
	 * Account plan / promises stayed on “Loading…” forever when hydrators ran while
	 * `let accountPlanGeneration` / `promisesGeneration` were still in the TDZ
	 * (increment threw outside try/catch). Counters + boot must stay ordered.
	 */
	public function testCompany360HydratorsBootAfterGenerationCounters(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$cmdGen = strpos($js, 'let commandCenterGeneration = 0');
		$planGen = strpos($js, 'let accountPlanGeneration = 0');
		$promGen = strpos($js, 'let promisesGeneration = 0');
		$bootMarker = "loadCommandCenter();\n  loadAccountPlan();\n  loadPromises();";
		$boot = strpos($js, $bootMarker);
		$this->assertNotFalse($cmdGen, 'commandCenterGeneration init');
		$this->assertNotFalse($planGen, 'accountPlanGeneration init');
		$this->assertNotFalse($promGen, 'promisesGeneration init');
		$this->assertNotFalse($boot, 'hydrator boot trio');
		$this->assertSame(1, substr_count($js, $bootMarker), 'exactly one boot trio');
		// PHPUnit assertLessThan($expected, $actual) means $actual < $expected — prefer explicit compares.
		$this->assertTrue($planGen < $boot, 'accountPlanGeneration must init before boot');
		$this->assertTrue($promGen < $boot, 'promisesGeneration must init before boot');
		$this->assertTrue($cmdGen < $planGen, 'command + account plan counters stay co-located');
		$this->assertTrue($planGen < $promGen, 'account plan counter precedes promises counter');
		$this->assertStringContainsString('id="crm-account-plan-form" class="crm-form crm-form--stack"', $js);
		$this->assertStringContainsString("t('Could not load account plan.')", $js);
		$this->assertStringContainsString("t('Loading account plan…')", $js);
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('id="crm-account-plan-body"', $html);
		$this->assertStringContainsString("\$l->t('Loading account plan…')", $html);
	}

	public function testUtilsAndBootstrapShareSafeHrefGuard(): void
	{
		$utils = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/company360-present.js');
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/company360-present.js');
		foreach (['javascript:', 'data:', 'vbscript:'] as $needle) {
			$this->assertStringContainsString($needle, $utils);
			$this->assertStringContainsString($needle, $boot);
		}
		$this->assertStringContainsString(
			"if (lower.startsWith('javascript:') || lower.startsWith('data:') || lower.startsWith('vbscript:'))",
			$utils,
		);
		$this->assertStringContainsString("s.startsWith('//')", $utils);
		$this->assertStringContainsString("s.includes('\\\\')", $utils);
		$this->assertStringContainsString("s.indexOf('//') === 0", $boot);
		$this->assertStringContainsString("s.indexOf('\\\\') !== -1", $boot);
		$this->assertStringContainsString('buildBillingSummaryTiles', $utils);
		$this->assertStringContainsString('buildBillingSummaryTiles', $boot);
		$this->assertStringContainsString('crm-billing-strip', $boot);
		$this->assertStringContainsString('textContent', $boot);
	}

	public function testCssGivesZonesBreathingRoomAndNestedScrollMargin(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-360-zone [id]', $css);
		$this->assertStringContainsString('scroll-margin-top: 6.5rem', $css);
		$this->assertStringContainsString('.crm-feed__subject', $css);
		$this->assertStringContainsString('.crm-billing-strip', $css);
		$this->assertStringContainsString('Jump-nav owns sticky', $css);
		$this->assertDoesNotMatchRegularExpression(
			'/@media \(min-width: 1024px\) \{[^}]*#crm-glance \.crm-glance-pin \{[^}]*position:\s*sticky/s',
			$css,
			'Glance pin must not compete with jump-nav sticky',
		);
	}
}
