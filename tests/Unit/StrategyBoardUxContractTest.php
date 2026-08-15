<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Strategy / Plans board — Team/Companies shell parity, health-first scan, safe inline edit.
 */
final class StrategyBoardUxContractTest extends TestCase
{
	public function testStrategyTemplateUsesCardShellAndFilterRoot(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/strategy.php');
		$this->assertStringContainsString('id="crm-strategy-root"', $html);
		$this->assertStringContainsString('data-crm-strategy-filter="all"', $html);
		$this->assertStringContainsString('id="crm-strategy-board"', $html);
		$this->assertStringContainsString('crm-card__title', $html);
		$this->assertStringContainsString('crm-card__lead', $html);
		$this->assertStringContainsString("\$l->t('Account plans')", $html);
		$this->assertStringContainsString("\$l->t('Goals and health for every customer with a plan. Open a company to change the full plan.')", $html);
		$this->assertStringContainsString('data-crm-persona-mount="strategy"', $html);
		$this->assertStringContainsString('aria-busy="true"', $html);
		$this->assertStringContainsString('aria-live="polite"', $html);
		$this->assertStringNotContainsString('crm-advanced', $html);
	}

	public function testPersonaStrategyRendersTableFiltersAndAccessibleEdit(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('strategyLoadGeneration', $js);
		$this->assertStringContainsString('renderStrategyBoard', $js);
		$this->assertStringContainsString('crm-table--strategy', $js);
		$this->assertStringContainsString('data-crm-strategy-filter-btn', $js);
		$this->assertStringContainsString("t('Needs attention')", $js);
		$this->assertStringContainsString("t('Open plan')", $js);
		$this->assertStringContainsString('#crm-account-plan', $js);
		$this->assertStringContainsString("t('Priority, owner & review')", $js);
		$this->assertStringContainsString('class="form-label"', $js);
		$this->assertStringContainsString('crm-plan-goal', $js);
		$this->assertStringContainsString('crmPlanSnapshot', $js);
		$this->assertStringContainsString("t('Saving…')", $js);
		$this->assertStringContainsString("getAttribute('aria-busy') === 'true'", $js);
		$this->assertStringContainsString('priorityLabel', $js);
		$this->assertStringContainsString('healthLabel', $js);
		$this->assertStringContainsString('openMoves', $js);
		$this->assertStringContainsString('crm-strategy-edit', $js);
		$this->assertStringNotContainsString("t('No account plans yet — open a company to add one.')", $js);
	}

	public function testStrategyCssSupportsEditRowAndTouchTargets(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-table--strategy', $css);
		$this->assertStringContainsString('.crm-strategy-edit-row', $css);
		$this->assertStringContainsString('.crm-strategy-edit', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-table--strategy[\s\S]*?min-height:\s*44px/',
			$css,
		);
	}

	public function testAccountPlanHydrateExposesBoardLabelsAndStatusGate(): void
	{
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccountPlanService.php');
		$this->assertStringContainsString("'priorityLabel' => \$this->labels->planPriority", $svc);
		$this->assertStringContainsString("'statusLabel' => \$this->labels->planStatus", $svc);
		$this->assertStringContainsString("'openMoves' => \$openMoves", $svc);
		$this->assertStringContainsString('function normalizePlanStatus', $svc);
		$this->assertStringContainsString('function sortPlansForBoard', $svc);
		$this->assertStringContainsString("Unknown plan status.", $svc);
		$this->assertStringNotContainsString("'status' => \$ins->createNamedParameter('active')", $svc);

		$labels = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/DisplayLabelService.php');
		$this->assertStringContainsString('function planPriority', $labels);
		$this->assertStringContainsString('function planStatus', $labels);
	}

	public function testPageHelpAndQuickStartStayPlainLanguage(): void
	{
		$help = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageHelpCatalog.php');
		$this->assertStringContainsString(
			"\$l->t('See every account plan — health first. Open a company to change the full plan.')",
			$help,
		);
		$this->assertStringNotContainsString(
			"\$l->t('Account plans — open a company to add or edit one.')",
			$help,
		);
		$qs = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/PageQuickStartCatalog.php');
		$this->assertStringContainsString("'strategy' => [", $qs);
		$this->assertStringContainsString('Scan plan health', $qs);
	}
}
