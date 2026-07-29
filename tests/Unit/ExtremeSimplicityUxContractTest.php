<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Extreme-simplicity UX contracts: chips, progressive 360, demoted slug, live filters.
 */
final class ExtremeSimplicityUxContractTest extends TestCase
{
	public function testCompanyFormDemotesSlugToAdvanced(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-form.php');
		$this->assertStringContainsString('crm-advanced', $html);
		$this->assertStringContainsString('crm-company-slug', $html);
		$this->assertLessThan(
			strpos($html, 'crm-company-slug'),
			strpos($html, 'crm-company-name'),
		);
		$this->assertStringContainsString('Just enter a name', $html);
		$this->assertStringContainsString('companyStatus', $html);
	}

	public function testCompaniesExposeOneClickQuickFilters(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/companies.php');
		$this->assertStringContainsString('crm-quick-filters', $html);
		$this->assertStringContainsString('?status=active', $html);
		$this->assertStringContainsString('?archived=1', $html);
		$this->assertStringContainsString('data-crm-live-filter', $html);
	}

	public function testCompanyDetailCollapsesEmptyLinkedApps(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('crm-linked-bundle', $html);
		$this->assertStringContainsString('Linked apps', $html);
		$this->assertStringContainsString('Mail & log hours', $html);
		$this->assertStringContainsString('data-crm-partner-session', $html);
		foreach (['id="crm-tickets"', 'id="crm-invoices"', 'id="crm-projects"', 'id="crm-timeline"'] as $needle) {
			$this->assertStringContainsString($needle, $html);
		}
		$this->assertStringContainsString("linkToRoute('invoicecheck.page.show'", $html);
		$this->assertStringContainsString('icInvoiceId', $html);
	}

	public function testMoneyAgreementsActivitiesHaveQuickFilters(): void
	{
		foreach (['money.php', 'agreements.php', 'activities.php'] as $file) {
			$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/' . $file);
			$this->assertStringContainsString('crm-quick-filters', $html, $file);
			$this->assertStringContainsString('data-crm-live-filter', $html, $file);
		}
	}

	public function testTodaySupportsInlineDone(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$this->assertStringContainsString('data-crm-complete-activity', $html);
		$this->assertStringContainsString('table-container crm-table--responsive', $html);
		$this->assertStringContainsString('crm-today-row', $html);
		$this->assertStringContainsString('crm-today-role', $html);
		$this->assertStringContainsString('Jump to queue', $html);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/today.js');
		$this->assertStringContainsString('/activities/', $js);
		$this->assertStringContainsString('crm-today-row--done', $js);
	}

	public function testTodayUsesSuiteTableColumns(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		foreach (['What', 'Company', 'Due', 'Action'] as $col) {
			$this->assertStringContainsString("\$l->t('$col')", $html, $col);
		}
		$this->assertStringContainsString('crm-table-actions', $html);
		$this->assertStringContainsString('crm-company-link.php', $html);
	}

	public function testShellExposesRoleWriteAndTeamNav(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageShellTrait.php');
		$this->assertStringContainsString("'canWrite'", $src);
		$this->assertStringContainsString("'showTeamNav'", $src);
		$this->assertStringContainsString('WorkspaceCapability::canOpenTeamBoard', $src);
		$nav = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringContainsString("showTeamNav", $nav);
	}

	public function testPersonaJsDoesNotUseRemovedOcUtilEscapeHtml(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringNotContainsString('OC.Util.escapeHTML', $js);
		$this->assertMatchesRegularExpression('/function\s+escapeHtml\s*\(/', $js);
		$this->assertGreaterThan(20, substr_count($js, 'escapeHtml('));
	}

