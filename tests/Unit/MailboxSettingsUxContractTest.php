<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Shared mailbox settings — granny cards, list hydrate, boot lock, honest secrets. */
final class MailboxSettingsUxContractTest extends TestCase
{
	public function testMailboxPageIsStackedCardsWithFormStackAndEmptyState(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$this->assertStringContainsString('id="section-workspace-mailbox"', $html);
		$this->assertStringContainsString('class="crm-mailbox-settings" id="section-workspace-mailbox"', $html);
		$this->assertStringContainsString('crm-mailbox-settings__stack', $html);
		$this->assertStringContainsString('id="crm-ws-limits-card"', $html);
		$this->assertStringContainsString('id="crm-ws-members-card"', $html);
		$this->assertStringContainsString('id="crm-ws-personal-mail-policy"', $html);
		$this->assertStringNotContainsString('id="crm-ws-personal-mail-mine"', $html);
		$this->assertStringContainsString('id="crm-ws-mailbox-card"', $html);
		$this->assertStringContainsString('crm-mailbox-settings__jump', $html);
		$this->assertStringContainsString('href="#crm-ws-mailbox-card"', $html);
		$this->assertStringContainsString('id="crm-mailbox-compose"', $html);
		$this->assertStringContainsString('id="crm-ws-member-add"', $html);
		$this->assertStringContainsString('Add or edit mailbox', $html);
		$this->assertStringContainsString('id="crm-mailbox-form"', $html);
		$this->assertStringContainsString('crm-form crm-form--stack', $html);
		$this->assertStringContainsString('crm-callout--warning', $html);
		$this->assertStringContainsString("IconCatalog::renderCalloutWell('alert-triangle', 'warning')", $html);
		$this->assertStringNotContainsString('<span class="crm-callout__icon" aria-hidden="true"></span>', $html);
		$this->assertStringContainsString('id="crm-mailbox-empty"', $html);
		$this->assertStringContainsString('No shared mailbox yet', $html);
		$this->assertStringContainsString('id="crm-mailbox-list"', $html);
		$this->assertStringContainsString('Up to three shared team inboxes', $html);
		$this->assertStringContainsString('Sync now refreshes every healthy mailbox', $html);
		$this->assertStringContainsString('crm-fieldset', $html);
		$this->assertStringContainsString('Inbox', $html);
		$this->assertStringContainsString('Mail servers', $html);
		$this->assertStringContainsString('id="crm-mailbox-servers"', $html);
		$this->assertStringContainsString('<details class="crm-advanced" id="crm-mailbox-servers">', $html);
		$this->assertStringContainsString('Mail servers (from your hoster)', $html);
		$this->assertStringContainsString('id="crm-mbox-imap-host"', $html);
		$this->assertStringContainsString('id="crm-mbox-smtp-host"', $html);
		$this->assertStringContainsString('id="crm-mbox-imap-enc"', $html);
		$this->assertStringContainsString('id="crm-mbox-smtp-enc"', $html);
		$this->assertStringContainsString('value="465"', $html);
		$this->assertStringContainsString('Do not paste the account id in parentheses', $html);
		$this->assertStringContainsString('Connect a team inbox only', $html);
		$this->assertStringContainsString('forward their mail here', $html);
		$this->assertStringContainsString('never on every recipient', $html);
		$this->assertStringNotContainsString('1 · Who', $html);
		$this->assertStringNotContainsString('2 · Receive (IMAP)', $html);
		$this->assertStringNotContainsString('Server settings (IMAP / SMTP)', $html);
		$this->assertStringNotContainsString('<fieldset class="crm-fieldset" id="crm-mailbox-servers">', $html);
		$this->assertStringContainsString('id="crm-mbox-id"', $html);
		$this->assertStringContainsString('id="crm-mailbox-reset"', $html);
		$this->assertStringContainsString('id="crm-mbox-folder-picker"', $html);
		$this->assertStringContainsString('crm-folder-picker', $html);
		$this->assertStringContainsString('Folders to sync', $html);
		$this->assertStringNotContainsString('<textarea id="crm-mbox-folders"', $html);
		$this->assertStringNotContainsString('One folder path per line', $html);
		$this->assertStringContainsString('leave blank to keep the saved password', $html);
	}

