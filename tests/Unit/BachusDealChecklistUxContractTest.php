<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus: deal checklist extreme simplicity — one-click Done, no typed REPLACE, Today parity.
 */
final class BachusDealChecklistUxContractTest extends TestCase
{
	public function testCompanyChecklistUsesUxModuleAndOneClickDone(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$ux = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/deal-checklist-ux.js');
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/deal-checklist-ux.js');
		$this->assertStringContainsString('CustomerCheckDealChecklistUx', $js);
		$this->assertStringContainsString('DEFAULT_CHECKLIST_DONE_NOTE', $js);
		$this->assertStringContainsString('data-cl-quickadd', $js);
		$this->assertStringContainsString('data-cl-apply-tmpl', $js);
		$this->assertStringContainsString('requireTypedReplaceConfirm()', $js);
		$this->assertStringNotContainsString("typedConfirmPhrase: 'REPLACE'", $js);
		$this->assertStringNotContainsString("data-cl-add>", $js);
		$this->assertStringContainsString("export const DEFAULT_CHECKLIST_DONE_NOTE = 'Done'", $ux);
		$this->assertMatchesRegularExpression(
			'/function requireTypedReplaceConfirm\(\)\s*\{\s*return false;/s',
			$ux,
		);
		$this->assertStringContainsString('CustomerCheckDealChecklistUx', $boot);
	}

	public function testTodayOneClickCompletesDealChecklist(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/today.js');
		$board = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/CommandBoardService.php');
		$page = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('data-crm-complete-checklist', $tpl);
		$this->assertStringContainsString('checklistItemId', $tpl);
		$this->assertStringContainsString("'/deal-checklist-items/'", $js);
		$this->assertStringContainsString('evidenceNote: doneNote', $js);
		$this->assertStringContainsString("'cta' => 'done'", $board);
		$this->assertStringContainsString('common/deal-checklist-ux', $page);
	}

	public function testChecklistButtonIsPrimaryAndCssHasGiantTargets(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/company-detail.php');
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertMatchesRegularExpression(
			'/crm-btn--primary"[^>]*data-crm-deal-checklist=/',
			$tpl,
		);
		$this->assertStringContainsString('crm-deal-checklist-quickadd', $css);
		$this->assertStringContainsString('crm-deal-checklist-starters', $css);
		$this->assertStringContainsString('min-height: 48px', $css);
	}

	public function testA11yContractsForChecklistSurfaces(): void
	{
		$ux = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/deal-checklist-ux.js');
		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/today.php');
		$this->assertStringContainsString('aria-label', $ux);
		$this->assertStringContainsString('crm-sr-only', $ux);
		$this->assertStringContainsString('aria-required="true"', $ux);
		$this->assertStringContainsString('role="group"', $ux);
		$this->assertStringContainsString('aria-label="<?php p($l->t(\'Mark done:', $tpl);
	}
}
