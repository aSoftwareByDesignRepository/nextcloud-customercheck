<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UX / surface contracts for deal checklists on Company 360 (CUC-DEAL-CHECKLIST-1).
 */
final class DealChecklistUxContractTest extends TestCase
{
	public function testCompanyDetailExposesChecklistButtonOnDeals(): void
	{
		$tpl = file_get_contents(__DIR__ . '/../../templates/company-detail.php');
		$this->assertNotFalse($tpl);
		$this->assertMatchesRegularExpression('/\bdata-crm-deal-checklist\b/', $tpl);
		$this->assertStringContainsString("\$l->t('Checklist')", $tpl);
	}

	public function testCompanyDetailJsWiresChecklistDialogAndReplaceConfirm(): void
	{
		$js = file_get_contents(__DIR__ . '/../../js/pages/company-detail.js');
		$this->assertNotFalse($js);
		$this->assertStringContainsString('openDealChecklist', $js);
		$this->assertStringContainsString("'/checklist-templates'", $js);
		$this->assertStringContainsString('requireTypedReplaceConfirm()', $js);
		$this->assertStringNotContainsString("typedConfirmPhrase: 'REPLACE'", $js);
		$this->assertStringContainsString("'/deal-checklist-items/'", $js);
		$this->assertStringContainsString('complete', $js);
		$this->assertStringContainsString('data-cl-quickadd', $js);
		$this->assertStringContainsString('DEFAULT_CHECKLIST_DONE_NOTE', $js);
	}

	public function testMobileRoutesExposeChecklistDone(): void
	{
		$routes = file_get_contents(__DIR__ . '/../../appinfo/routes.php');
		$this->assertNotFalse($routes);
		$this->assertStringContainsString('mobile#doneChecklistItem', $routes);
		$this->assertStringContainsString('/checklist-items/{id}/done', $routes);
	}

	public function testReplaceUsesOpenOnlyDelete(): void
	{
		$svc = file_get_contents(__DIR__ . '/../../lib/Service/DealChecklistService.php');
		$this->assertNotFalse($svc);
		$this->assertStringContainsString('deleteOpenByDeal', $svc);
		$this->assertStringNotContainsString(
			"\$this->items->deleteByDeal(\$dealId);",
			$svc,
			'replace must not wipe done history via deleteByDeal',
		);
	}

	public function testSettingsExposesChecklistTemplateSection(): void
	{
		$catalog = file_get_contents(__DIR__ . '/../../lib/Service/WorkspaceSettingsSectionCatalog.php');
		$settings = file_get_contents(__DIR__ . '/../../templates/settings.php');
		$tpl = file_get_contents(__DIR__ . '/../../templates/settings/workspace-checklists.php');
		$routes = file_get_contents(__DIR__ . '/../../appinfo/routes.php');
		$this->assertNotFalse($catalog);
		$this->assertNotFalse($settings);
		$this->assertNotFalse($tpl);
		$this->assertStringContainsString("'checklists'", $catalog);
		$this->assertStringContainsString("'checklists' => 'workspace-checklists.php'", $settings);
		$this->assertStringContainsString('crm-cl-tmpl-new', $tpl);
		$this->assertStringContainsString('hardware/goods', $tpl);
		$this->assertStringContainsString('checklist-templates/manage', $routes);
		$this->assertStringContainsString('deal_checklist_api#createTemplate', $routes);
	}

	public function testSystemTemplatesIncludeHardwareGoodsOrder(): void
	{
		$v1011 = file_get_contents(__DIR__ . '/../../lib/Migration/Version1011Date20260811200000.php');
		$v1012 = file_get_contents(__DIR__ . '/../../lib/Migration/Version1012Date20260811213000.php');
		$dealSvc = file_get_contents(__DIR__ . '/../../lib/Service/DealService.php');
		$this->assertNotFalse($v1011);
		$this->assertNotFalse($v1012);
		$this->assertStringContainsString("'key' => 'license_order'", $v1011);
		$this->assertStringContainsString("'key' => 'consulting_order'", $v1011);
		$this->assertStringContainsString("'key' => 'project_order'", $v1011);
		$this->assertStringContainsString("'key' => 'hardware_order'", $v1011);
		$this->assertStringContainsString('hardware_order', $v1012);
		$this->assertStringContainsString('Hardware / goods order', $v1012);
		// No closed product-type / SKU gate on deal create.
		$this->assertStringNotContainsString('license SKU', $dealSvc);
		$this->assertStringNotContainsString('productType', $dealSvc);
		$this->assertStringNotContainsString('PRODUCT_TYPES', $dealSvc);
	}

	public function testTodayOverviewAndNotificationsSurface(): void
	{
		$board = file_get_contents(__DIR__ . '/../../lib/Service/CommandBoardService.php');
		$push = file_get_contents(__DIR__ . '/../../lib/Service/MobilePushService.php');
		$svc = file_get_contents(__DIR__ . '/../../lib/Service/DealChecklistService.php');
		$stuck = file_get_contents(__DIR__ . '/../../lib/Service/StuckLogic.php');
		$coach = file_get_contents(__DIR__ . '/../../lib/Service/CoachingService.php');
		$notifier = file_get_contents(__DIR__ . '/../../lib/Notification/Notifier.php');
		$this->assertNotFalse($board);
		$this->assertNotFalse($push);
		$this->assertNotFalse($svc);
		$this->assertStringContainsString("'id' => 'my_deal_tasks'", $board);
		$this->assertStringContainsString("'kind' => 'deal_checklist'", $board);
		$this->assertMatchesRegularExpression(
			"/'id'\\s*=>\\s*'my_deal_tasks'[\\s\\S]*?'coveringBadge'\\s*=>\\s*\\\$covering\\s*\\?\\s*\\\$this->l10n->t\\('Covering'\\)\\s*:\\s*null/m",
			$board,
		);
		$this->assertStringContainsString('Constants::NOTIFY_DEAL_CHECKLIST', $push);
		$this->assertStringContainsString("'workspaceId' => (int)\$item->getWorkspaceId()", $svc);
		$this->assertStringContainsString('checklistOverdue', $stuck);
		$this->assertStringContainsString('overdueChecklistCountsByOwner', $coach);
		$this->assertStringContainsString('notifyChecklistBulkReassign', $coach);
		$this->assertStringContainsString('NOTIFY_DEAL_CHECKLIST', $notifier);
		$this->assertStringContainsString('page.today', $notifier);
		$this->assertStringContainsString('countOpenByDeal', $svc);
		$this->assertStringContainsString('lockRow($openId)', $svc);
		$this->assertStringContainsString('assertDealItemCapacity', $svc);
		$this->assertStringContainsString('MAX_ITEMS_PER_DEAL', $svc);
		$this->assertStringContainsString('lockRow($itemId)', $svc);
		$this->assertStringContainsString('dealMapper->lockRow($dealId)', $svc);
		$this->assertStringContainsString('DbTransaction::begin', $svc);
		$this->assertStringNotContainsString('$this->db->beginTransaction();', $svc);
		$this->assertStringContainsString('flushPendingAssignNotifies', $svc);
		$this->assertStringContainsString('_pendingAssignItemIds', $svc);
		$this->assertMatchesRegularExpression(
			'/function applyTemplate[\s\S]*?DbTransaction::commit\(\$this->db, \$ownsTxn\);[\s\S]*?(notifyAssign|_pendingAssignItemIds)/m',
			$svc,
		);
	}
}
