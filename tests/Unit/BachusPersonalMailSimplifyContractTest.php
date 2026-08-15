<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bachus 1.6.115 — My email OAuth-first, single SHARE dialog, reconnect deep-link.
 */
final class BachusPersonalMailSimplifyContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2);
	}

	public function testMyEmailConnectPutsDsgvoUnderOauthBeforeImap(): void
	{
		$html = (string)file_get_contents($this->root . '/templates/settings/my-email.php');
		$this->assertStringContainsString('data-crm-bachus-pm="1"', $html);
		$dsgvo = strpos($html, 'id="crm-pm-dsgvo"');
		$imap = strpos($html, 'id="crm-personal-mail-imap"');
		$oauth = strpos($html, 'id="crm-personal-mail-oauth"');
		$connect = strpos($html, 'id="crm-personal-mail-connect"');
		$remove = strpos($html, 'id="crm-personal-mail-remove"');
		$this->assertNotFalse($dsgvo);
		$this->assertNotFalse($imap);
		$this->assertNotFalse($oauth);
		$this->assertNotFalse($connect);
		$this->assertNotFalse($remove);
		$this->assertLessThan($dsgvo, $oauth, 'OAuth strip must sit above DSGVO ack');
		$this->assertLessThan($imap, $dsgvo, 'DSGVO sits under OAuth before collapsed IMAP (no scroll trap)');
		$this->assertLessThan($connect, $imap, 'IMAP panel comes before Connect CTA');
		$this->assertStringContainsString('Use email and app password', $html);
		$this->assertStringContainsString('Username (if different)', $html);
		$this->assertStringContainsString('Signature (optional)', $html);
		$this->assertStringContainsString('Remove inbox', $html);
		$this->assertStringContainsString('crm-btn--lg" id="crm-personal-mail-sync"', $html);
		$this->assertMatchesRegularExpression(
			'/id="crm-personal-mail-folders-save"[^>]*\bhidden\b/',
			$html,
		);
		// Flat connect: one IMAP panel, servers as divider — not box-in-box fieldsets.
		$this->assertStringContainsString('crm-personal-mail-imap__body', $html);
		$this->assertStringContainsString('crm-personal-mail-servers__body', $html);
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-personal-mail-imap"[\s\S]*?<fieldset class="crm-fieldset"/',
			$html,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-personal-mail-servers"[^>]*class="[^"]*crm-advanced/',
			$html,
		);
		// Hosts inside collapsed details must not use HTML required (hostile hidden validation).
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-pm-imap-host"[^>]*required/',
			$html,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/id="crm-pm-smtp-host"[^>]*required/',
			$html,
		);
	}

	public function testFolderTogglesAutoSave(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('schedulePersonalFoldersAutoSave', $js);
		$this->assertStringContainsString('savePersonalFolders', $js);
		$this->assertStringContainsString('personalFoldersSaveDirty', $js);
		$this->assertStringContainsString('changes save automatically', $js);
		$this->assertStringContainsString('serversEl.open = true', $js);
		$this->assertStringContainsString("shareEl.setAttribute('data-was-shared', '1')", $js);
	}

	public function testInboxBusyRetryDoesNotUnlockDuringHandOff(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('handOffRetry', $js);
		$this->assertStringContainsString('mailId: mid', $js);
		$this->assertStringContainsString('if (!handOffRetry)', $js);
		$this->assertStringContainsString('_busyRetried', $js);
	}

	public function testShareInboxSentIsSingleTypedDialog(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('requireTypedConfirm: !!inboxSent', $js);
		$this->assertStringContainsString("typedConfirmPhrase: 'SHARE'", $js);
		$this->assertStringContainsString('requireCheckbox: true', $js);
		// Typed SHARE lives in confirmDialog; window.prompt only as legacy fallback without C.confirmDialog.
		$this->assertMatchesRegularExpression(
			'/requireTypedConfirm: !!inboxSent[\s\S]{0,500}typedConfirmPhrase: \'SHARE\'/',
			$js,
		);
		$this->assertMatchesRegularExpression(
			'/!\(C && C\.confirmDialog\)[\s\S]{0,200}window\.prompt\(/',
			$js,
		);
	}

	public function testSalesInboxReconnectDeepLinksMyEmail(): void
	{
		$js = (string)file_get_contents($this->root . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString('#crm-ws-personal-mail-mine', $js);
		$this->assertStringContainsString('/settings/my-email', $js);
		$this->assertStringContainsString('needs a quick reconnect', $js);
		$settings = (string)file_get_contents($this->root . '/js/pages/settings.js');
		$this->assertStringContainsString('__crmFocusPersonalMailMine', $settings);
		$this->assertStringContainsString('crm-pm-dsgvo-wrap', $settings);
	}

	public function testCssGiantPersonalMailTargets(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('#crm-personal-mail-oauth .crm-btn', $css);
		$this->assertStringContainsString('min-height: 52px', $css);
		$this->assertStringContainsString('.crm-personal-mail-imap__body', $css);
		$this->assertStringContainsString('.crm-personal-mail-servers', $css);
		$this->assertStringContainsString('.crm-rich-mail__tool', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-rich-mail__tool\s*\{[^}]*min-height:\s*44px/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-personal-mail-folder \.crm-check\s*\{[^}]*min-height:\s*44px/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-personal-mail-servers\s*\{[^}]*border-top:\s*1px solid/',
			$css,
		);
	}
}
