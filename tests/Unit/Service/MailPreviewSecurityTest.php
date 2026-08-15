<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\MailboxService;
use OCA\CustomerCheck\Service\MailboxAuthService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\RateLimitService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Argus security + Zeus architecture regressions — mail preview / IMAP lock.
 */
final class MailPreviewSecurityTest extends TestCase
{
	public function testPreviewRateLimitBlocksImapDial(): void
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
			'preview' => '',
			'triageStatus' => 'open',
		]);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())
			->method('acquireLock')
			->with('crm_mbox_3', ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects($this->once())
			->method('releaseLock')
			->with('crm_mbox_3', ILockingProvider::LOCK_EXCLUSIVE);
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->once())
			->method('allow')
			->with('mail_preview_u:alex', Constants::MAIL_PREVIEW_USER_LIMIT, Constants::MAIL_PREVIEW_USER_WINDOW, false)
			->willReturn(false);

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$locks,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$rate,
			$this->createMock(MailboxAuthService::class),
		);

		try {
			$svc->previewIndexedMessage(1, 9, 'alex');
			$this->fail('Expected rate-limit ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_RATE_LIMITED, $e->getErrorCode());
		}
	}

	public function testUncachedPreviewAcquiresMailboxLock(): void
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
			'preview' => '',
			'triageStatus' => 'open',
		]);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())
			->method('acquireLock')
			->with('crm_mbox_3', ILockingProvider::LOCK_EXCLUSIVE)
			->willThrowException(new \RuntimeException('held'));
		$locks->expects($this->never())->method('releaseLock');
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$locks,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$rate,
			$this->createMock(MailboxAuthService::class),
		);

		try {
			$svc->previewIndexedMessage(1, 9, 'alex');
			$this->fail('Expected lock contention ValidationException');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('This mailbox is updating', $e->getMessage());
		}
	}

	public function testLockBusyDoesNotBurnRateLimitTokens(): void
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
			'preview' => '',
			'triageStatus' => 'open',
		]);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new \RuntimeException('busy'));
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$locks,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$rate,
			$this->createMock(MailboxAuthService::class),
		);

		$this->expectException(ValidationException::class);
		$svc->previewIndexedMessage(1, 9, 'alex');
	}

	public function testStampedeCacheHitUnderLockSkipsRateLimit(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->expects($this->exactly(2))
			->method('findMessageForPreview')
			->willReturnOnConsecutiveCalls(
				[
					'id' => 9,
					'mailboxId' => 3,
					'folderId' => 1,
					'folderPath' => 'INBOX',
					'messageUid' => '42',
					'subject' => 'Hi',
					'from' => 'a@b.c',
					'preview' => '',
					'triageStatus' => 'open',
				],
				[
					'id' => 9,
					'mailboxId' => 3,
					'folderId' => 1,
					'folderPath' => 'INBOX',
					'messageUid' => '42',
					'subject' => 'Hi',
					'from' => 'a@b.c',
					'preview' => 'Filled by peer',
					'triageStatus' => 'open',
				],
			);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('acquireLock');
		$locks->expects($this->once())->method('releaseLock');
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$locks,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$rate,
			$this->createMock(MailboxAuthService::class),
		);

		$out = $svc->previewIndexedMessage(1, 9, 'alex');
		$this->assertTrue($out['cached']);
		$this->assertSame('Filled by peer', $out['preview']);
	}

	public function testEmptyActorRejectedBeforeImap(): void
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
			'preview' => '',
			'triageStatus' => 'open',
		]);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->never())->method('acquireLock');
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$locks,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$rate,
			$this->createMock(MailboxAuthService::class),
		);

		try {
			$svc->previewIndexedMessage(1, 9, '');
			$this->fail('Expected empty-actor ValidationException');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('Sign in', $e->getMessage());
		}
	}

	public function testReplyComposeUsesCachedPreviewWithoutImapLock(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 9,
			'mailboxId' => 3,
			'folderId' => 1,
			'folderPath' => 'INBOX',
			'messageUid' => '42',
			'subject' => 'Hello',
			'from' => 'Ada <ada@acme.example>',
			'preview' => 'Body line',
			'triageStatus' => 'open',
			'visibility' => 'shared',
			'ownerUserId' => 'alex',
			'companyId' => null,
			'mailboxKind' => 'team',
		]);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->never())->method('acquireLock');
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$locks,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$rate,
			$this->createMock(MailboxAuthService::class),
		);

		$out = $svc->replyComposeIndexedMessage(1, 9, 'alex', true, true);
		$this->assertTrue($out['available']);
		$this->assertSame('ada@acme.example', $out['to']);
		$this->assertSame('Re: Hello', $out['subject']);
		$this->assertStringStartsWith('mailto:', $out['mailtoUrl']);
		$this->assertStringContainsString('> Body line', $out['bodyText']);
		$this->assertSame(3, $out['mailboxId']);
		$this->assertArrayNotHasKey('companyId', $out);
		$this->assertSame('shared', $out['visibility']);
		$this->assertSame('team', $out['mailboxKind']);
	}

	public function testReplyComposeIncludesMatchedCompanyId(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 9,
			'mailboxId' => 3,
			'folderId' => 1,
			'folderPath' => 'INBOX',
			'messageUid' => '42',
			'subject' => 'Hello',
			'from' => 'Ada <ada@acme.example>',
			'preview' => 'Body line',
			'triageStatus' => 'matched',
			'visibility' => 'shared',
			'ownerUserId' => 'alex',
			'companyId' => 42,
			'mailboxKind' => 'team',
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

		$out = $svc->replyComposeIndexedMessage(1, 9, 'alex', true, true);
		$this->assertTrue($out['available']);
		$this->assertSame(42, $out['companyId']);
		$this->assertSame(3, $out['mailboxId']);
	}

	public function testReplyComposeDeniesPrivatePersonalForTeammate(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 9,
			'mailboxId' => 3,
			'folderId' => 1,
			'folderPath' => 'INBOX',
			'messageUid' => '42',
			'subject' => 'Secret',
			'from' => 'Ada <ada@acme.example>',
			'preview' => 'Private body',
			'triageStatus' => 'open',
			'visibility' => 'private',
			'ownerUserId' => 'alex',
			'companyId' => null,
			'mailboxKind' => 'personal',
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
		$svc->replyComposeIndexedMessage(1, 9, 'teammate', true, true);
	}

	public function testCachedPreviewIncludesReplyPack(): void
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
			'preview' => 'Cached body',
			'triageStatus' => 'open',
			'visibility' => 'shared',
			'ownerUserId' => 'alex',
			'companyId' => 1,
			'mailboxKind' => 'team',
		]);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->never())->method('acquireLock');
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$locks,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$rate,
			$this->createMock(MailboxAuthService::class),
		);

		$out = $svc->previewIndexedMessage(1, 9, 'alex');
		$this->assertTrue($out['cached']);
		$this->assertArrayHasKey('reply', $out);
		$this->assertTrue($out['reply']['available']);
		$this->assertSame('a@b.c', $out['reply']['to']);
	}

	public function testCachedPreviewWithInvalidUtf8StillJsonEncodes(): void
	{
		$mailboxes = $this->createMock(MailboxService::class);
		$mailboxes->method('findMessageForPreview')->willReturn([
			'id' => 9,
			'mailboxId' => 3,
			'folderId' => 1,
			'folderPath' => 'INBOX',
			'messageUid' => '42',
			'subject' => "Betreff \xB1",
			'from' => 'a@b.c',
			'preview' => "Body with \xB1\xB2 garbage",
			'triageStatus' => 'open',
		]);
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->never())->method('acquireLock');
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('allow');

		$svc = new MailboxSyncService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ICrypto::class),
			$mailboxes,
			$this->createMock(WorkspaceService::class),
			$this->createMock(NotificationService::class),
			$locks,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$rate,
			$this->createMock(MailboxAuthService::class),
		);

		$out = $svc->previewIndexedMessage(1, 9, 'alex');
		$this->assertTrue(mb_check_encoding($out['preview'], 'UTF-8'));
		$this->assertTrue(mb_check_encoding($out['subject'], 'UTF-8'));
		$json = json_encode(['success' => true, 'data' => $out], JSON_THROW_ON_ERROR | JSON_HEX_TAG);
		$this->assertIsString($json);
		$this->assertStringContainsString('Body with', $out['preview']);
	}

	public function testApiResponsesUseUtf8SubstituteEncodeFlag(): void
	{
		$trait = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiControllerTrait.php');
		$this->assertSame(
			2,
			substr_count($trait, 'JSON_INVALID_UTF8_SUBSTITUTE'),
			'ok() and fail() must both pass JSON_INVALID_UTF8_SUBSTITUTE to JSONResponse',
		);
		$okPos = strpos($trait, 'function ok(');
		$failPos = strpos($trait, 'function fail(');
		$fromThrowablePos = strpos($trait, 'function fromThrowable(');
		$this->assertNotFalse($okPos);
		$this->assertNotFalse($failPos);
		$this->assertNotFalse($fromThrowablePos);
		$okSlice = substr($trait, $okPos, $failPos - $okPos);
		$failSlice = substr($trait, $failPos, $fromThrowablePos - $failPos);
		$this->assertStringContainsString('JSON_INVALID_UTF8_SUBSTITUTE', $okSlice);
		$this->assertStringContainsString('JSON_INVALID_UTF8_SUBSTITUTE', $failSlice);
		$sync = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('MailPreviewText::ensureUtf8', $sync);
		$this->assertStringContainsString("\$payload['preview'] = MailPreviewText::toPlainPreview((string)(\$payload['preview'] ?? ''));", $sync);
		$previewText = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Support/MailPreviewText.php');
		$this->assertStringContainsString('function ensureUtf8', $previewText);
		$this->assertStringContainsString('function stripMimeDebris', $previewText);
		$this->assertStringContainsString('UTF-8//IGNORE', $previewText);
	}

	public function testArchitecturePreviewSharesSyncLockKey(): void
	{
		$sync = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString("\$lockKey = 'crm_mbox_' . \$mailboxId;", $sync);
		$this->assertGreaterThanOrEqual(
			3,
			substr_count($sync, "\$lockKey = 'crm_mbox_' . \$mailboxId;"),
			'preview, sync, and indexed delete must share crm_mbox_* lock key',
		);
		$this->assertStringNotContainsString('crm_mail_del_', $sync);
		$deleteStart = strpos($sync, 'function deleteIndexedMessage(');
		$this->assertNotFalse($deleteStart);
		$deleteChunk = substr($sync, (int)$deleteStart, 5500);
		$this->assertStringContainsString('assertMailMutateRateAllowed', $deleteChunk);
		$this->assertStringContainsString("\$lockKey = 'crm_mbox_' . \$mailboxId;", $deleteChunk);
		$this->assertStringContainsString('This mailbox is updating', $deleteChunk);
		$this->assertStringContainsString('Double-check cache under lock', $sync);
		$this->assertStringContainsString('Rate limits run *after* lock', $sync);
		$this->assertStringContainsString('Sign in to load a message preview.', $sync);
		$this->assertStringContainsString('This mailbox is updating', $sync);
		$this->assertStringContainsString('Peek only — charge after a successful fetch', $sync);
		$this->assertStringContainsString('Charge only after a successful fetch', $sync);
		$this->assertStringContainsString('Open the target folder directly', $sync);
		$this->assertStringContainsString('Part 1 only — never imap_body()', $sync);
		$this->assertStringNotContainsString('@imap_body', $sync);
		$this->assertStringNotContainsString('imap_body($', $sync);
		$this->assertStringContainsString('This mailbox uses STARTTLS. Install php-imap', $sync);
		$this->assertStringContainsString("\$probe['reason'] === 'auth'", $sync);
		$this->assertStringContainsString('recordAuthFailure', $sync);
	}

	public function testCompanyFeedContractDoesNotEmbedPreviewBody(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/CompanyCommandService.php');
		$this->assertStringContainsString("'preview' => null", $src);
		$this->assertStringContainsString('Never embed cached body text in list/feed payloads', $src);
		$mbox = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxService.php');
		$this->assertStringNotContainsString("'preview' => \$preview !== '' ? mb_substr(\$preview, 0, 800) : null", $mbox);
		$sync = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('Could not load message preview from the mailbox.', $sync);
		$this->assertStringNotContainsString('Could not load message preview: %s', $sync);
		$this->assertStringContainsString('isBlockedMailHost', $sync);
		$this->assertStringContainsString('MAIL_PREVIEW_USER_LIMIT', $sync);
		$this->assertStringContainsString('Use a public mail host', $sync);
		$doSyncStart = strpos($sync, 'function doSync(int $mailboxId, int $workspaceId): array');
		$this->assertNotFalse($doSyncStart);
		$doSync = substr($sync, $doSyncStart, 900);
		$this->assertStringContainsString("eq('workspace_id', \$qb->createNamedParameter(\$workspaceId))", $doSync);
		$this->assertStringContainsString("eq('id', \$qb->createNamedParameter(\$mailboxId))", $doSync);
		$this->assertStringContainsString('isSafeImapFolder', $sync);
		$send = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('isBlockedMailHost($smtpHost)', $send);
		$smtp = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Support/SharedMailboxSmtpClient.php');
		$this->assertStringContainsString('isBlockedMailHost($host)', $smtp);
		$companyJs = (string)file_get_contents(dirname(__DIR__, 3) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('Never seed body text from list/feed JSON', $companyJs);
		$this->assertStringNotContainsString('if (item.preview)', $companyJs);
	}

	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s): string => $s);

		return $l;
	}
}