	public function testSettingsJsHydratesListAndGuardsSave(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$this->assertStringContainsString('function refreshMailboxes', $js);
		$this->assertStringContainsString('mailboxesGeneration', $js);
		$this->assertStringContainsString('const generation = ++mailboxesGeneration;', $js);
		$this->assertStringContainsString('mailboxPayloadFromForm', $js);
		$this->assertStringContainsString('imapEnc', $js);
		$this->assertStringContainsString('smtpEnc', $js);
		$this->assertStringContainsString('syncMailboxEncFromPorts', $js);
		$this->assertStringContainsString("'/mailboxes'", $js);
		$this->assertStringContainsString('CustomerCheckMailboxSettingsPresent', $js);
		$this->assertStringContainsString('requireWorkspaceReady(mailboxTestStatus)', $js);
		$this->assertStringContainsString('crm-mailbox-form', $js);
		$this->assertStringContainsString('mailboxSave.disabled = true', $js);
		$this->assertStringContainsString('await refreshMailboxes()', $js);
		$this->assertStringContainsString('resetMailboxForm()', $js);
		$this->assertStringContainsString('fillMailboxFormFromCache', $js);
		$this->assertStringContainsString('setMailboxComposeOpen', $js);
		$this->assertStringContainsString('syncMailboxComposeVisibility', $js);
		$this->assertStringContainsString('highlightSavedMailbox', $js);
		$this->assertStringContainsString('Saved. Your mailbox is listed above', $js);
		$this->assertStringContainsString('crm-mailbox-list__item--just-saved', $js);
		$savePos = (int)strpos($js, "'/mailboxes', payload)");
		$this->assertGreaterThan(0, $savePos);
		$saveChunk = substr($js, $savePos, 1200);
		$this->assertStringContainsString('await refreshMailboxes()', $saveChunk);
		$this->assertStringContainsString('fillMailboxFormFromCache(savedId', $saveChunk);
		$this->assertStringContainsString('highlightSavedMailbox(savedId)', $saveChunk);
		$this->assertStringNotContainsString('location.reload()', $saveChunk);
		// Success path must not wipe the form (looked like data loss).
		$this->assertStringNotContainsString('resetMailboxForm();', $saveChunk);
		$this->assertStringContainsString('await syncMailboxNow(savedId, null);', $js);
		$this->assertStringContainsString('function syncMailboxNow', $js);
		$this->assertStringContainsString("'/mailboxes/' + mailboxId + '/sync'", $js);
		$this->assertStringContainsString('function removeMailboxNow', $js);
		$this->assertStringContainsString("Api.post('/workspaces/' + currentWorkspaceId + '/mailboxes/' + mailboxId + '/remove', {})", $js);
		$this->assertStringContainsString('Remove this mailbox?', $js);
		$this->assertStringContainsString('No members yet', $js);
		$this->assertStringContainsString("empty.querySelector('.crm-empty-state__title').textContent = tr('No members yet');", $js);
	}

	public function testPresentHelpersRegisteredOnWorkspaceSettings(): void
	{
		$controller = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'common/mailbox-settings-present'", $controller);
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/mailbox-settings-present.js');
		$this->assertStringContainsString('buildMailboxListRows', $boot);
		$this->assertStringContainsString('renderMailboxList', $boot);
		$this->assertStringContainsString('textContent', $boot);
		$utils = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/mailbox-settings-present.js');
		$this->assertStringContainsString("case 'ok':", $utils);
		$this->assertStringContainsString("return 'success'", $utils);
		$this->assertStringContainsString("t('Connected')", $utils);
		$this->assertStringContainsString('filter((row) => row.id > 0)', $utils);
	}

	public function testMailboxServiceHasSecretIsHonest(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString("'secret_enc'", $src);
		$this->assertStringContainsString("'hasSecret' => trim((string)(\$row['secret_enc'] ?? '')) !== ''", $src);
		$this->assertStringNotContainsString("'hasSecret' => true,", $src);
		$this->assertStringContainsString("'folders' => \$this->listFolderPaths(\$id)", $src);
		$this->assertStringContainsString('array_key_exists(\'folders\', $data)', $src);
		$this->assertStringContainsString('function listFolderPaths', $src);
		$this->assertStringContainsString("->set('imap_enc'", $src);
		$this->assertStringContainsString("->set('smtp_enc'", $src);
		$this->assertStringContainsString('ImapConnection::normalizeUsername', $src);
		$this->assertStringContainsString('ImapConnection::resolveSmtpEnc', $src);
		// Update path must clear error quarantine or Sync permanently skips the box.
		$this->assertStringContainsString("->set('status', \$qb->createNamedParameter('ok'))", $src);
		$this->assertStringContainsString("->set('auth_fail_count', \$qb->createNamedParameter(0))", $src);
		$this->assertStringContainsString('public function delete(int $workspaceId, int $mailboxId, bool $allowPersonal = false)', $src);
		$this->assertStringContainsString('purgeOpenSharedMailAssignForMessages', $src);
		$this->assertStringContainsString("->delete('crm_review_item')", $src);
		$this->assertStringContainsString("->delete('crm_mailbox')", $src);
		$this->assertStringContainsString("->delete('crm_mail_msg')", $src);
		$this->assertStringContainsString("->delete('crm_mail_dft')", $src);
	}

