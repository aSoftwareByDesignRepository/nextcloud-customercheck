<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\HtmlSanitizeService;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Company Talk / Review Answer → in-app send contracts (security + UX wiring).
 */
final class TalkAnswerSendContractTest extends TestCase
{
	public function testReplyPackExposesMailboxCompanyAndPrivacyMeta(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString("\$pack['mailboxId'] = (int)\$msg['mailboxId'];", $src);
		$this->assertStringContainsString("\$pack['companyId'] = \$companyId;", $src);
		$this->assertStringContainsString("\$pack['visibility'] = (string)\$msg['visibility'];", $src);
		$this->assertStringContainsString("\$pack['mailboxKind'] = (string)\$msg['mailboxKind'];", $src);
		$this->assertStringContainsString('MailPreviewText::ensureUtf8', $src);
		$this->assertStringContainsString('in-app send', $src);
	}

	public function testPersonalSendUsesPersonalDisclosure(): void
	{
		$mailboxSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('Sent from your personal email %1$s', $mailboxSrc);
		$this->assertStringContainsString('Everyone in workspace %2$s who can read mail can see them.', $mailboxSrc);
		$this->assertStringContainsString('string $kind = MailAclLogic::KIND_TEAM', $mailboxSrc);

		$sendSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString(
			"\$disclosure = \$this->mailboxes->disclosure((string)\$box['label'], \$workspaceName, \$boxKind);",
			$sendSrc,
		);
		$this->assertStringContainsString("'disclosure' => \$disclosure", $sendSrc);
		// Disclosure stays in-app / API — never appended to the SMTP body customers receive.
		$this->assertStringNotContainsString('$fullBody = $body . "\n\n—\n" . $disclosure;', $sendSrc);
		$this->assertStringContainsString('Disclosure is for in-app UI / API only', $sendSrc);
		$this->assertStringNotContainsString(
			"\$disclosure = \$this->mailboxes->disclosure((string)\$box['label'], \$workspaceName);",
			$sendSrc,
		);
	}

	public function testDisclosureCopyDiffersByKind(): void
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $text, array $args = []): string {
			$out = $text;
			if (isset($args[0])) {
				$out = str_replace('%1$s', (string)$args[0], $out);
			}
			if (isset($args[1])) {
				$out = str_replace('%2$s', (string)$args[1], $out);
			}
			return $out;
		});
		$svc = new MailboxService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			new HtmlSanitizeService(),
		);
		$personal = $svc->disclosure('me@example.com', 'Acme', MailAclLogic::KIND_PERSONAL);
		$team = $svc->disclosure('sales@example.com', 'Acme', MailAclLogic::KIND_TEAM);
		$this->assertStringContainsString('personal email', $personal);
		$this->assertStringContainsString('me@example.com', $personal);
		$this->assertStringNotContainsString('Everyone in workspace', $personal);
		$this->assertStringContainsString('shared mailbox', $team);
		$this->assertStringContainsString('Everyone in workspace', $team);
	}

	public function testSharedMailOfferPreparedMailHardened(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/shared-mail-send.js');
		$this->assertMatchesRegularExpression('/function queryInDialog\s*\(/', $js);
		$this->assertStringContainsString('crm-prepared-mail', $js);
		$this->assertStringContainsString('Write your reply here', $js);
		$this->assertStringContainsString('sourceVisibility', $js);
		$this->assertStringContainsString('sourceMailboxKind', $js);
		$this->assertMatchesRegularExpression('/\belevatingPrivate\b/', $js);
		$this->assertStringContainsString('This reply quotes private personal mail', $js);
		$this->assertStringContainsString("t('This sends as %s. Everyone on the team who can read mail can see it.', [label])", $js);
		$this->assertStringContainsString('/%(?:\\d+\\$)?s/g', $js);
		$this->assertStringContainsString('C.closeModal(dialogRoot(dialogId))', $js);
		$this->assertStringContainsString('sendInFlight', $js);
		$this->assertStringContainsString('preferredInAccounts', $js);
		$this->assertStringContainsString("t('Cannot answer')", $js);
		$this->assertStringContainsString('This message has no usable reply address.', $js);
		$this->assertStringNotContainsString(', [data-crm-shared-send]', $js);
		$this->assertStringNotContainsString(', [data-crm-attach-file]', $js);
		$this->assertStringContainsString("queryInDialog(dialogId, '[data-crm-shared-send]')", $js);
		$this->assertStringContainsString("queryInDialog(dialogId, '[data-crm-attach-file]')", $js);
		$this->assertStringContainsString('aria-live="polite"', $js);
		$this->assertStringContainsString('includeSignatureDefault: true', $js);
		$rich = (string)file_get_contents(dirname(__DIR__, 3) . '/js/common/rich-mail-composer.js');
		$this->assertDoesNotMatchRegularExpression('/if\s*\(\s*!hasSig\s*\)\s*return false/', $rich);
		$this->assertMatchesRegularExpression('/if\s*\(\s*!sigBox\s*\)\s*\{\s*return true;\s*\}/', $rich);
		$send = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('HTML-only signatures', $send);
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/app.css');
		$this->assertStringContainsString('.crm-prepared-mail', $css);
		$this->assertStringContainsString('min-height: 44px', $css);
	}

	public function testTalkAndReviewAnswerWireOfferPreparedMail(): void
	{
		$company = (string)file_get_contents(dirname(__DIR__, 3) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('offerPreparedMail', $company);
		$this->assertStringContainsString('replyMode: true', $company);
		$this->assertStringContainsString('pack.companyId', $company);

		$persona = (string)file_get_contents(dirname(__DIR__, 3) . '/js/pages/persona.js');
		$this->assertStringContainsString('offerPreparedMail', $persona);
		$this->assertStringContainsString('replyMode: true', $persona);
		$this->assertStringContainsString("wrap.getAttribute('data-company-id')", $persona);
		$this->assertStringContainsString('data-company-id="', $persona);
		$this->assertStringContainsString('Reply from your connected personal or team email', $persona);

		$page = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		// Review/Talk Answer mounts on persona shells; prepared-mail assets load via company-detail + sales-inbox.
		$this->assertStringContainsString(
			"registerFrontEndAssets('pages/persona', null, [], ['common/mail-sync-now'])",
			$page,
		);
		$this->assertStringContainsString('common/rich-mail-composer', $page);
		$this->assertStringContainsString('common/shared-mail-send', $page);
		$this->assertStringContainsString('common/folder-picker', $page);
	}

	public function testReplyMailControllerDocAllowsInAppSend(): void
	{
		$ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('in-app send', $ctrl);
		$this->assertStringNotContainsString('no send from CRM', $ctrl);
	}
}
