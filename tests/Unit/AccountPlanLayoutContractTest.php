<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Account plan on Company 360 — design-system coach steps, page grid, flat sections.
 */
final class AccountPlanLayoutContractTest extends TestCase
{
	public function testHydratorUsesWelcomeCoachStepsAndMoreOptions(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('function accountPlanStepsHtml(plan, mapSteps)', $js);
		$this->assertStringContainsString('function planMoreShouldOpen(plan)', $js);
		$this->assertStringContainsString('crm-welcome-coach__steps crm-account-plan__guide', $js);
		$this->assertStringContainsString('crm-welcome-coach__step-title', $js);
		$this->assertStringContainsString('crm-welcome-coach__step-text', $js);
		$this->assertStringContainsString('crm-account-plan__stack', $js);
		$this->assertStringNotContainsString('crm-page-grid crm-account-plan__grid', $js);
		$this->assertStringContainsString('crm-account-plan__col--plan', $js);
		$this->assertStringContainsString('crm-account-plan__col--moves', $js);
		$this->assertStringContainsString('crm-account-plan__section crm-account-plan__section--moves', $js);
		$this->assertStringContainsString('crm-advanced crm-account-plan__more', $js);
		$this->assertStringContainsString("t('More options')", $js);
		$this->assertStringContainsString("t('Where should this customer be in a year?')", $js);
		$this->assertStringContainsString("t('Keeps the goal for your team.')", $js);
		$this->assertStringContainsString("t('Concrete steps toward the goal.')", $js);
		$this->assertStringContainsString('crm-account-plan__save', $js);
		$this->assertStringContainsString('crm-account-plan__saved', $js);
		$this->assertStringContainsString('id="crm-plan-save-status"', $js);
		$this->assertStringContainsString('function announceAccountPlanSaved()', $js);
		$this->assertStringContainsString('function bindAccountPlanDirty(form)', $js);
		$this->assertStringContainsString('loadAccountPlan(savedPlan)', $js);
		$this->assertStringContainsString("C.showToast({ type: 'success'", $js);
		$this->assertStringContainsString("C.showToast({ type: 'error'", $js);
		$this->assertStringContainsString('horizon: plan.horizon ||', $js);
		$this->assertStringContainsString('data-plan-just-saved', $js);
		$this->assertStringContainsString('crm-account-plan__panel', $js);
		$this->assertStringContainsString('crm-account-plan__panel-num', $js);
		$this->assertStringContainsString('aria-labelledby="crm-plan-goal-heading"', $js);
		$this->assertStringNotContainsString('crm-account-plan__layout', $js);
		$this->assertStringNotContainsString("t('Priority, owner & review')", $js);
		$this->assertStringNotContainsString('html += planDisclosureHtml();', $js);
		$this->assertStringNotContainsString('crm-card__header--summary', $js);
		$this->assertStringNotContainsString("return v == null ? escapeHtml(t('Open'))", $js);
	}

	public function testTemplateUsesAlwaysOpenAccountPlanSection(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString('<section class="crm-card crm-card--account-plan" id="crm-account-plan"', $html);
		$this->assertStringNotContainsString('<details class="crm-card crm-card--collapsible" id="crm-account-plan"', $html);
	}

	public function testAccountPlanCssUsesFullWidthCoachAndFlatSections(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-account-plan {', $css);
		$this->assertStringContainsString('.crm-account-plan__guide {', $css);
		$this->assertStringContainsString('.crm-account-plan__section {', $css);
		$this->assertStringContainsString('.crm-account-plan__panel {', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-account-plan__panel-title\s*\{[^}]*align-items:\s*center/s',
			$css,
			'Panel titles must vertically center the step number with the label',
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-account-plan__panel-title\s*\{[^}]*align-items:\s*flex-start/s',
			$css,
			'Panel titles must not top-align the step number',
		);
		$this->assertStringContainsString('.crm-account-plan__heading {', $css);
		$this->assertStringContainsString('.crm-account-plan__stack {', $css);
		$this->assertStringContainsString('.crm-account-plan__saved', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-account-plan__saved\s*\{[^}]*min-height:\s*var\(--crm-touch/s',
			$css,
			'Saved banner must stay tappable-sized (WCAG 2.5.5 / granny target)',
		);
		$this->assertStringNotContainsString('.crm-account-plan__layout {', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-account-plan\s*\{[^}]*max-width:\s*none/s',
			$css,
			'Account plan must use full panel width',
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-account-plan__stack\s*\{[^}]*flex-direction:\s*column/s',
			$css,
			'Account plan stack must stay a full-width vertical column',
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-account-plan__guide\.crm-welcome-coach__steps\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s',
			$css,
			'Coach steps stack to one column on narrow screens',
		);
		$this->assertMatchesRegularExpression(
			'/@media\s*\(min-width:\s*720px\)\s*\{[^}]*\.crm-account-plan__guide\.crm-welcome-coach__steps\s*\{[^}]*grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\)/s',
			$css,
			'Coach steps stay a horizontal 3-across row from 720px',
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.crm-account-plan__guide[^{]*\{[^}]*flex-direction:\s*column/s',
			$css,
			'Coach steps must not be forced into a vertical flex column',
		);
		$this->assertStringContainsString('.crm-card--account-plan > .crm-card__body {', $css);
	}

	public function testAddMoveFormLivesInsideMovesColumn(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$fn = strpos($js, 'const renderMovesSection = function');
		$this->assertNotFalse($fn);
		$chunk = substr($js, (int)$fn, 2200);
		$this->assertStringContainsString("id=\"crm-map-step-form\"", $chunk);
		$this->assertStringContainsString('crm-account-plan__section--moves', $chunk);
		$this->assertLessThan(strpos($chunk, "id=\"crm-map-step-form\""), strpos($chunk, 'crm-account-plan__section--moves'));
		$this->assertStringContainsString('crm-account-plan__col--moves', $js);
		$this->assertMatchesRegularExpression(
			'/crm-account-plan__col--moves[\s\S]{0,160}renderMovesSection\(mapSteps, true\)/',
			$js,
			'Moves column must wrap the moves renderer on the write path',
		);
	}

	public function testReadOnlyPathPutsGoalInPlanColumn(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertMatchesRegularExpression(
			'/crm-account-plan__col--plan[\s\S]{0,500}crm-account-plan__section--read[\s\S]{0,500}<dt>/',
			$js,
			'Read-only goal must render in the plan column',
		);
	}
}