	public function testSettingsJsHydratesFoldersOnEdit(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$this->assertStringContainsString('Array.isArray(box.folders)', $js);
		$this->assertStringContainsString('renderMailboxFolderPicker', $js);
		$this->assertStringContainsString('buildFolderPickerOptions', $js);
		$this->assertStringContainsString('readCheckedFolderPaths', $js);
		$this->assertStringContainsString('foldersWrapEl.hidden = folderList.length === 0', $js);
		$this->assertStringNotContainsString('folderList.join(\'\\n\')', $js);
	}

	public function testPresentHelpersRegisterFolderPicker(): void
	{
		$boot = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/mailbox-settings-present.js');
		$utils = (string)file_get_contents(dirname(__DIR__, 2) . '/js/utils/mailbox-settings-present.js');
		$this->assertStringContainsString('buildFolderPickerOptions', $boot);
		$this->assertStringContainsString('renderFolderPicker', $boot);
		$this->assertStringContainsString('readCheckedFolderPaths', $boot);
		$this->assertStringContainsString('export function buildFolderPickerOptions', $utils);
		$this->assertStringContainsString('export function readCheckedFolderPaths', $utils);
	}

	public function testJobsAndMailboxDigestCrossLinks(): void
	{
		$jobs = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/jobs.php');
		$mailbox = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$myEmail = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/my-email.php');
		$this->assertStringContainsString('crm-form--stack', $jobs);
		$this->assertStringContainsString('server clock', $jobs);
		$this->assertStringContainsString('Team & mailbox', $jobs);
		$this->assertStringContainsString('Reminders & digest', $mailbox);
		$this->assertStringContainsString('Copy calendar feed URL', $mailbox);
		// Signature HTML twins use visually-hidden textareas with aria-hidden — nothing else should.
		$withoutSigMirrors = (string)preg_replace(
			'/<textarea\\b[^>]*\\bvisually-hidden\\b[^>]*>.*?<\\/textarea>/is',
			'',
			$mailbox . $myEmail,
		);
		$this->assertStringNotContainsString('aria-hidden="true"', $withoutSigMirrors);
		$this->assertStringContainsString('id="crm-personal-mail-signature" class="visually-hidden"', $myEmail);
		$this->assertStringContainsString('id="crm-mbox-signature" class="visually-hidden"', $mailbox);
		$this->assertStringContainsString('crm-advanced__body', $myEmail);
		$this->assertMatchesRegularExpression(
			'/id="crm-personal-mail-signature-details".*?crm-advanced__body.*?crm-personal-mail-signature-editor/s',
			$myEmail,
		);
	}

	public function testMyEmailPageHostsPersonalConnectCard(): void
	{
		$html = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/my-email.php');
		$this->assertStringContainsString('id="section-my-email"', $html);
		$this->assertStringContainsString('class="crm-my-email-settings"', $html);
		$this->assertStringContainsString('id="crm-ws-personal-mail-mine"', $html);
		$this->assertStringContainsString('data-crm-bachus-pm="1"', $html);
		$this->assertStringContainsString('Ask a manager to enable Personal email sync under Team & mailbox', $html);
		$this->assertStringNotContainsString('id="crm-ws-personal-mail-policy"', $html);
		$this->assertStringNotContainsString('id="crm-mailbox-form"', $html);
	}

	public function testCssDefinesMailboxListAndFieldsets(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-mailbox-settings', $css);
		$this->assertStringContainsString('.crm-mailbox-list__item', $css);
		$this->assertStringContainsString('.crm-mailbox-list__item--just-saved', $css);
		$this->assertStringContainsString('.crm-fieldset__legend', $css);
		$this->assertStringContainsString('.crm-ws-members__empty', $css);
		$this->assertStringContainsString('.crm-folder-picker', $css);
	}
}