	public function testPulseUsesSuiteShellAndTables(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/pulse.php');
		$this->assertStringContainsString('page-start.php', $html);
		$this->assertStringContainsString('data-crm-persona-mount="pulse"', $html);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('crm-today-stat', $js);
		$this->assertStringContainsString('table-container crm-table--responsive', $js);
		$this->assertStringContainsString('crm-pulse-metrics', $js);
		$this->assertStringContainsString('crm-pulse-glance', $js);
		$this->assertStringContainsString("t('At a glance')", $js);
		$this->assertStringContainsString("t('Cash')", $js);
		$this->assertStringContainsString("t('Work')", $js);
		$this->assertStringContainsString("t('Unbilled hours')", $js);
		$this->assertStringContainsString("t('Paid lately')", $js);
		$this->assertStringContainsString("t('Renewals soon')", $js);
		$this->assertStringContainsString('crm-today-stat--alert', $js);
		$this->assertStringNotContainsString("t('Health metrics')", $js);
		$this->assertStringNotContainsString("t('Paid 30d')", $js);
		$this->assertStringNotContainsString("t('Renewals ≤30d')", $js);
		$this->assertStringNotContainsString("t('Not available yet')", $js);
		$this->assertStringNotContainsString("t('Book health')", $js);
		$this->assertStringContainsString('renderSuiteTable', $js);
		// Ops glance chrome: never plaster tax/GoBD legal lectures on Pulse or Money.
		$this->assertStringNotContainsString('crm-pulse-money-disclaimer', $js);
		$this->assertStringNotContainsString('Ops overview — not tax/GoBD certification.', $js);
		$this->assertStringNotContainsString('not tax filing', $js);
		$this->assertStringNotContainsString('GoBD', $js);
		$moneyHtml = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/money.php');
		$this->assertStringNotContainsString('crm-money-ops-disclaimer', $moneyHtml);
		$this->assertStringNotContainsString('Ops overview — not tax/GoBD certification.', $moneyHtml);
		$this->assertStringNotContainsString('not tax filing', $moneyHtml);
		$this->assertStringNotContainsString('GoBD', $moneyHtml);
		$moneyPhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('Overdue, unpaid, paid and unknown invoice states.', $moneyPhp);
		$this->assertStringNotContainsString('day-to-day ops, not tax filing', $moneyPhp);
		$this->assertStringNotContainsString('moneyOpsDisclaimer', $moneyPhp);
		$pulsePhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PulseService.php');
		$this->assertStringNotContainsString("'disclaimer'", $pulsePhp);
		$this->assertStringNotContainsString('Day-to-day ops amounts — not for tax filing.', $pulsePhp);
		$this->assertStringNotContainsString('Ops overview — not tax/GoBD certification.', $pulsePhp);
		$this->assertStringNotContainsString('not for tax filing', $pulsePhp);
		$this->assertStringNotContainsString("registerFrontEndAssets('pages/persona', null, [], ['pages/persona'])", (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php'));
		$this->assertStringContainsString('unbilledHint', $js);
		$this->assertStringContainsString('displayValue', $js);
		$this->assertStringNotContainsString("t('Not available yet')", $js);
		$this->assertDoesNotMatchRegularExpression('/t\(\s*[\'"]honesty_blocked/', $js);
		$this->assertStringContainsString('unbilledHint', $pulsePhp);
		$this->assertStringContainsString('honestOpenBillableMinutes', $pulsePhp);
		$facadeProbe = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FacadeProbeService.php');
		$this->assertStringContainsString('FACADE_VERSION', $facadeProbe);
		$this->assertStringContainsString('if ($version < 2)', $facadeProbe);
		$this->assertStringContainsString('sumOpenBillableMinutes($id)', $facadeProbe);
		$this->assertStringNotContainsString('sumOpenBillableMinutes()', $facadeProbe);
		$this->assertStringContainsString('commitmentStatusLabel', $js);
		$this->assertStringContainsString('commitmentKindLabel', $js);
		$this->assertStringContainsString('crm-brief-add', $js);
		$this->assertStringContainsString("t('Add a promise')", $js);
		$this->assertStringContainsString("t('What will you finish?')", $js);
		$this->assertStringContainsString("t('Add to this week')", $js);
		$this->assertStringContainsString('data-crm-brief-suggest', $js);
		$this->assertStringContainsString('crm-form--stack', $js);
		$this->assertStringNotContainsString("t('Confirm commitment')", $js);
		$this->assertStringNotContainsString("t('New commitment')", $js);
		$this->assertStringNotContainsString("t('Verify commitment')", $js);
		$this->assertStringContainsString("t('Check this promise')", $js);
		$this->assertStringContainsString('crm-form--stack', $js);
		$this->assertStringContainsString("t('What did you discuss?')", $js);
		$this->assertStringContainsString('data-crm-11-form', $js);
		$this->assertDoesNotMatchRegularExpression(
			"/form class=\"crm-form\"(?![\\s\\S]{0,40}crm-form--stack)/",
			$js,
			'Persona forms must use crm-form--stack, not bare crm-form stubs'
		);
		$this->assertStringContainsString('pulseAttentionKindLabel', $js);
		$this->assertStringContainsString('actionHtml', $js);
		$this->assertStringContainsString('data-crm-mail-triage-body', $js);
		$this->assertStringContainsString('data-crm-brief-last', $js);
		$this->assertStringContainsString('verifyActionsHtml', $js);
		$this->assertDoesNotMatchRegularExpression("/data-crm-brief-last[\\s\\S]{0,400}ul\\.className = 'crm-list'/", $js);
		$this->assertStringContainsString("data-crm-mail-triage-body", $js);
		$this->assertStringContainsString('kindLabel', $pulsePhp);
		$this->assertStringContainsString('pulseAttentionKind', $pulsePhp);
		$this->assertStringNotContainsString("' · ' . \$health", $pulsePhp);
		$preparePhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PrepareMeetingService.php');
		$this->assertStringContainsString('flagLabels', $preparePhp);
		$this->assertStringContainsString('buyingRoleLabel', $preparePhp);
		$this->assertStringContainsString('statusLabel', $preparePhp);
		$this->assertStringContainsString('missingLabels', $preparePhp);
		$coveragePhp = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CoverageService.php');
		$this->assertStringContainsString('missingLabels', $coveragePhp);
		$this->assertStringContainsString('coverageMissing', $coveragePhp);
		$companyDetailJs = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('coverageMissingLabel', $companyDetailJs);
		$this->assertStringContainsString('missingLabels', $companyDetailJs);
		$this->assertStringNotContainsString('cov.missing.join', $companyDetailJs);
	}

	public function testFilterLiveScriptIsRegistered(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/FrontEndAssetService.php');
		$this->assertStringContainsString("addScript(Application::APP_ID, 'common/filter-live')", $src);
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/filter-live.js');
		$this->assertStringContainsString('requestSubmit', $js);
		$this->assertStringContainsString('crm-filter-panel__form', $js);
	}

	public function testJumpNavRevealsCollapsedLinkedBundle(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/jump-nav.js');
		$this->assertStringContainsString('revealAncestorDetails', $js);
		$this->assertStringContainsString("tagName === 'DETAILS'", $js);
	}

	public function testTouchTargetsBumpOnMobile(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('crm-quick-filter', $css);
		$this->assertStringContainsString('@media (max-width: 767px)', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
		// Suite UX6: desktop interactive .crm-btn--sm must also meet 44px (px units —
		// rem-based sizing under-delivers with Nextcloud's 15px root font).
		$this->assertMatchesRegularExpression(
			'/\.crm-btn--sm[^{]*\{[^}]*min-height:\s*44px/s',
			$css,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-btn--sm[^{]*\{[^}]*min-height:\s*2\.25rem/s',
			$css,
		);
	}

	public function testChoiceControlsAlignWithLabels(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/\.crm-choice[^{]*\{[^}]*display:\s*flex\s*!important/s',
			$css,
			'crm-choice must flex-align control + label (!important against stale caches)'
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-choice[^{]*\{[^}]*align-items:\s*center\s*!important/s',
			$css,
		);
		$this->assertStringContainsString('.crm-choice input[type="radio"]', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-choice input\[type="radio"\][\s\S]{0,500}max-width:\s*1\.25rem\s*!important/s',
			$css,
			'Native radios must stay a fixed control size (label is the touch target)'
		);
		$import = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/import.php');
		$this->assertStringContainsString('class="crm-choice"', $import);
		$this->assertStringContainsString('type="radio"', $import);
		$info = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml');
		$this->assertMatchesRegularExpression('/<version>1\.5\.1[6-9]</', $info, 'Bump app version when shipping CSS cache-sensitive choice alignment');
	}
}
