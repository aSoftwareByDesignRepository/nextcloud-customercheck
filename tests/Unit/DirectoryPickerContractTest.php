<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio rule: never ask operators to type raw Nextcloud user ids on
 * assign-member / assign-seat / allow-list surfaces — directory pickers only.
 */
final class DirectoryPickerContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testWorkspaceMemberFormUsesDirectoryPickerNotRawUidInput(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/settings/workspace-mailbox.php');
		$this->assertStringContainsString('id="crm-ws-member-search"', $html);
		$this->assertStringContainsString('role="combobox"', $html);
		$this->assertStringContainsString('id="crm-ws-member-uid"', $html);
		$this->assertStringContainsString('type="hidden"', $html);
		$this->assertStringContainsString('user-picker', $html);
		$this->assertStringNotContainsString('for="crm-ws-member-uid"', $html);
		$this->assertDoesNotMatchRegularExpression(
			'/<input[^>]+id="crm-ws-member-uid"[^>]+type="text"/',
			$html,
		);
		$this->assertStringContainsString('Never type a raw user id', $html);
	}

	public function testLicenseSeatAssignUsesDirectoryPickerNotRawUidInput(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/settings/license.php');
		$this->assertStringContainsString('id="crm-license-seat-search"', $html);
		$this->assertStringContainsString('role="combobox"', $html);
		$this->assertStringContainsString('id="crm-license-seat-user"', $html);
		$this->assertDoesNotMatchRegularExpression(
			'/<input[^>]+id="crm-license-seat-user"[^>]+type="text"/',
			$html,
		);
		$this->assertStringNotContainsString('Nextcloud user id, e.g. anna', $html);
		$this->assertStringContainsString('Never type a raw user id', $html);
	}

	public function testAccessAllowListUsesSearchChipsNotRawUidHelp(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/settings/access.php');
		$this->assertStringContainsString('crm-allowed-users-search', $html);
		$this->assertStringContainsString('crm-allowed-users-chips', $html);
		$this->assertStringContainsString('Never type a raw user id', $html);
		$this->assertStringNotContainsString('allowlisted by user id', $html);
	}

	public function testSettingsJsCommitsHiddenUidFromPickerOnly(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('function bindDirectoryUserPicker', $js);
		$this->assertStringContainsString('Select a colleague from the directory first.', $js);
		$this->assertStringContainsString("workspaceIdFn: () => currentWorkspaceId || 0", $js);
		$this->assertStringNotContainsString('Enter the Nextcloud user id first.', $js);
	}

	public function testSearchUsersAllowsWorkspaceManagersWithWorkspaceId(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Controller/SettingsApiController.php');
		$this->assertStringContainsString('assertCapability($uid, $workspaceId, \'write\')', $src);
		$this->assertStringContainsString('mb_strlen(trim($query)) < 2', $src);
		$this->assertStringContainsString('WorkspaceAccessService', $src);
	}

	public function testDealAndPlanOwnerPickersForbidRawUidTextInputs(): void
	{
		$pipeline = (string) file_get_contents($this->root . '/js/pages/pipeline.js');
		$company = (string) file_get_contents($this->root . '/js/pages/company-detail.js');
		$this->assertStringContainsString('mountOwnerPicker', $pipeline);
		$this->assertStringContainsString('Never type a raw user id', $pipeline);
		$this->assertStringContainsString('user-picker', $pipeline);
		$this->assertDoesNotMatchRegularExpression(
			'/name="ownerUserId" type="text"/',
			$pipeline,
		);
		$this->assertStringContainsString('crm-plan-owner-search', $company);
		$this->assertStringContainsString('Never type a raw user id', $company);
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-plan-owner"[^>]*type="text"/',
			$company,
		);
		$this->assertStringContainsString('type="hidden" id="crm-plan-owner"', $company);
	}

	public function testTeamCapacityToolsUseDirectoryPickersNotRawUidFields(): void
	{
		$js = (string) file_get_contents($this->root . '/js/pages/persona.js');
		$this->assertStringContainsString('function bindPersonaUserPicker(', $js);
		$this->assertStringContainsString('bindPersonaUserPicker(Object.assign', $js);
		$this->assertStringContainsString('crm-ooo-primary-search', $js);
		$this->assertStringContainsString('crm-bulk-from-search', $js);
		$this->assertStringContainsString('crm-esc-subject-search', $js);
		$this->assertStringContainsString('Never type a raw user id', $js);
		$this->assertStringContainsString('I confirm reassigning work from the selected', $js);
		$this->assertStringContainsString("confirmName: fromUser", $js);
		$this->assertStringContainsString('/api/v1/settings/users?q=', $js);
		$this->assertStringContainsString('Select a colleague from the directory first.', $js);
		$this->assertStringNotContainsString('Type the from-user name to confirm', $js);
		$this->assertStringNotContainsString('autocomplete="username"', $js);
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-ooo-primary"[^>]*class="crm-input form-input"/',
			$js,
		);
		// Hidden uid is committed only after a suggestion click — typing clears it.
		$this->assertMatchesRegularExpression(
			'/if \(hidden\.value\) \{\s*hidden\.value = \'\';/',
			$js,
		);
	}

	public function testUserPickerKeyboardNavIsRegisteredAndWired(): void
	{
		$nav = (string)file_get_contents($this->root . '/js/common/user-picker-nav.js');
		$assets = (string)file_get_contents($this->root . '/lib/Service/FrontEndAssetService.php');
		$pipeline = (string)file_get_contents($this->root . '/js/pages/pipeline.js');
		$persona = (string)file_get_contents($this->root . '/js/pages/persona.js');
		$settings = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('bindListboxKeyboard', $nav);
		$this->assertStringContainsString("ArrowDown", $nav);
		$this->assertStringContainsString("aria-activedescendant", $nav);
		$this->assertStringContainsString("common/user-picker-nav", $assets);
		foreach ([$pipeline, $persona, $settings] as $js) {
			$this->assertStringContainsString('CustomerCheckUserPickerNav', $js);
		}
	}

	public function testCompanyMoveWorkspaceUsesConfirmAndOptimisticLock(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/company-detail.php');
		$js = (string)file_get_contents($this->root . '/js/pages/company-detail.js');
		$shared = (string)file_get_contents($this->root . '/js/common/shared-mail-send.js');
		$this->assertStringContainsString('crm-company-move-workspace', $html);
		$this->assertStringContainsString('/move-workspace', $js);
		$this->assertStringContainsString('confirmSlug', $js);
		$this->assertStringContainsString('updatedAt', $js);
		$this->assertStringContainsString('Shared mailbox: everyone in this workspace', $shared);
	}

	public function testMailTriageUsesCompanySearchNotTypedId(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/persona.js');
		$this->assertStringContainsString('Search by company name', $js);
		$this->assertStringContainsString('/api/v1/companies?q=', $js);
		$this->assertStringContainsString('crm-triage-company-picker', $js);
		$this->assertStringNotContainsString("t('Company ID')", $js);
		$this->assertDoesNotMatchRegularExpression(
			'/name="companyId"[^>]*type="number"/',
			$js,
		);
	}

	public function testSharedSendPicksMailboxWhenMultipleExist(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/shared-mail-send.js');
		$this->assertStringContainsString('pickMailbox', $js);
		$this->assertStringContainsString('Which shared mailbox?', $js);
		$this->assertStringContainsString('lastMailboxByWs', $js);
		$this->assertStringContainsString('attachmentFileIds', $js);
		$this->assertStringContainsString('data-crm-attach-file', $js);
		$this->assertStringContainsString('pickFile', $js);
		$job = (string)file_get_contents($this->root . '/lib/BackgroundJob/MailRetentionPurgeJob.php');
		$this->assertStringContainsString('purgeExpiredAcrossWorkspaces', $job);
		$info = (string)file_get_contents($this->root . '/appinfo/info.xml');
		$this->assertStringContainsString('MailRetentionPurgeJob', $info);
	}

	public function testMailDraftsInboxRoutesAndPersonaUi(): void
	{
		$routes = (string)file_get_contents($this->root . '/appinfo/routes.php');
		$persona = (string)file_get_contents($this->root . '/js/pages/persona.js');
		$company = (string)file_get_contents($this->root . '/lib/Service/CompanyService.php');
		$drafts = (string)file_get_contents($this->root . '/lib/Service/MailDraftService.php');
		$send = (string)file_get_contents($this->root . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString("mail/drafts", $routes);
		$this->assertStringContainsString('listMailDrafts', $routes);
		$this->assertStringContainsString('sendMailDraft', $routes);
		$this->assertStringContainsString('deleteMailDraft', $routes);
		$this->assertStringContainsString('Failed sends', $persona);
		$this->assertStringContainsString('/mail/drafts', $persona);
		$this->assertStringContainsString('data-crm-draft-resend', $persona);
		$this->assertStringContainsString('isNull(\'idempotency_key\')', $drafts);
		$this->assertStringContainsString('reuseDraftId', $send);
		$this->assertStringContainsString('claimFailedDraft', $send);
		$this->assertStringContainsString("set('subject', \$qb->createNamedParameter('[erased]'))", $company);
		$this->assertStringContainsString("set('body_text', \$qb->createNamedParameter(''))", $company);
		$this->assertStringContainsString('draftsScrubbed', $company);
		$this->assertStringContainsString("set('from_addr', \$qb->createNamedParameter('[erased]'))", $company);
		$this->assertStringContainsString("set('preview', \$qb->createNamedParameter(''))", $company);
	}

	public function testQuotePdfAndCompanyFilesFolderGuards(): void
	{
		$pipeline = (string)file_get_contents($this->root . '/js/pages/pipeline.js');
		$companyCtrl = (string)file_get_contents($this->root . '/lib/Controller/CompanyApiController.php');
		$deal = (string)file_get_contents($this->root . '/lib/Service/DealService.php');
		$files = (string)file_get_contents($this->root . '/lib/Service/FilesAccessService.php');
		$picker = (string)file_get_contents($this->root . '/js/common/folder-picker.js');
		$tpl = (string)file_get_contents($this->root . '/templates/pipeline.php');
		$this->assertStringContainsString('pickFile', $picker);
		$this->assertStringContainsString('Pick PDF', $pipeline);
		$this->assertStringContainsString('quoteFileId', $pipeline);
		$this->assertStringContainsString('data-quote-file-id', $tpl);
		$this->assertStringContainsString("unset(\$body['filesFolderId'], \$body['filesFolderPath'])", $companyCtrl);
		$this->assertStringContainsString('resolveReadableFile', $deal);
		$this->assertStringContainsString('getUserFolder', $files);
		$this->assertStringContainsString('loadMailAttachments', $files);
	}

	public function testAccessSettingsExposeDoorLayers(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/settings/access.php');
		foreach (['crm-access-mode', 'id="crm-app-admins-chips"', 'crm-allowed-users-search', 'crm-allowed-groups-list'] as $needle) {
			$this->assertStringContainsString($needle, $html);
		}
	}
}
