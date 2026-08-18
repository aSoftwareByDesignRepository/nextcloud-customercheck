<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Account plan save/add guards — aria-busy, conflict reload, owner display name.
 */
final class AccountPlanSaveGuardContractTest extends TestCase
{
	public function testSaveFormUsesBusyGuardAndConflictReload(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('function accountPlanOwnerSearchLabel(plan)', $js);
		$this->assertStringContainsString("form.getAttribute('aria-busy') === 'true'", $js);
		$this->assertStringContainsString("form.setAttribute('aria-busy', 'true')", $js);
		$this->assertStringContainsString("form.removeAttribute('aria-busy')", $js);
		$this->assertStringContainsString("submitBtn.textContent = t('Saving…')", $js);
		$this->assertStringContainsString('Someone else changed this account plan', $js);
		$this->assertMatchesRegularExpression(
			'/isConflict[\s\S]{0,400}await loadAccountPlan\(\)/',
			$js,
			'Conflict path must reload the plan after alerting',
		);
		$this->assertStringContainsString("mapForm.getAttribute('aria-busy') === 'true'", $js);
		$this->assertStringContainsString("mapForm.setAttribute('aria-busy', 'true')", $js);
	}

	public function testStepsReflectPlanProgress(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('function accountPlanStepsHtml(plan, mapSteps)', $js);
		$this->assertStringContainsString('crm-welcome-coach__step--done', $js);
		$this->assertStringContainsString('accountPlanStepsHtml(plan, mapSteps)', $js);
		$this->assertStringNotContainsString('function planDisclosureHtml()', $js);
	}

	public function testPanelsNumberGoalAndMoves(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('crm-account-plan__panel crm-account-plan__panel--goal', $js);
		$this->assertStringContainsString('crm-account-plan__panel crm-account-plan__panel--moves', $js);
		$this->assertStringContainsString('crm-account-plan__panel-num', $js);
		$this->assertStringContainsString('crm-account-plan__panel-label', $js);
		$this->assertStringContainsString('plan.ownerDisplayName || plan.ownerUserId', $js);
		$this->assertStringContainsString('accountPlanOwnerSearchLabel(plan)', $js);
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-account-plan__panel {', $css);
		$this->assertStringContainsString('.crm-account-plan__panel-num {', $css);
	}

	public function testHydrateExposesOwnerDisplayName(): void
	{
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccountPlanService.php');
		$this->assertStringContainsString('DirectoryOptionsService $directory', $svc);
		$this->assertStringContainsString("'ownerDisplayName' =>", $svc);
		$this->assertStringContainsString('$this->directory->resolveUsers', $svc);
	}
}
