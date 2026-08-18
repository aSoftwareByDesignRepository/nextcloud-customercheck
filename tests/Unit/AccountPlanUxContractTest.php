<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus: Account plan = goal first, moves as checklist + textarea — not a tiny “New next step” input.
 */
final class AccountPlanUxContractTest extends TestCase
{
	public function testPlanLeadExplainsPurposeInPlainLanguage(): void
	{
		$detail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$this->assertStringContainsString("\$l->t('Where this customer should go — write the goal, then the next moves.')", $detail);
		$this->assertStringNotContainsString("\$l->t('Goal, priority and next steps for this account.')", $detail);
	}

	public function testWriteUiUsesTextareaMovesAndDone(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('crm-advanced crm-account-plan__more', $js);
		$this->assertStringContainsString("t('More options')", $js);
		$this->assertStringContainsString("t('Add the next moves')", $js);
		$this->assertStringContainsString("t('Next moves')", $js);
		$this->assertStringContainsString("t('Plan moves stay here. Day-to-day to-dos live under To-dos above.')", $js);
		$this->assertStringContainsString("t('What should happen next?')", $js);
		$this->assertStringContainsString('id="crm-map-step-title" name="title" rows="3"', $js);
		$this->assertStringContainsString("t('Add move')", $js);
		$this->assertStringContainsString('data-crm-map-step-done', $js);
		$this->assertStringContainsString("/account-plan/map-steps/' + stepId + '/done'", $js);
		$this->assertStringNotContainsString("t('New next step')", $js);
		$this->assertStringNotContainsString("t('Add step')", $js);
		$this->assertStringContainsString('.crm-plan-add-move', (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css'));
	}

	public function testCompleteMapStepRouteExists(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$this->assertStringContainsString('persona_api#completeMapStep', $routes);
		$this->assertStringContainsString('/account-plan/map-steps/{id}/done', $routes);
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccountPlanService.php');
		$this->assertStringContainsString('function setMapStepStatus', $svc);
		$this->assertStringContainsString('function normalizeMapStepTitle', $svc);
		$this->assertStringContainsString('PARAM_STR_ARRAY', $svc);
		$this->assertStringNotContainsString("'term' . \$i", $svc);
	}
}
