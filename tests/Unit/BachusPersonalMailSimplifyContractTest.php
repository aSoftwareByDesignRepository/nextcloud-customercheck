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
		$this->assertMatchesRegularExpression(
			'/function schedulePersonalFoldersAutoSave\(\) \{\s*personalFoldersSaveDirty = true;/',
			$js,
		);
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
		// Typed SHARE lives in confirmDialog; without it the flow fails closed —
		// no window.prompt/confirm fallback (native_browser_dialog defect class).
		$this->assertMatchesRegularExpression(
			'/requireTypedConfirm: !!inboxSent[\s\S]{0,500}typedConfirmPhrase: \'SHARE\'/',
			$js,
		);
		$this->assertDoesNotMatchRegularExpression('/window\.prompt\(|window\.confirm\(|window\.alert\(/', $js);
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
		$this->assertStringContainsString('min-height: var(--crm-touch-lg)', $css);
		$this->assertStringContainsString('.crm-personal-mail-imap__body', $css);
		$this->assertStringContainsString('.crm-personal-mail-servers', $css);
		$this->assertStringContainsString('.crm-rich-mail__tool', $css);
		$this->assertMatchesRegularExpression(
			'/\.crm-rich-mail__tool\s*\{[^}]*min-height:\s*var\(--crm-touch\)/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-personal-mail-folder \.crm-check\s*\{[^}]*min-height:\s*var\(--crm-touch\)/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/\.crm-personal-mail-servers\s*\{[^}]*border-top:\s*1px solid/',
			$css,
		);
		// Responsiveness: NC mobile ≤768px stacks My email actions; desktop base unchanged.
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:\s*768\.98px\) \{\s*\n\s*#app-content\.crm-app \.crm-personal-mail-connected-actions/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:\s*768\.98px\) \{\s*\n\s*#app-content\.crm-app \.crm-personal-mail-folder \{[\s\S]*?\* 0\.85rem/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:\s*768\.98px\) \{\s*\n\s*#app-content\.crm-app \.crm-sales-inbox__toolbar[\s\S]*?crm-sales-inbox__actions-bar/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:\s*1023\.98px\) \{\s*\n\s*#app-content\.crm-app \.crm-personal-mail-folders/',
			$css,
		);
		$this->assertStringContainsString('var(--crm-muted)', $css);
		$this->assertStringContainsString('var(--crm-bg-card)', $css);
		$mailBlockStart = strpos($css, '/* Sales Inbox + personal mail settings');
		$mailBlockEnd = strpos($css, '/* Sales Inbox: full-width list');
		$this->assertNotFalse($mailBlockStart);
		$this->assertNotFalse($mailBlockEnd);
		$mailBlock = substr($css, $mailBlockStart, $mailBlockEnd - $mailBlockStart);
		$this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}/', $mailBlock);
	}

	public function testResponsiveDesktopSacredDoesNotStackInBaseRules(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		// Desktop sacred: base toolbar stays row flex — column only inside ≤768 MQ.
		$this->assertMatchesRegularExpression(
			'/#app-content\.crm-app \.crm-sales-inbox__toolbar \{\s*\n\s*display:\s*flex;\s*\n\s*flex-wrap:\s*wrap;/',
			$css,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/#app-content\.crm-app \.crm-sales-inbox__toolbar \{\s*\n\s*display:\s*flex;\s*\n\s*flex-direction:\s*column/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:\s*768\.98px\) \{[\s\S]*?\.crm-sales-inbox__toolbar \{\s*\n\s*flex-direction:\s*column;/',
			$css,
		);
		// Connected actions: base never forces column; mobile MQ does.
		$this->assertMatchesRegularExpression(
			'/#app-content\.crm-app \.crm-personal-mail-connected-actions \{\s*\n\s*margin-top:[^}]*flex-wrap:\s*wrap;/',
			$css,
		);
		$this->assertDoesNotMatchRegularExpression(
			'/#app-content\.crm-app \.crm-personal-mail-connected-actions \{\s*\n\s*margin-top:[^}]*flex-direction:\s*column/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:\s*768\.98px\) \{[\s\S]*?\.crm-personal-mail-connected-actions,[\s\S]*?flex-direction:\s*column;/',
			$css,
		);
		// Folder depth multiplier stays 1.75rem on desktop; 0.85rem only under ≤768.
		$this->assertMatchesRegularExpression(
			'/var\(--crm-folder-depth,\s*0\) \* 1\.75rem/',
			$css,
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:\s*768\.98px\) \{[\s\S]*?var\(--crm-folder-depth,\s*0\) \* 0\.85rem/',
			$css,
		);
	}
}
