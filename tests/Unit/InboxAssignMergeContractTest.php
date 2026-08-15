<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Inbox merges Assign into Unassigned with one chrome (list/reader/Reply/decide) — auditor contracts.
 */
final class InboxAssignMergeContractTest extends TestCase
{
	public function testReviewPageRedirectsIntoInboxUnassigned(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString('function review(): RedirectResponse', $ctrl);
		$this->assertStringContainsString('inboxAssignUrl', $ctrl);
		$this->assertStringContainsString("tab=unmatched", $ctrl);
		$this->assertStringContainsString("kind === 'shared_mail'", $ctrl);
		$this->assertStringContainsString("\$kind = 'mail'", $ctrl);
		$this->assertStringContainsString('function mailTriage(): RedirectResponse', $ctrl);
		$this->assertStringContainsString("inboxAssignUrl('mail')", $ctrl);
	}

	public function testSalesInboxUsesUnifiedChromeNotPersonaBoard(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertMatchesRegularExpression(
			"/function salesInbox\(\): TemplateResponse[\s\S]*?'common\/inbox-assign-map'/",
			$ctrl,
		);
		$salesStart = strpos($ctrl, 'function salesInbox(): TemplateResponse');
		$reviewStart = strpos($ctrl, 'function review(): RedirectResponse');
		$this->assertNotFalse($salesStart);
		$this->assertNotFalse($reviewStart);
		$salesInboxBlock = substr($ctrl, $salesStart, $reviewStart - $salesStart);
		$this->assertStringContainsString("'common/user-picker-nav'", $salesInboxBlock);
		$this->assertStringNotContainsString("'pages/persona'", $salesInboxBlock);

		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/sales-inbox.php');
		$this->assertStringContainsString('data-crm-inbox-unified="1"', $tpl);
		$this->assertStringContainsString('crm-sales-inbox-list-tools', $tpl);
		$this->assertStringContainsString('crm-sales-inbox-cols', $tpl);
		$this->assertStringContainsString('data-crm-inbox-sort="sent"', $tpl);
		$this->assertStringContainsString('data-crm-inbox-mail-filter="mail"', $tpl);
		$this->assertStringContainsString('crm-sales-inbox-decide', $tpl);
		$this->assertStringContainsString('crm-sales-inbox-assign-form', $tpl);
		$this->assertStringContainsString('crm-sales-inbox-company-picker', $tpl);
		$this->assertStringContainsString('crm-company-list', $tpl);
		$this->assertStringContainsString('crm-sales-inbox-suggest-card', $tpl);
		$this->assertStringContainsString('crm-sales-inbox-open-assign', $tpl);
		$this->assertStringContainsString('crm-sales-inbox-discard', $tpl);
		$this->assertStringContainsString('crm-sales-inbox__status', $tpl);
		$this->assertStringContainsString('id="crm-sales-inbox-reply"', $tpl);
		$this->assertStringContainsString('Unassigned', $tpl);
		$this->assertStringNotContainsString('crm-sales-inbox-assign-panel', $tpl);
		$this->assertStringNotContainsString('data-crm-persona-mount="review"', $tpl);
		$this->assertStringNotContainsString('crm-sales-inbox-search-wrap', $tpl);
		$this->assertStringContainsString('aria-controls="crm-sales-inbox-panel"', $tpl);
	}

	public function testNavDropsSeparateAssignLink(): void
	{
		$nav = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		$this->assertStringNotContainsString("\$u('review')", $nav);
		$this->assertStringContainsString("\$is('sales-inbox', 'review', 'mail-triage')", $nav);
		$this->assertStringContainsString("\$u('salesInbox')", $nav);
	}

	public function testSalesInboxLoadsAssignViaReviewApiInSamePane(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('loadAssignInbox', $js);
		$this->assertStringContainsString('applyListView', $js);
		$this->assertStringContainsString('mapReviewItemToRow', $js);
		$this->assertStringContainsString('updateDecideZone', $js);
		$this->assertStringContainsString('mountCompanyPanel', $js);
		$this->assertStringContainsString('CustomerCheckCompanyPicker', $js);
		$this->assertStringContainsString('openAssignPanel', $js);
		$this->assertStringContainsString('syncActionBar', $js);
		$this->assertStringContainsString("activeTab === 'unmatched'", $js);
		$this->assertStringContainsString('/review/items', $js);
		$this->assertStringContainsString('reviewItemId', $js);
		$this->assertStringContainsString('data-crm-inbox-sort', $js);
		$this->assertStringContainsString("tab === 'unassigned'", $js);
		$this->assertStringNotContainsString('CustomerCheckReviewBoard', $js);
		$this->assertStringNotContainsString('mountAssignBoard', $js);
		$this->assertStringNotContainsString('showMailPanels', $js);
	}

	public function testPersonaExportsDeferredReviewBoardForOtherPages(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('window.CustomerCheckReviewBoard', $js);
		$this->assertStringContainsString("data-crm-defer-boot') === '1'", $js);
		$this->assertStringContainsString("id: 'mail', label: t('Mail')", $js);
		$this->assertStringContainsString('personal_mail_shared_unmatched', $js);
		$this->assertStringContainsString('normalizeReviewKindAttr', $js);
		// Personal shared unmatched must open the same preview path as team shared mail (FAC/AS-12).
		$this->assertMatchesRegularExpression(
			'/const mailMsgId = isMailSourceKind\(item\.sourceKind\)\s*\n\s*\? \(parseInt/',
			$js,
		);
		$this->assertStringNotContainsString(
			"const mailMsgId = item.sourceKind === 'shared_mail'\n          ? (parseInt",
			$js,
		);
	}

	public function testReviewApiPassesKindToOpenCount(): void
	{
		$api = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/ReviewApiController.php');
		$this->assertStringContainsString('countOpen($workspaceId, $kindParam)', $api);
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('function countOpen(int $workspaceId, ?string $kind = null)', $svc);
		$this->assertStringContainsString('resolveKindFilter', $svc);
	}
}
