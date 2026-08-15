<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Controller\ApiControllerTrait;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\MailAclLogic;
use OCA\CustomerCheck\Service\MailboxAuthService;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\PersonalMailService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * FAC / Absolute No-No contracts for CUC-PERSONAL-MAIL-1 (1.6.94).
 */
final class PersonalMailFacNnContractTest extends TestCase
{
	public function testFac01PersonalMailDisabledMapsToForbidden(): void
	{
		$ctrl = new class {
			use ApiControllerTrait;

			public function expose(\Throwable $e): JSONResponse
			{
				return $this->fromThrowable($e);
			}

			protected function fail(string $code, string $message, array $fields = [], int $status = 400): JSONResponse
			{
				return new JSONResponse(['ok' => false, 'error' => $code, 'message' => $message, 'fields' => $fields], $status);
			}

			protected function ok(array $data): JSONResponse
			{
				return new JSONResponse(['ok' => true, 'data' => $data], 200);
			}
		};
		$res = $ctrl->expose(ValidationException::field(
			PersonalMailService::ERR_PERSONAL_MAIL_DISABLED,
			'personalMail',
			'disabled',
		));
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		$this->assertSame(PersonalMailService::ERR_PERSONAL_MAIL_DISABLED, $res->getData()['error']);
	}

	public function testFac19AccountConflictMapsToConflict(): void
	{
		$ctrl = new class {
			use ApiControllerTrait;

			public function expose(\Throwable $e): JSONResponse
			{
				return $this->fromThrowable($e);
			}

			protected function fail(string $code, string $message, array $fields = [], int $status = 400): JSONResponse
			{
				return new JSONResponse(['ok' => false, 'error' => $code, 'message' => $message, 'fields' => $fields], $status);
			}

			protected function ok(array $data): JSONResponse
			{
				return new JSONResponse(['ok' => true, 'data' => $data], 200);
			}
		};
		$res = $ctrl->expose(ValidationException::field(
			PersonalMailService::ERR_MAIL_ACCOUNT_CONFLICT,
			'emailAddress',
			'conflict',
		));
		$this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
	}

