<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\ImapConnection;
use OCA\CustomerCheck\Support\ImapLiveProbe;
use OCA\CustomerCheck\Support\MailPreviewText;
use OCA\CustomerCheck\Support\MailtoReplyBuilder;
use OCA\CustomerCheck\Support\MimeHeader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Shared mailbox IMAP sync — product §14.1 / §17.4.
 *
 * Bounded messages/run; advisory lock per mailbox; auth fail ×3 → error.
 * Personal IMAP is never ingested — only workspace-owned rows in crm_mailbox.
 */
class MailboxSyncService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ICrypto $crypto,
		private readonly MailboxService $mailboxes,
		private readonly WorkspaceService $workspaces,
		private readonly NotificationService $notifications,
		private readonly ILockingProvider $locks,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly RateLimitService $rateLimit,
		private readonly ?FacadeProbeService $facades = null,
	) {
	}

	/**
	 * Delete one indexed shared-mailbox message on the IMAP server (spam / junk).
	 * Idempotent when the UID is already gone. Does not change CRM triage by itself —
	 * callers (Review discard) close the CRM copy after a successful dial.
	 *
	 * Prefers php-imap when available; falls back to ImapLiveProbe (same path as sync
	 * on Docker/dev hosts without ext-imap).
	 *
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function deleteIndexedMessage(int $workspaceId, int $messageId): void
	{
		$msg = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
		if ($msg === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$mailboxId = (int)$msg['mailboxId'];
		$folder = trim((string)$msg['folderPath']) !== '' ? trim((string)$msg['folderPath']) : 'INBOX';
		$uidRaw = trim((string)$msg['messageUid']);
		if ($mailboxId <= 0 || $uidRaw === '' || !preg_match('/^\d+$/', $uidRaw)) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('This message cannot be deleted from the shared mailbox (missing folder or id).'),
			);
		}
		if (!ImapConnection::isSafeImapFolder($folder)) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('This message cannot be deleted from the shared mailbox (unsafe folder).'),
			);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('Shared mailbox not found'));
		}

		$host = ImapConnection::normalizeHost((string)$row['imap_host']);
		if (ImapConnection::isBlockedMailHost($host)) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Shared mailbox host is blocked.'),
			);
		}
		try {
			$secret = $this->crypto->decrypt((string)$row['secret_enc']);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Shared mailbox password could not be decrypted. Re-save the mailbox credentials in Team & mailbox.'),
			);
		}
		$port = (int)$row['imap_port'];
		$enc = ImapConnection::resolveImapEnc((string)$row['imap_enc'], $port > 0 ? $port : 993);
		$user = ImapConnection::normalizeUsername((string)$row['username']);
		if ($port <= 0) {
			$port = $enc === ImapConnection::ENC_TLS ? 143 : 993;
		}
		if ($user === '' || $secret === '') {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Shared mailbox credentials are incomplete. Re-save them in Team & mailbox.'),
			);
		}

		// ZEUS NG-01: one live IMAP session per mailbox — same key as sync/preview.
		$lockKey = 'crm_mbox_' . $mailboxId;
		try {
			$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Mailbox is busy (sync or another preview). Try again in a moment.'),
			);
		}
		try {
			if (!function_exists('imap_open') || !function_exists('imap_delete') || !function_exists('imap_expunge')) {
				$this->deleteIndexedMessageViaLiveProbe($workspaceId, $mailboxId, $host, $port, $user, $secret, $folder, $uidRaw, $messageId);

				return;
			}

			$mailboxPath = ImapConnection::buildMailboxRoot($host, $port, $enc, false);
			$stream = @imap_open($mailboxPath . $folder, $user, $secret, 0, 3);
			if ($stream === false) {
				$err = ImapConnection::collectErrors(
					function_exists('imap_errors') ? @imap_errors() : null,
					function_exists('imap_last_error') ? @imap_last_error() : null,
				);
				if (ImapConnection::isCertificateError($err) || $err === '' || ImapConnection::classifyError($err) === 'unknown') {
					$mailboxPath = ImapConnection::buildMailboxRoot($host, $port, $enc, true);
					$stream = @imap_open($mailboxPath . $folder, $user, $secret, 0, 3);
				}
			}
			if ($stream === false) {
				// php-imap open failed — last chance via wire probe (same as sync fallback).
				$this->deleteIndexedMessageViaLiveProbe($workspaceId, $mailboxId, $host, $port, $user, $secret, $folder, $uidRaw, $messageId);

				return;
			}
			try {
				$ftUid = defined('FT_UID') ? FT_UID : 1;
				// Already gone on the server → success (idempotent spam cleanup).
				$msgNo = function_exists('imap_msgno') ? @imap_msgno($stream, (int)$uidRaw) : 0;
				if ($msgNo < 1) {
					return;
				}
				$ok = @imap_delete($stream, $uidRaw, $ftUid);
				if ($ok !== true) {
					$err = ImapConnection::collectErrors(
						function_exists('imap_errors') ? @imap_errors() : null,
						function_exists('imap_last_error') ? @imap_last_error() : null,
					);
					$this->logger->warning('CustomerCheck IMAP delete failed', [
						'workspaceId' => $workspaceId,
						'mailboxId' => $mailboxId,
						'messageId' => $messageId,
						'uid' => $uidRaw,
						'error' => $err,
					]);
					throw new ValidationException(
						Constants::ERR_VALIDATION,
						$this->l10n->t('Could not delete this message from the shared mailbox. Try again or delete it in the mail host.'),
					);
				}
				@imap_expunge($stream);
			} finally {
				$clExpunge = defined('CL_EXPUNGE') ? CL_EXPUNGE : 0;
				@imap_close($stream, $clExpunge);
			}
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * @throws ValidationException
	 */
	private function deleteIndexedMessageViaLiveProbe(
		int $workspaceId,
		int $mailboxId,
		string $host,
		int $port,
		string $user,
		string $secret,
		string $folder,
		string $uidRaw,
		int $messageId,
	): void {
		// Live probe speaks IMAP over TLS (993). STARTTLS-only hosts need php-imap.
		if ($port === 143) {
			$port = 993;
		}
		$result = ImapLiveProbe::deleteUid($host, $port, $user, $secret, $folder, $uidRaw, 20.0);
		if (!empty($result['ok'])) {
			return;
		}
		$this->logger->warning('CustomerCheck IMAP live-probe delete failed', [
			'workspaceId' => $workspaceId,
			'mailboxId' => $mailboxId,
			'messageId' => $messageId,
			'uid' => $uidRaw,
			'reason' => (string)($result['reason'] ?? ''),
			'detail' => (string)($result['detail'] ?? ''),
		]);
		$reason = (string)($result['reason'] ?? '');
		if ($reason === 'auth') {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Could not sign in to the shared mailbox to delete this message. Check Team & mailbox.'),
			);
		}
		throw new ValidationException(
			Constants::ERR_VALIDATION,
			$this->l10n->t('Could not delete this message from the shared mailbox. Try again or delete it in the mail host.'),
		);
	}

	/**
	 * Prepare Answer (mailto / Mail app) for an indexed message — no IMAP dial.
	 *
	 * @return array{to:string,subject:string,bodyText:string,mailtoUrl:string,mailAppUrl:?string,available:bool}
	 */
	public function replyComposeIndexedMessage(int $workspaceId, int $messageId): array
	{
		$msg = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
		if ($msg === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$quote = trim((string)($msg['preview'] ?? ''));
		if ($quote === '') {
			$quote = $this->messageSnippet($workspaceId, $messageId);
			// Review sync stores the From line in snippet — not useful as a quote body.
			$asEmail = MailtoReplyBuilder::extractEmail($quote);
			if ($asEmail !== null && strcasecmp($asEmail, $quote) === 0) {
				$quote = '';
			}
		}

		return $this->replyPackFromMessage($msg, $quote);
	}

	/**
	 * @param array{id?:int,subject?:string,from?:string,preview?:string} $msg
	 * @return array{to:string,subject:string,bodyText:string,mailtoUrl:string,mailAppUrl:?string,available:bool}
	 */
	private function replyPackFromMessage(array $msg, string $quote = ''): array
	{
		$from = (string)($msg['from'] ?? '');
		$subject = (string)($msg['subject'] ?? '');
		if ($quote === '') {
			$quote = trim((string)($msg['preview'] ?? ''));
		}
		$pack = MailtoReplyBuilder::build($from, $subject, $quote);
		if ($pack === null) {
			return [
				'to' => '',
				'subject' => MailtoReplyBuilder::replySubject($subject),
				'bodyText' => '',
				'mailtoUrl' => '',
				'mailAppUrl' => null,
				'available' => false,
			];
		}

		$mailAppUrl = null;
		if ($this->facades !== null) {
			try {
				$mailAppUrl = $this->facades->mailComposeUrl($pack['to'], $pack['subject'], $pack['bodyText']);
			} catch (\Throwable) {
				$mailAppUrl = null;
			}
		}

		return [
			'to' => $pack['to'],
			'subject' => $pack['subject'],
			'bodyText' => $pack['bodyText'],
			'mailtoUrl' => $pack['mailtoUrl'],
			'mailAppUrl' => $mailAppUrl,
			'available' => true,
		];
	}

	private function messageSnippet(int $workspaceId, int $messageId): string
	{
		if (!$this->db->tableExists('crm_mail_msg')) {
			return '';
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('snippet')
			->from('crm_mail_msg')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return '';
		}

		return MimeHeader::decode((string)($row['snippet'] ?? ''));
	}

	/**
	 * @param array{id:int,subject:string,from:string,preview:string,cached:bool,truncated:bool} $payload
	 * @param array{id?:int,subject?:string,from?:string,preview?:string} $msg
	 * @return array{id:int,subject:string,from:string,preview:string,cached:bool,truncated:bool,reply:array<string,mixed>}
	 */
	private function withReplyPack(array $payload, array $msg): array
	{
		$payload['reply'] = $this->replyPackFromMessage($msg, (string)($payload['preview'] ?? ''));

		return $payload;
	}

	/**
	 * On-demand plain-text preview for one indexed CRM mail message.
	 * Uses cached crm_mail_msg.preview when present; otherwise fetches via IMAP
	 * (live TLS probe — works without php-imap) and caches the result.
	 *
	 * @return array{id: int, subject: string, from: string, preview: string, cached: bool, truncated: bool, reply: array<string, mixed>}
	 */
	public function previewIndexedMessage(int $workspaceId, int $messageId, string $actorUid = ''): array
	{
		$msg = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
		if ($msg === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$cached = trim((string)($msg['preview'] ?? ''));
		if ($cached !== '') {
			return $this->withReplyPack([
				'id' => (int)$msg['id'],
				'subject' => (string)$msg['subject'],
				'from' => (string)$msg['from'],
				'preview' => $cached,
				'cached' => true,
				'truncated' => str_ends_with($cached, '…'),
			], $msg);
		}

		$actorUid = trim($actorUid);
		// Uncached preview dials IMAP — never allow an anonymous/empty actor to bypass rate limits.
		if ($actorUid === '') {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Sign in to load a message preview.'),
			);
		}

		$uid = trim((string)($msg['messageUid'] ?? ''));
		$mailboxId = (int)($msg['mailboxId'] ?? 0);
		if ($mailboxId <= 0 || $uid === '' || !preg_match('/^\d+$/', $uid)) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('This message cannot be previewed (missing mailbox or UID).'),
			);
		}

		// Same advisory lock as sync/delete — one live IMAP session per mailbox.
		// Rate limits run *after* lock + cache double-check so lock-busy / stampede
		// cache hits do not burn IMAP quota tokens.
		$lockKey = 'crm_mbox_' . $mailboxId;
		try {
			$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'mailbox',
				$this->l10n->t('Mailbox is busy (sync or another preview). Try again in a moment.'),
			);
		}

		try {
			// Double-check cache under lock (stampede / concurrent preview).
			$again = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
			if ($again === null) {
				throw new NotFoundException($this->l10n->t('Message not found'));
			}
			$cachedAgain = trim((string)($again['preview'] ?? ''));
			if ($cachedAgain !== '') {
				return $this->withReplyPack([
					'id' => (int)$again['id'],
					'subject' => (string)$again['subject'],
					'from' => (string)$again['from'],
					'preview' => $cachedAgain,
					'cached' => true,
					'truncated' => str_ends_with($cachedAgain, '…'),
				], $again);
			}

			$userBucket = 'mail_preview_u:' . $actorUid;
			$wsBucket = 'mail_preview_w:' . $workspaceId;
			// Peek only — charge after a successful fetch so flaky IMAP does not burn quota.
			if (!$this->rateLimit->allow($userBucket, Constants::MAIL_PREVIEW_USER_LIMIT, Constants::MAIL_PREVIEW_USER_WINDOW, false)) {
				throw ValidationException::field(
					Constants::ERR_RATE_LIMITED,
					'rate',
					$this->l10n->t('Message preview rate limit reached. Try again later.'),
				);
			}
			if (!$this->rateLimit->allow($wsBucket, Constants::MAIL_PREVIEW_WS_LIMIT, Constants::MAIL_PREVIEW_WS_WINDOW, false)) {
				throw ValidationException::field(
					Constants::ERR_RATE_LIMITED,
					'rate',
					$this->l10n->t('Message preview rate limit reached for this workspace. Try again later.'),
				);
			}

			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('crm_mailbox')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
			$r = $qb->executeQuery();
			$row = $r->fetch();
			$r->closeCursor();
			if ($row === false) {
				throw new NotFoundException($this->l10n->t('Mailbox not found'));
			}

			try {
				$secret = $this->crypto->decrypt((string)$row['secret_enc']);
			} catch (\Throwable) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					$this->l10n->t('Mailbox password could not be unlocked.'),
				);
			}

			$host = ImapConnection::normalizeHost((string)$row['imap_host']);
			if (ImapConnection::isBlockedMailHost($host)) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					$this->l10n->t('This mailbox host cannot be reached from CustomerCheck.'),
				);
			}
			$port = (int)$row['imap_port'];
			$enc = ImapConnection::resolveImapEnc((string)$row['imap_enc'], $port > 0 ? $port : 993);
			$user = ImapConnection::normalizeUsername((string)$row['username']);
			if ($port <= 0) {
				$port = $enc === ImapConnection::ENC_TLS ? 143 : 993;
			}
			$folder = (string)($again['folderPath'] ?? 'INBOX');
			if (!ImapConnection::isSafeImapFolder($folder)) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					$this->l10n->t('This message cannot be previewed (unsafe folder path).'),
				);
			}

			$probe = ['ok' => false, 'reason' => 'unknown', 'detail' => '', 'text' => '', 'truncated' => false];
			$useLiveProbe = ($enc === ImapConnection::ENC_SSL && $port !== 143);
			if ($useLiveProbe) {
				$probe = ImapLiveProbe::fetchUidTextPreview(
					$host,
					$port,
					$user,
					$secret,
					$folder,
					$uid,
					Constants::MAIL_PREVIEW_MAX_BYTES,
				);
			} elseif (!function_exists('imap_open')) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					$this->l10n->t('This mailbox uses STARTTLS. Install php-imap on the server to load message previews.'),
				);
			}

			if (!empty($probe['reason']) && $probe['reason'] === 'auth') {
				$this->mailboxes->recordAuthFailure($mailboxId, (int)($row['auth_fail_count'] ?? 0));
			}

			if (!$probe['ok'] || trim((string)$probe['text']) === '') {
				$fallback = $this->fetchPreviewViaPhpImap($row, $folder, $uid);
				if ($fallback === null || trim($fallback) === '') {
					$detail = ImapConnection::sanitizeError((string)($probe['detail'] ?? ''));
					if ($detail !== '') {
						$this->logger->warning('CustomerCheck mail preview IMAP failed', [
							'messageId' => (int)$again['id'],
							'mailboxId' => $mailboxId,
							'workspaceId' => $workspaceId,
							'detail' => $detail,
						]);
					}
					throw new ValidationException(
						Constants::ERR_VALIDATION,
						$this->l10n->t('Could not load message preview from the mailbox.'),
					);
				}
				$plain = MailPreviewText::toPlainPreview($fallback, Constants::MAIL_PREVIEW_MAX_BYTES);
				$truncated = str_ends_with($plain, '…');
			} else {
				$plain = (string)$probe['text'];
				$truncated = !empty($probe['truncated']);
			}

			// Charge only after a successful fetch (peek above already enforced the ceiling).
			$this->rateLimit->allow($userBucket, Constants::MAIL_PREVIEW_USER_LIMIT, Constants::MAIL_PREVIEW_USER_WINDOW, true);
			$this->rateLimit->allow($wsBucket, Constants::MAIL_PREVIEW_WS_LIMIT, Constants::MAIL_PREVIEW_WS_WINDOW, true);

			if ($plain !== '') {
				try {
					$this->mailboxes->cacheMessagePreview($workspaceId, (int)$again['id'], $plain);
				} catch (\Throwable $e) {
					$this->logger->warning('CustomerCheck mail preview cache failed', [
						'messageId' => (int)$again['id'],
						'exception' => $e,
					]);
				}
			}

			$this->logger->info('CustomerCheck mail preview fetched', [
				'messageId' => (int)$again['id'],
				'workspaceId' => $workspaceId,
				'actor' => $actorUid !== '' ? $actorUid : null,
				'cached' => false,
				'bytes' => strlen($plain),
			]);

			return $this->withReplyPack([
				'id' => (int)$again['id'],
				'subject' => (string)$again['subject'],
				'from' => (string)$again['from'],
				'preview' => $plain,
				'cached' => false,
				'truncated' => $truncated,
			], array_merge($again, ['preview' => $plain]));
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * @param array<string, mixed> $mailboxRow
	 */
	private function fetchPreviewViaPhpImap(array $mailboxRow, string $folder, string $uid): ?string
	{
		if (!function_exists('imap_open') || !function_exists('imap_fetchbody') || !function_exists('imap_msgno')) {
			return null;
		}
		try {
			$secret = $this->crypto->decrypt((string)$mailboxRow['secret_enc']);
		} catch (\Throwable) {
			return null;
		}
		$host = ImapConnection::normalizeHost((string)$mailboxRow['imap_host']);
		$folder = trim($folder) !== '' ? trim($folder) : 'INBOX';
		if (ImapConnection::isBlockedMailHost($host) || !ImapConnection::isSafeImapFolder($folder)) {
			return null;
		}
		$port = (int)$mailboxRow['imap_port'];
		$enc = ImapConnection::resolveImapEnc((string)$mailboxRow['imap_enc'], $port > 0 ? $port : 993);
		$user = ImapConnection::normalizeUsername((string)$mailboxRow['username']);
		if ($port <= 0) {
			$port = $enc === ImapConnection::ENC_TLS ? 143 : 993;
		}
		// Open the target folder directly — UIDs are folder-scoped (never blindly reopen).
		$mailboxPath = ImapConnection::buildMailboxRoot($host, $port, $enc, false);
		$stream = @imap_open($mailboxPath . $folder, $user, $secret, 0, 3);
		if ($stream === false) {
			$err = ImapConnection::collectErrors(
				function_exists('imap_errors') ? @imap_errors() : null,
				function_exists('imap_last_error') ? @imap_last_error() : null,
			);
			if (ImapConnection::isCertificateError($err) || $err === '' || ImapConnection::classifyError($err) === 'unknown') {
				$mailboxPath = ImapConnection::buildMailboxRoot($host, $port, $enc, true);
				$stream = @imap_open($mailboxPath . $folder, $user, $secret, 0, 3);
			}
		}
		if ($stream === false) {
			return null;
		}
		try {
			$msgNo = @imap_msgno($stream, (int)$uid);
			if ($msgNo < 1) {
				return null;
			}
			$peek = defined('FT_PEEK') ? FT_PEEK : 1;
			// Part 1 only — never imap_body() (unbounded full MIME into memory).
			$body = @imap_fetchbody($stream, $msgNo, '1', $peek);
			if (!is_string($body) || trim($body) === '') {
				$body = @imap_fetchbody($stream, $msgNo, '1.1', $peek);
			}
			if (!is_string($body) || $body === '') {
				return null;
			}
			$max = Constants::MAIL_PREVIEW_MAX_BYTES + 2048;
			if (strlen($body) > $max) {
				$body = substr($body, 0, $max);
			}

			return $body;
		} finally {
			imap_close($stream);
		}
	}

	/**
	 * Sync all healthy mailboxes, then purge aged CRM mail index rows.
	 *
	 * @return array{mailboxes: int, messages: int, errors: int, purged: int}
	 */
	public function syncAll(): array
	{
		$mailboxes = 0;
		$messages = 0;
		$errors = 0;
		if ($this->db->tableExists('crm_mailbox')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'workspace_id')->from('crm_mailbox')
				->where($qb->expr()->neq('status', $qb->createNamedParameter('error')));
			$r = $qb->executeQuery();
			while ($row = $r->fetch()) {
				$mailboxes++;
				try {
					$result = $this->syncOne((int)$row['id'], (int)$row['workspace_id']);
					$messages += (int)$result['ingested'];
				} catch (\Throwable $e) {
					$errors++;
					$this->logger->warning('CustomerCheck mailbox sync failed', [
						'mailboxId' => (int)$row['id'],
						'exception' => $e,
					]);
				}
			}
			$r->closeCursor();
		}
		// Retention purge runs after sync so a concurrent ingest cannot race a
		// mid-flight message into a half-deleted state for the same batch.
		$purge = $this->purgeExpiredAcrossWorkspaces();
		return [
			'mailboxes' => $mailboxes,
			'messages' => $messages,
			'errors' => $errors,
			'purged' => $purge['purged'],
			'purgeRemaining' => $purge['remaining'],
		];
	}

	/**
	 * Enforce per-workspace mail_retention_days on the CRM index only.
	 * Serialized with an advisory lock so two sync jobs cannot interleave
	 * select-then-delete batches for the same backlog. Drains under a wall-clock
	 * budget with fair round-robin across workspaces so early WS cannot starve later ones.
	 *
	 * @return array{purged: int, remaining: int}
	 */
	public function purgeExpiredAcrossWorkspaces(): array
	{
		$lockKey = 'crm_mail_purge_all';
		try {
			$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			return ['purged' => 0, 'remaining' => -1];
		}
		$total = 0;
		$remaining = 0;
		$deadline = microtime(true) + Constants::MAIL_PURGE_BUDGET_SECONDS;
		try {
			$workspaces = array_values(array_filter(
				$this->workspaces->listAll(),
				static fn ($ws): bool => (int)$ws->getId() > 0,
			));
			$count = count($workspaces);
			if ($count === 0) {
				return ['purged' => 0, 'remaining' => 0];
			}
			// Rotate start each minute so a heavy early workspace cannot monopolize every run.
			$start = ((int)floor(time() / 60)) % $count;
			$ordered = [];
			for ($i = 0; $i < $count; $i++) {
				$ordered[] = $workspaces[($start + $i) % $count];
			}

			// Fair drain: one batch per workspace per wave until budget or quiet.
			for ($round = 0; $round < Constants::MAIL_PURGE_MAX_ROUNDS; $round++) {
				if (microtime(true) >= $deadline) {
					break;
				}
				$progress = false;
				foreach ($ordered as $ws) {
					if (microtime(true) >= $deadline) {
						break;
					}
					$wsId = (int)$ws->getId();
					$days = (int)$this->workspaces->getSettings($wsId)['mailRetentionDays'];
					$n = $this->mailboxes->purgeExpired($wsId, $days);
					$total += $n;
					if ($n > 0) {
						$progress = true;
					}
				}
				if (!$progress) {
					break;
				}
			}

			foreach ($ordered as $ws) {
				$wsId = (int)$ws->getId();
				$days = (int)$this->workspaces->getSettings($wsId)['mailRetentionDays'];
				$remaining += $this->mailboxes->countExpired($wsId, $days);
			}
			if ($remaining > 0) {
				$this->logger->warning('CustomerCheck mail retention purge incomplete', [
					'purged' => $total,
					'remaining' => $remaining,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck mail retention purge failed', [
				'exception' => $e,
			]);
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
			}
		}
		return ['purged' => $total, 'remaining' => $remaining];
	}

	/**
	 * @return array{ingested: int, status: string}
	 */
	public function syncOne(int $mailboxId, int $workspaceId): array
	{
		$lockKey = 'crm_mbox_' . $mailboxId;
		try {
			$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			return ['ingested' => 0, 'status' => 'locked'];
		}
		try {
			return $this->doSync($mailboxId, $workspaceId);
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * Remove mailbox under the same advisory lock as sync — prevents ingest racing delete.
	 *
	 * @throws \OCA\CustomerCheck\Exception\ValidationException when sync holds the lock
	 */
	public function deleteMailbox(int $workspaceId, int $mailboxId): void
	{
		$lockKey = 'crm_mbox_' . $mailboxId;
		try {
			$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'id',
				$this->l10n->t('Sync already running for this mailbox. Try again in a moment.'),
			);
		}
		try {
			$this->mailboxes->delete($workspaceId, $mailboxId);
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * Ingest a pre-fetched batch (unit/integration without live IMAP).
	 *
	 * @param list<array<string, mixed>> $messages
	 * @return array{ingested: int}
	 */
	public function ingestBatch(int $workspaceId, int $mailboxId, int $folderId, array $messages): array
	{
		$persons = $this->personIndex($workspaceId);
		$n = 0;
		foreach (array_slice($messages, 0, Constants::MAILBOX_SYNC_CAP) as $msg) {
			$this->mailboxes->ingestMessage($workspaceId, $mailboxId, $folderId, $msg, $persons);
			$n++;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('last_sync_at', $qb->createNamedParameter($now))
			->set('status', $qb->createNamedParameter('ok'))
			->set('auth_fail_count', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->executeStatement();
		return ['ingested' => $n];
	}

	/**
	 * Test IMAP credentials without indexing mail.
	 *
	 * Prefers php-imap when available. Falls back to ImapLiveProbe (TLS + LOGIN)
	 * so Docker/dev hosts without ext-imap still get a real connectivity verdict.
	 *
	 * @param array<string, mixed> $cfg
	 * @return array{ok: bool, message: string, folders?: list<string>, reason?: string, detail?: string, via?: string}
	 */
	public function testConnection(array $cfg): array
	{
		$host = ImapConnection::normalizeHost((string)($cfg['imapHost'] ?? ''));
		$port = (int)($cfg['imapPort'] ?? 993);
		$user = ImapConnection::normalizeUsername((string)($cfg['username'] ?? ''));
		$pass = (string)($cfg['password'] ?? '');
		$enc = ImapConnection::resolveImapEnc(
			isset($cfg['imapEnc']) ? (string)$cfg['imapEnc'] : null,
			$port > 0 ? $port : 993,
		);
		if ($port <= 0) {
			$port = $enc === ImapConnection::ENC_TLS ? 143 : 993;
		}

		// Re-test while editing: blank password reuses the encrypted secret for this workspace.
		$mailboxId = (int)($cfg['id'] ?? $cfg['mailboxId'] ?? 0);
		$workspaceId = (int)($cfg['workspaceId'] ?? 0);
		if ($pass === '' && $mailboxId > 0 && $workspaceId > 0) {
			$pass = $this->loadMailboxSecret($mailboxId, $workspaceId) ?? '';
		}

		if ($host === '' || $user === '' || $pass === '') {
			return [
				'ok' => false,
				'message' => $this->l10n->t('Host, username and password are required.'),
				'reason' => 'validation',
			];
		}

		if (ImapConnection::isBlockedMailHost($host)) {
			return [
				'ok' => false,
				'message' => $this->l10n->t('Use a public mail host — localhost and private network addresses are not allowed.'),
				'reason' => 'validation',
			];
		}

		if (!function_exists('imap_open')) {
			return $this->testConnectionViaLiveProbe($host, $port, $user, $pass);
		}

		if (function_exists('imap_errors')) {
			@imap_errors();
		}
		if (function_exists('imap_alerts')) {
			@imap_alerts();
		}

		$stream = $this->openImapStream($host, $port, $enc, $user, $pass, false);
		$usedNovalidate = false;
		if ($stream === false) {
			$firstDetail = ImapConnection::collectErrors(
				function_exists('imap_errors') ? @imap_errors() : null,
				function_exists('imap_last_error') ? @imap_last_error() : null,
			);
			// Retry once without cert hostname checks — common on shared hosts with
			// mismatched CN / incomplete CA chains. Prefer validated first.
			// Also retry when the first error was empty/opaque (not clearly auth/network).
			$firstReason = ImapConnection::classifyError($firstDetail);
			$shouldRetryNovalidate = ImapConnection::isCertificateError($firstDetail)
				|| $firstDetail === ''
				|| $firstReason === 'unknown';
			if ($shouldRetryNovalidate && $firstReason !== 'auth' && $firstReason !== 'network') {
				if (function_exists('imap_errors')) {
					@imap_errors();
				}
				$stream = $this->openImapStream($host, $port, $enc, $user, $pass, true);
				$usedNovalidate = $stream !== false;
			}
			if ($stream === false) {
				$detail = ImapConnection::collectErrors(
					function_exists('imap_errors') ? @imap_errors() : null,
					function_exists('imap_last_error') ? @imap_last_error() : null,
				);
				if ($detail === '' && $firstDetail !== '') {
					$detail = $firstDetail;
				}
				return $this->failedTestResult($detail);
			}
		}

		$folders = $this->listRemoteFolders($stream, $host, $port, $enc, $usedNovalidate);
		imap_close($stream);

		$message = $this->l10n->t('IMAP connection OK.');
		if ($usedNovalidate) {
			$message = $this->l10n->t('IMAP connection OK (server certificate could not be fully verified — connection still works).');
		}

		return [
			'ok' => true,
			'message' => $message,
			'folders' => $folders !== [] ? $folders : ['INBOX'],
			'via' => 'imap',
		];
	}

	/**
	 * @return array{ok: bool, message: string, folders?: list<string>, reason?: string, detail?: string, via: string}
	 */
	private function testConnectionViaLiveProbe(string $host, int $port, string $user, string $pass): array
	{
		$probe = ImapLiveProbe::probeLogin($host, $port > 0 ? $port : 993, $user, $pass, 15.0);
		if ($probe['ok']) {
			$folders = isset($probe['folders']) && is_array($probe['folders'])
				? array_values(array_filter(array_map('strval', $probe['folders'])))
				: ['INBOX'];
			if ($folders === []) {
				$folders = ['INBOX'];
			}
			return [
				'ok' => true,
				'message' => $this->l10n->t('IMAP login OK (probed without PHP IMAP extension). Install php-imap on the server before relying on background sync.'),
				'folders' => array_slice($folders, 0, Constants::MAILBOX_MAX_FOLDERS),
				'via' => 'live_probe',
				'reason' => 'ok',
			];
		}
		$detail = (string)($probe['detail'] ?? '');
		$reason = (string)($probe['reason'] ?? 'unknown');
		if ($reason === 'protocol') {
			$reason = 'unknown';
		}
		$result = $this->failedTestResult($detail !== '' ? $detail : $reason);
		$result['via'] = 'live_probe';
		if ($reason !== 'ok') {
			$result['reason'] = $reason;
		}
		if (!$probe['reachable']) {
			$result['reason'] = 'network';
			$result['message'] = $this->l10n->t('Could not reach the IMAP server. Check host, port, and that this Nextcloud server may open outbound port 993/143.');
			if ($detail !== '') {
				$result['message'] .= ' ' . $this->l10n->t('Details: %s', [$detail]);
			}
		}
		return $result;
	}

	/**
	 * @return resource|\IMAP\Connection|false
	 */
	private function openImapStream(
		string $host,
		int $port,
		string $enc,
		string $user,
		string $pass,
		bool $novalidateCert,
	) {
		$root = ImapConnection::buildMailboxRoot($host, $port, $enc, $novalidateCert);
		// Select INBOX so auth + mailbox access are both proven (HALFOPEN can hide ACL issues).
		return @imap_open($root . 'INBOX', $user, $pass, 0, 3);
	}

	/**
	 * @param resource|\IMAP\Connection $stream
	 * @return list<string>
	 */
	private function listRemoteFolders($stream, string $host, int $port, string $enc, bool $novalidateCert): array
	{
		if (!function_exists('imap_list')) {
			return ['INBOX'];
		}
		$root = ImapConnection::buildMailboxRoot($host, $port, $enc, $novalidateCert);
		$list = @imap_list($stream, $root, '*');
		if (!is_array($list) || $list === []) {
			return ['INBOX'];
		}
		$out = [];
		foreach ($list as $full) {
			$path = (string)$full;
			if (str_starts_with($path, $root)) {
				$path = substr($path, strlen($root));
			}
			$path = trim($path);
			if ($path === '') {
				continue;
			}
			$out[] = $path;
			if (count($out) >= Constants::MAILBOX_MAX_FOLDERS) {
				break;
			}
		}
		return $out !== [] ? $out : ['INBOX'];
	}

	/**
	 * @return array{ok: false, message: string, reason: string, detail: string}
	 */
	private function failedTestResult(string $detail): array
	{
		$reason = ImapConnection::classifyError($detail);
		$message = match ($reason) {
			'auth' => $this->l10n->t('IMAP login failed. Use the full email address as username (not the account id in parentheses) and the mailbox password.'),
			'cert' => $this->l10n->t('IMAP TLS certificate check failed. Confirm the IMAP host matches your mail provider, then try again.'),
			'network' => $this->l10n->t('Could not reach the IMAP server. Check host, port, and that this Nextcloud server may open outbound port 993/143.'),
			default => $this->l10n->t('IMAP connection failed.'),
		};
		if ($detail !== '' && $reason === 'unknown') {
			$message = $this->l10n->t('IMAP connection failed: %s', [$detail]);
		} elseif ($detail !== '' && $reason !== 'unknown') {
			$message .= ' ' . $this->l10n->t('Details: %s', [$detail]);
		}

		return [
			'ok' => false,
			'message' => $message,
			'reason' => $reason,
			'detail' => $detail,
		];
	}

	private function loadMailboxSecret(int $mailboxId, int $workspaceId): ?string
	{
		if (!$this->db->tableExists('crm_mailbox')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('secret_enc')
			->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return null;
		}
		$enc = trim((string)($row['secret_enc'] ?? ''));
		if ($enc === '') {
			return null;
		}
		try {
			return $this->crypto->decrypt($enc);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return array{ingested: int, status: string}
	 */
	private function doSync(int $mailboxId, int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return ['ingested' => 0, 'status' => 'missing'];
		}

		$host = ImapConnection::normalizeHost((string)$row['imap_host']);
		if (ImapConnection::isBlockedMailHost($host)) {
			$this->logger->warning('CustomerCheck mailbox sync blocked private/local IMAP host', [
				'mailboxId' => $mailboxId,
				'workspaceId' => $workspaceId,
			]);
			return ['ingested' => 0, 'status' => 'error'];
		}

		if (!function_exists('imap_open')) {
			return $this->doSyncViaLiveProbe($mailboxId, $workspaceId, $row);
		}

		try {
			$secret = $this->crypto->decrypt((string)$row['secret_enc']);
		} catch (\Throwable) {
			$this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
			return ['ingested' => 0, 'status' => 'auth'];
		}

		$port = (int)$row['imap_port'];
		$enc = ImapConnection::resolveImapEnc((string)$row['imap_enc'], $port > 0 ? $port : 993);
		$user = ImapConnection::normalizeUsername((string)$row['username']);
		if ($port <= 0) {
			$port = $enc === ImapConnection::ENC_TLS ? 143 : 993;
		}
		$mailboxPath = ImapConnection::buildMailboxRoot($host, $port, $enc, false);
		$stream = @imap_open($mailboxPath . 'INBOX', $user, $secret, 0, 3);
		if ($stream === false) {
			$err = ImapConnection::collectErrors(
				function_exists('imap_errors') ? @imap_errors() : null,
				function_exists('imap_last_error') ? @imap_last_error() : null,
			);
			$errReason = ImapConnection::classifyError($err);
			if (
				ImapConnection::isCertificateError($err)
				|| $err === ''
				|| $errReason === 'unknown'
			) {
				$mailboxPath = ImapConnection::buildMailboxRoot($host, $port, $enc, true);
				$stream = @imap_open($mailboxPath . 'INBOX', $user, $secret, 0, 3);
			}
		}
		if ($stream === false) {
			$status = $this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
			if ($status === 'error') {
				$wsId = (int)($row['workspace_id'] ?? 0);
				if ($wsId > 0) {
					$this->notifications->notifyWorkspaceUsers(
						$wsId,
						Constants::NOTIFY_DIGEST,
						'mailbox_error',
						(string)$mailboxId,
						['label' => (string)$row['label']],
					);
				}
			}
			return ['ingested' => 0, 'status' => $status];
		}

		$persons = $this->personIndex($workspaceId);
		$folders = $this->enabledFolders($mailboxId);
		$ingested = 0;
		foreach ($folders as $folder) {
			$folderPath = (string)$folder['folder_path'];
			if (!ImapConnection::isSafeImapFolder($folderPath)) {
				$this->logger->warning('CustomerCheck mailbox sync skipped unsafe IMAP folder', [
					'mailboxId' => $mailboxId,
					'workspaceId' => $workspaceId,
				]);
				continue;
			}
			$full = $mailboxPath . $folderPath;
			@imap_reopen($stream, $full);
			$n = @imap_num_msg($stream);
			if ($n === false || $n < 1) {
				continue;
			}
			$start = max(1, $n - Constants::MAILBOX_SYNC_CAP + 1);
			for ($i = $n; $i >= $start && $ingested < Constants::MAILBOX_SYNC_CAP; $i--) {
				$hdr = @imap_headerinfo($stream, $i);
				if ($hdr === false) {
					continue;
				}
				$uid = (string)(@imap_uid($stream, $i) ?: $i);
				$from = '';
				if (!empty($hdr->from) && is_array($hdr->from)) {
					$f = $hdr->from[0];
					$from = strtolower(trim(($f->mailbox ?? '') . '@' . ($f->host ?? '')));
				}
				$subject = isset($hdr->subject)
					? MimeHeader::decode((string)@imap_utf8($hdr->subject))
					: '';
				$sentAt = isset($hdr->date) ? date('Y-m-d H:i:s', strtotime((string)$hdr->date) ?: time()) : null;
				$this->mailboxes->ingestMessage($workspaceId, $mailboxId, (int)$folder['id'], [
					'uid' => $uid,
					'from' => $from,
					'subject' => $subject,
					'snippet' => mb_substr($subject, 0, 200),
					'sentAt' => $sentAt,
					'direction' => 'inbound',
				], $persons);
				$ingested++;
			}
		}
		imap_close($stream);

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$u = $this->db->getQueryBuilder();
		$u->update('crm_mailbox')
			->set('last_sync_at', $u->createNamedParameter($now))
			->set('status', $u->createNamedParameter('ok'))
			->set('auth_fail_count', $u->createNamedParameter(0))
			->set('last_error', $u->createNamedParameter(null))
			->where($u->expr()->eq('id', $u->createNamedParameter($mailboxId)))
			->executeStatement();

		return ['ingested' => $ingested, 'status' => 'ok'];
	}

	/**
	 * Header ingest without php-imap — TLS LOGIN + SELECT + FETCH via ImapLiveProbe.
	 *
	 * @param array<string, mixed> $row
	 * @return array{ingested: int, status: string}
	 */
	private function doSyncViaLiveProbe(int $mailboxId, int $workspaceId, array $row): array
	{
		try {
			$secret = $this->crypto->decrypt((string)$row['secret_enc']);
		} catch (\Throwable) {
			$this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
			return ['ingested' => 0, 'status' => 'auth'];
		}

		$host = ImapConnection::normalizeHost((string)$row['imap_host']);
		$port = (int)$row['imap_port'];
		$enc = ImapConnection::resolveImapEnc((string)$row['imap_enc'], $port > 0 ? $port : 993);
		$user = ImapConnection::normalizeUsername((string)$row['username']);
		if ($port <= 0) {
			$port = $enc === ImapConnection::ENC_TLS ? 143 : 993;
		}
		if (ImapConnection::isBlockedMailHost($host)) {
			$this->logger->warning('CustomerCheck mailbox live-probe sync blocked private/local IMAP host', [
				'mailboxId' => $mailboxId,
				'workspaceId' => $workspaceId,
			]);
			return ['ingested' => 0, 'status' => 'error'];
		}
		// Live probe currently speaks SSL wrap (993). STARTTLS without php-imap is not supported.
		if ($enc !== ImapConnection::ENC_SSL || $port === 143) {
			$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
			$u = $this->db->getQueryBuilder();
			$u->update('crm_mailbox')
				->set('last_error', $u->createNamedParameter('IMAP sync needs SSL port 993 when php-imap is missing'))
				->set('last_sync_at', $u->createNamedParameter($now))
				->where($u->expr()->eq('id', $u->createNamedParameter($mailboxId)))
				->executeStatement();
			return ['ingested' => 0, 'status' => 'no_imap'];
		}

		$persons = $this->personIndex($workspaceId);
		$folders = $this->enabledFolders($mailboxId);
		$ingested = 0;
		foreach ($folders as $folder) {
			if ($ingested >= Constants::MAILBOX_SYNC_CAP) {
				break;
			}
			$folderPath = (string)$folder['folder_path'];
			if (!ImapConnection::isSafeImapFolder($folderPath)) {
				$this->logger->warning('CustomerCheck mailbox live-probe sync skipped unsafe IMAP folder', [
					'mailboxId' => $mailboxId,
					'workspaceId' => $workspaceId,
				]);
				continue;
			}
			$remaining = Constants::MAILBOX_SYNC_CAP - $ingested;
			$result = ImapLiveProbe::fetchRecentMessages(
				$host,
				$port,
				$user,
				$secret,
				$folderPath,
				$remaining,
			);
			if (!$result['ok']) {
				if ($result['reason'] === 'auth') {
					$status = $this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
					if ($status === 'error') {
						$wsId = (int)($row['workspace_id'] ?? $workspaceId);
						if ($wsId > 0) {
							$this->notifications->notifyWorkspaceUsers(
								$wsId,
								Constants::NOTIFY_DIGEST,
								'mailbox_error',
								(string)$mailboxId,
								['label' => (string)$row['label']],
							);
						}
					}
					return ['ingested' => $ingested, 'status' => $status];
				}
				$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
				$detail = ImapConnection::sanitizeError((string)($result['detail'] ?? 'IMAP sync failed'));
				$u = $this->db->getQueryBuilder();
				$u->update('crm_mailbox')
					->set('last_error', $u->createNamedParameter($detail !== '' ? $detail : 'IMAP sync failed'))
					->set('last_sync_at', $u->createNamedParameter($now))
					->where($u->expr()->eq('id', $u->createNamedParameter($mailboxId)))
					->executeStatement();
				return ['ingested' => $ingested, 'status' => (string)$result['reason']];
			}
			foreach ($result['messages'] as $msg) {
				if ($ingested >= Constants::MAILBOX_SYNC_CAP) {
					break;
				}
				$this->mailboxes->ingestMessage($workspaceId, $mailboxId, (int)$folder['id'], $msg, $persons);
				$ingested++;
			}
		}

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$u = $this->db->getQueryBuilder();
		$u->update('crm_mailbox')
			->set('last_sync_at', $u->createNamedParameter($now))
			->set('status', $u->createNamedParameter('ok'))
			->set('auth_fail_count', $u->createNamedParameter(0))
			->set('last_error', $u->createNamedParameter(null))
			->where($u->expr()->eq('id', $u->createNamedParameter($mailboxId)))
			->executeStatement();

		return ['ingested' => $ingested, 'status' => 'ok'];
	}

	/** @return list<array{email: string, companyId: int}> */
	private function personIndex(int $workspaceId): array
	{
		$out = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.email', 'p.company_id')
			->from('crm_person', 'p')
			->innerJoin('p', 'crm_company', 'c', $qb->expr()->eq('p.company_id', 'c.id'))
			->where($qb->expr()->eq('c.workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->isNotNull('p.email'));
		$r = $qb->executeQuery();
		while ($row = $r->fetch()) {
			$email = strtolower(trim((string)$row['email']));
			if ($email === '') {
				continue;
			}
			$out[] = ['email' => $email, 'companyId' => (int)$row['company_id']];
		}
		$r->closeCursor();
		return $out;
	}

	/** @return list<array{id: int, folder_path: string}> */
	private function enabledFolders(int $mailboxId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'folder_path')->from('crm_mail_fld')
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('sync_enabled', $qb->createNamedParameter(1)));
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = ['id' => (int)$row['id'], 'folder_path' => (string)$row['folder_path']];
		}
		$r->closeCursor();
		if ($out === []) {
			// Default INBOX folder row if manager has not picked yet.
			return [['id' => 0, 'folder_path' => 'INBOX']];
		}
		return $out;
	}
}
