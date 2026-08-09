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
		$this->assertStringContainsString('id="crm-ws-mailbox-card"', $html);
		$this->assertStringContainsString('id="crm-mailbox-form"', $html);
		$this->assertStringContainsString('crm-form crm-form--stack', $html);
		$this->assertStringContainsString('crm-callout--warning', $html);
		$this->assertStringContainsString('id="crm-mailbox-empty"', $html);
		$this->assertStringContainsString('No shared mailbox yet', $html);
		$this->assertStringContainsString('id="crm-mailbox-list"', $html);
		$this->assertStringContainsString('crm-fieldset', $html);
		$this->assertStringContainsString('1 · Who', $html);
		$this->assertStringContainsString('2 · Receive (IMAP)', $html);
		$this->assertStringContainsString('3 · Send (SMTP)', $html);
		$this->assertStringContainsString('id="crm-mbox-id"', $html);
		$this->assertStringContainsString('id="crm-mailbox-reset"', $html);
		$this->assertStringContainsString('leave blank to keep the saved password', $html);
	}

	public function testSettingsJsHydratesListAndGuardsSave(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/settings.js');
		$this->assertStringContainsString('function refreshMailboxes', $js);
		$this->assertStringContainsString('mailboxesGeneration', $js);
		$this->assertStringContainsString('const generation = ++mailboxesGeneration;', $js);
		$this->assertStringContainsString("'/mailboxes'", $js);
		$this->assertStringContainsString('CustomerCheckMailboxSettingsPresent', $js);
		$this->assertStringContainsString('requireWorkspaceReady(mailboxTestStatus)', $js);
		$this->assertStringContainsString('crm-mailbox-form', $js);
		$this->assertStringContainsString('mailboxSave.disabled = true', $js);
		$this->assertStringContainsString('await refreshMailboxes()', $js);
		$this->assertStringContainsString('resetMailboxForm()', $js);
		$savePos = (int)strpos($js, "'/mailboxes', payload)");
		$this->assertGreaterThan(0, $savePos);
		$saveChunk = substr($js, $savePos, 400);
		$this->assertStringContainsString('await refreshMailboxes()', $saveChunk);
		$this->assertStringNotContainsString('location.reload()', $saveChunk);
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
	}

	public function testCssDefinesMailboxListAndFieldsets(): void
	{
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-mailbox-settings', $css);
		$this->assertStringContainsString('.crm-mailbox-list__item', $css);
		$this->assertStringContainsString('.crm-fieldset__legend', $css);
		$this->assertStringContainsString('.crm-ws-members__empty', $css);
	}
}