	public function testNn06DoSyncSkipsWhenPersonalMailDisabled(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$qb = $this->getMockBuilder(\stdClass::class)
			->addMethods(['select', 'from', 'where', 'andWhere', 'setMaxResults', 'executeQuery', 'createNamedParameter', 'expr'])
			->getMock();
		$expr = $this->getMockBuilder(\stdClass::class)->addMethods(['eq'])->getMock();
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$result = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch', 'closeCursor'])->getMock();
		$result->method('fetch')->willReturn([
			'id' => 9,
			'workspace_id' => 1,
			'kind' => MailAclLogic::KIND_PERSONAL,
			'imap_host' => 'imap.example.com',
			'imap_port' => 993,
			'imap_enc' => 'ssl',
			'username' => 'u',
			'secret_enc' => 'enc',
			'auth_kind' => 'imap',
			'auth_fail_count' => 0,
		]);
		$qb->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($qb);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->method('getSettings')->willReturn(['personalMailEnabled' => false]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'personal_mail.sync_skip',
			$this->callback(static fn (array $ctx): bool => ($ctx['reason'] ?? '') === 'disabled'),
		);

		$svc = new MailboxSyncService(
			$db,
			$this->createMock(ICrypto::class),
			$this->createMock(MailboxService::class),
			$workspaces,
			$this->createMock(NotificationService::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			$logger,
			$this->l10n(),
			$this->createMock(RateLimitService::class),
			$this->createMock(MailboxAuthService::class),
		);

		$ref = new \ReflectionClass($svc);
		$method = $ref->getMethod('doSync');
		$method->setAccessible(true);
		$out = $method->invoke($svc, 9, 1);
		$this->assertSame('disabled', $out['status']);
		$this->assertSame(0, $out['ingested']);
	}

	public function testFac04PreviewDoesNotInventCompanyReadFromLinkage(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 9,
			'mailboxId' => 3,
			'folderId' => 1,
			'folderPath' => 'INBOX',
			'messageUid' => '42',
			'subject' => 'Hi',
			'from' => 'a@b.c',
			'preview' => 'cached body',
			'triageStatus' => 'matched',
			'visibility' => MailAclLogic::VISIBILITY_SHARED,
			'ownerUserId' => 'owner',
			'companyId' => 77,
		]);

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$this->createMock(RateLimitService::class),
			$this->createMock(MailboxAuthService::class),
		);

		$this->expectException(NotFoundException::class);
		// canReadCompany=false even though companyId is set (FAC-04).
		$svc->previewIndexedMessage(1, 9, 'teammate', false, true);
	}

	public function testFac02PrivatePreviewDeniedForNonOwner(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 9,
			'mailboxId' => 3,
			'folderId' => 1,
			'folderPath' => 'INBOX',
			'messageUid' => '42',
			'subject' => 'Hi',
			'from' => 'a@b.c',
			'preview' => 'secret',
			'triageStatus' => 'open',
			'visibility' => MailAclLogic::VISIBILITY_PRIVATE,
			'ownerUserId' => 'owner',
			'companyId' => null,
		]);

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$this->createMock(RateLimitService::class),
			$this->createMock(MailboxAuthService::class),
		);

		$this->expectException(NotFoundException::class);
		$svc->previewIndexedMessage(1, 9, 'teammate', true, true);
	}

	public function testFac03SharedLinkedReadableWithCompanyRead(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 9,
			'mailboxId' => 3,
			'folderId' => 1,
			'folderPath' => 'INBOX',
			'messageUid' => '42',
			'subject' => 'Hi',
			'from' => 'a@b.c',
			'preview' => 'ok body',
			'triageStatus' => 'matched',
			'visibility' => MailAclLogic::VISIBILITY_SHARED,
			'ownerUserId' => 'owner',
			'companyId' => 77,
		]);

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$this->createMock(RateLimitService::class),
			$this->createMock(MailboxAuthService::class),
		);

		$out = $svc->previewIndexedMessage(1, 9, 'teammate', true, true);
		$this->assertSame('ok body', $out['preview']);
		$this->assertTrue($out['cached']);
	}

	public function testFac16PreviewForwardsBodyHtmlFromFindMessage(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 9,
			'mailboxId' => 3,
			'folderId' => 1,
			'folderPath' => 'INBOX',
			'messageUid' => '1',
			'subject' => 'Hi',
			'from' => 'a@b.c',
			'preview' => 'plain body',
			// findMessageForPreview already re-sanitizes — sync layer must forward that value.
			'bodyHtml' => '<p>safe</p>',
			'triageStatus' => 'open',
			'visibility' => MailAclLogic::VISIBILITY_SHARED,
			'ownerUserId' => 'alex',
			'companyId' => null,
		]);
		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(ILockingProvider::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$this->createMock(RateLimitService::class),
			$this->createMock(MailboxAuthService::class),
		);
		$out = $svc->previewIndexedMessage(1, 9, 'alex', false, true);
		$this->assertSame('plain body', $out['preview']);
		$this->assertSame('<p>safe</p>', $out['bodyHtml']);
	}

	public function testFac16SalesInboxAndTalkRenderServerBodyHtmlContract(): void
	{
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/pages/sales-inbox.js');
		$talk = file_get_contents(dirname(__DIR__, 3) . '/js/pages/company-detail.js');
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/sales-inbox.php');
		$mbox = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertIsString($js);
		$this->assertIsString($talk);
		$this->assertIsString($tpl);
		$this->assertIsString($mbox);
		$this->assertStringContainsString("bodyEl.innerHTML = bodyHtml", $js);
		$this->assertStringContainsString("bodyEl.innerHTML = bodyHtml", $talk);
		$this->assertStringContainsString('FAC-16', $talk);
		$this->assertStringContainsString('crm-mail-preview__body--html', $js);
		$this->assertStringContainsString('role="article"', $tpl);
		$this->assertStringContainsString('htmlSanitize->sanitize(trim', $mbox);
		$this->assertStringContainsString("\$row['body_html']", $mbox);
	}

	public function testSignatureRichEditorWiringContract(): void
	{
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/pages/settings.js');
		$myEmail = file_get_contents(dirname(__DIR__, 3) . '/templates/settings/my-email.php');
		$team = file_get_contents(dirname(__DIR__, 3) . '/templates/settings/workspace-mailbox.php');
		$this->assertIsString($js);
		$this->assertIsString($myEmail);
		$this->assertIsString($team);
		$this->assertStringContainsString('mountSignatureEditor', $js);
		$this->assertStringContainsString('signatureHtml', $js);
		$this->assertStringContainsString('crm-personal-mail-signature-editor', $myEmail);
		$this->assertStringContainsString('crm-mbox-signature-editor', $team);
	}

	public function testMobilePersonalMailMutationsRouted(): void
	{
		$routes = file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$this->assertIsString($routes);
		$this->assertStringContainsString('mobile_mail#connectPersonalMailbox', $routes);
		$this->assertStringContainsString('mobile_mail#listMailAttachFiles', $routes);
		$this->assertStringContainsString('mobile_mail#disconnectPersonalMailbox', $routes);
	}

	public function testSalesInboxAttachUiContract(): void
	{
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/sales-inbox.php');
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/pages/sales-inbox.js');
		$this->assertIsString($tpl);
		$this->assertIsString($js);
		$this->assertStringContainsString('crm-sales-compose-attach-add', $tpl);
		$this->assertStringContainsString('attachmentFileIds', $js);
		$this->assertStringContainsString('CustomerCheckFolderPicker', $js);
	}

	public function testNn06GateNeedlePresentInDoSync(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertIsString($src);
		$this->assertStringContainsString("empty(\$settings['personalMailEnabled'])", $src);
		$this->assertStringContainsString("'status' => 'disabled'", $src);
	}

	public function testShareDisclosureRequiresCheckboxContract(): void
	{
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/pages/settings.js');
		$this->assertIsString($js);
		$this->assertStringContainsString('requireCheckbox: true', $js);
		$this->assertStringContainsString(
			'I understand these emails will be visible to teammates who can open the linked customer.',
			$js,
		);
	}

	public function testFolderSaveUsesMailboxSyncLockNeedle(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/PersonalMailService.php');
		$this->assertIsString($src);
		$this->assertStringContainsString("crm_mbox_' . \$mailboxId", $src);
		$this->assertStringContainsString('saveFoldersLocked', $src);
	}

	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);

		return $l;
	}
}
