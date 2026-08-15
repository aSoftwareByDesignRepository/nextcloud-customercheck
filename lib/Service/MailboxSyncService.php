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
 * Team and personal mailboxes are ingested when policy allows (`personalMailEnabled`
 * for kind=personal). Personal visibility/ACL is enforced at read time (MailAclLogic).
 */
class MailboxSyncService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ICrypto $crypto,
		private readonly MailboxService $mailboxes,
		private readonly WorkspaceService $workspaces,
		/** @deprecated Quarantine notify lives on MailboxService::recordAuthFailure (US-002 AC-3). Kept for DI BC. */
		private readonly NotificationService $notifications,
		private readonly ILockingProvider $locks,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly RateLimitService $rateLimit,
		private readonly MailboxAuthService $mailboxAuth,
		private readonly ?FacadeProbeService $facades = null,
	) {
	}

	/**
	 * Delete one indexed shared-mailbox message on the IMAP server (spam / junk).
	 * Idempotent when the UID is already gone. Does not change CRM triage by itself —
	 * callers (Assign discard) close the CRM copy after a successful dial.
	 *
	 * Prefers php-imap when available; falls back to ImapLiveProbe (same path as sync
	 * on Docker/dev hosts without ext-imap).
	 *
	 * @param ?string $personalOwnerActor When set, personal mailboxes may be dialed if this
	 *                                    actor owns the mailbox (Inbox Personal tab). Null =
	 *                                    Assign path — personal dials stay forbidden.
	 *
	 * @return bool true when IMAP was dialed (or UID already gone); false when CRM-only
	 *              (synthetic outbound: / missing uid — Assign still closes)
	 *
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function deleteIndexedMessage(int $workspaceId, int $messageId, ?string $personalOwnerActor = null): bool
	{
		$msg = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
		if ($msg === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$mailboxId = (int)$msg['mailboxId'];
		$folder = trim((string)$msg['folderPath']) !== '' ? trim((string)$msg['folderPath']) : 'INBOX';
		$uidRaw = trim((string)$msg['messageUid']);
		// No mailbox / no live IMAP UID → CRM-only. Never throw “missing folder or id”
		// for outbound:… / empty / garbage UIDs (Assign + Inbox must still close).
		if ($mailboxId <= 0 || !ImapConnection::isLiveUid($uidRaw)) {
			return false;
		}
		// Live dial only — personal mailboxes need the owning actor (Inbox Personal).
		$this->assertRemoteMutateAllowed($workspaceId, $mailboxId, $personalOwnerActor);
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

				return true;
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

				return true;
			}
			try {
				$ftUid = defined('FT_UID') ? FT_UID : 1;
				// Already gone on the server → success (idempotent spam cleanup).
				$msgNo = function_exists('imap_msgno') ? @imap_msgno($stream, (int)$uidRaw) : 0;
				if ($msgNo < 1) {
					return true;
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

		return true;
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
	 * Move one indexed shared-mailbox message into the server Junk/Spam folder.
	 * Folder is discovered on the IMAP host (never client-supplied). Same lock as delete/sync.
	 *
	 * @param ?string $personalOwnerActor When set, personal mailboxes may be dialed if this
	 *                                    actor owns the mailbox (Inbox Personal tab). Null =
	 *                                    Assign path — personal dials stay forbidden.
	 *
	 * @return bool true when IMAP was dialed (or UID already gone); false when CRM-only
	 *              (synthetic outbound: / missing uid — Assign still closes)
	 *
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function moveIndexedMessageToJunk(int $workspaceId, int $messageId, ?string $personalOwnerActor = null): bool
	{
		$msg = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
		if ($msg === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$mailboxId = (int)$msg['mailboxId'];
		$folder = trim((string)$msg['folderPath']) !== '' ? trim((string)$msg['folderPath']) : 'INBOX';
		$uidRaw = trim((string)$msg['messageUid']);
		// No mailbox / no live IMAP UID → CRM-only (same gate as deleteIndexedMessage).
		if ($mailboxId <= 0 || !ImapConnection::isLiveUid($uidRaw)) {
			return false;
		}
		$this->assertRemoteMutateAllowed($workspaceId, $mailboxId, $personalOwnerActor);
		if (!ImapConnection::isSafeImapFolder($folder)) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('This message cannot be marked as spam (unsafe folder).'),
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
			// Live probe speaks IMAP over TLS (993). STARTTLS-only hosts need php-imap.
			$probePort = $port === 143 ? 993 : $port;
			$result = ImapLiveProbe::moveUidToJunk($host, $probePort, $user, $secret, $folder, $uidRaw, 25.0);
			if (!empty($result['ok'])) {
				return true;
			}
			$this->logger->warning('CustomerCheck IMAP move-to-junk failed', [
				'workspaceId' => $workspaceId,
				'mailboxId' => $mailboxId,
				'messageId' => $messageId,
				'uid' => $uidRaw,
				'reason' => (string)($result['reason'] ?? ''),
				'detail' => (string)($result['detail'] ?? ''),
			]);
			$reason = (string)($result['reason'] ?? '');
			$detail = (string)($result['detail'] ?? '');
			if ($reason === 'auth') {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					$this->l10n->t('Could not sign in to the shared mailbox to mark spam. Check Team & mailbox.'),
				);
			}
			if (str_contains(strtolower($detail), 'no junk') || str_contains(strtolower($detail), 'no spam')) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					$this->l10n->t('This mailbox has no Spam/Junk folder. Delete the message instead.'),
				);
			}
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Could not mark this message as spam on the mail server. Try again or move it in the mail host.'),
			);
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * Prepare Answer (mailto / Mail app / in-app send) for an indexed message — no IMAP dial.
	 * Same ACL as preview (never leak private personal bodies via reply pack).
	 *
	 * @return array{
	 *   to:string,
	 *   subject:string,
	 *   bodyText:string,
	 *   mailtoUrl:string,
	 *   mailAppUrl:?string,
	 *   available:bool,
	 *   mailboxId?:int,
	 *   companyId?:int,
	 *   visibility?:string,
	 *   mailboxKind?:string
	 * }
	 */
	public function replyComposeIndexedMessage(
		int $workspaceId,
		int $messageId,
		string $actorUid = '',
		bool $canReadCompany = false,
		bool $canReadOrTriageMail = true,
	): array {
		$msg = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
		if ($msg === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$actorUid = trim($actorUid);
		if ($actorUid === '') {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Sign in to prepare a reply.'),
			);
		}
		$companyId = isset($msg['companyId']) ? (int)$msg['companyId'] : 0;
		if (!MailAclLogic::canReadMessage(
			$actorUid,
			[
				'visibility' => (string)($msg['visibility'] ?? MailAclLogic::VISIBILITY_SHARED),
				'ownerUserId' => $msg['ownerUserId'] ?? null,
				'companyId' => $companyId > 0 ? $companyId : null,
				'mailboxKind' => (string)($msg['mailboxKind'] ?? MailAclLogic::KIND_TEAM),
			],
			$canReadCompany,
			$canReadOrTriageMail,
		)) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$quote = trim((string)($msg['preview'] ?? ''));
		if ($quote === '') {
			$quote = $this->messageSnippet($workspaceId, $messageId);
			// Assign sync stores the From line in snippet — not useful as a quote body.
			$asEmail = MailtoReplyBuilder::extractEmail($quote);
			if ($asEmail !== null && strcasecmp($asEmail, $quote) === 0) {
				$quote = '';
			}
		}

		$pack = $this->replyPackFromMessage($msg, $quote);
		foreach (['to', 'subject', 'bodyText', 'mailtoUrl'] as $k) {
			if (isset($pack[$k]) && is_string($pack[$k])) {
				$pack[$k] = MailPreviewText::ensureUtf8($pack[$k]);
			}
		}
		if (isset($msg['mailboxId'])) {
			$pack['mailboxId'] = (int)$msg['mailboxId'];
		}
		if ($companyId > 0) {
			$pack['companyId'] = $companyId;
		}
		if (isset($msg['visibility'])) {
			$pack['visibility'] = (string)$msg['visibility'];
		}
		if (isset($msg['mailboxKind'])) {
			$pack['mailboxKind'] = (string)$msg['mailboxKind'];
		}

		return $pack;
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
		// Preview / headers must be JSON-safe UTF-8 — invalid IMAP bytes otherwise
		// make Nextcloud JSONResponse::render() throw → HTML HTTP 500 for the client.
		$payload['subject'] = MailPreviewText::ensureUtf8((string)($payload['subject'] ?? ''));
		$payload['from'] = MailPreviewText::ensureUtf8((string)($payload['from'] ?? ''));
		// Re-run full plain preview pipeline so legacy cached MIME debris is stripped on read.
		$payload['preview'] = MailPreviewText::toPlainPreview((string)($payload['preview'] ?? ''));
		// FAC-16: expose re-sanitized bodyHtml from findMessageForPreview (never raw DB).
		$bodyHtml = trim((string)($msg['bodyHtml'] ?? ''));
		if ($bodyHtml !== '') {
			$payload['bodyHtml'] = MailPreviewText::ensureUtf8($bodyHtml);
		} else {
			$payload['bodyHtml'] = '';
		}
		if (isset($msg['mailboxId'])) {
			$payload['mailboxId'] = (int)$msg['mailboxId'];
		}
		if (!isset($payload['visibility']) && isset($msg['visibility'])) {
			$payload['visibility'] = (string)$msg['visibility'];
		}
		$payload['reply'] = $this->replyPackFromMessage($msg, (string)($payload['preview'] ?? ''));
		if (isset($payload['reply']) && is_array($payload['reply'])) {
			foreach (['to', 'subject', 'bodyText', 'mailtoUrl'] as $k) {
				if (isset($payload['reply'][$k]) && is_string($payload['reply'][$k])) {
					$payload['reply'][$k] = MailPreviewText::ensureUtf8($payload['reply'][$k]);
				}
			}
		}

		return $payload;
	}

	/**
	 * On-demand plain-text preview for one indexed CRM mail message.
	 * Uses cached crm_mail_msg.preview when present; otherwise fetches via IMAP
	 * (live TLS probe — works without php-imap) and caches the result.
	 *
	 * @return array{id: int, subject: string, from: string, preview: string, cached: bool, truncated: bool, reply: array<string, mixed>}
	 */
	/**
	 * @param bool $canReadCompany Workspace `read` / company.read — never invent from company_id alone (FAC-04).
	 * @param bool $canReadOrTriageMail Workspace mail.read / triage — for shared unmatched (FAC-02/03).
	 */
	public function previewIndexedMessage(
		int $workspaceId,
		int $messageId,
		string $actorUid = '',
		bool $canReadCompany = false,
		bool $canReadOrTriageMail = true,
	): array {
		$msg = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
		if ($msg === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$actorUid = trim($actorUid);
		if ($actorUid === '') {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Sign in to load a message preview.'),
			);
		}
		$companyId = isset($msg['companyId']) ? (int)$msg['companyId'] : 0;
		if (!MailAclLogic::canReadMessage(
			$actorUid,
			[
				'visibility' => (string)($msg['visibility'] ?? MailAclLogic::VISIBILITY_SHARED),
				'ownerUserId' => $msg['ownerUserId'] ?? null,
				'companyId' => $companyId > 0 ? $companyId : null,
				'mailboxKind' => (string)($msg['mailboxKind'] ?? MailAclLogic::KIND_TEAM),
			],
			$canReadCompany,
			$canReadOrTriageMail,
		)) {
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
				'visibility' => (string)($msg['visibility'] ?? MailAclLogic::VISIBILITY_SHARED),
			], $msg);
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
				$auth = $this->mailboxAuth->resolve($row);
			} catch (\Throwable $e) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					$e instanceof ValidationException ? $e->getMessage() : $this->l10n->t('Mailbox password could not be unlocked.'),
				);
			}
			$secret = $auth['secret'];
			$user = $auth['user'];
			$authMode = $auth['mode'];

			$host = ImapConnection::normalizeHost((string)$row['imap_host']);
			if (ImapConnection::isBlockedMailHost($host)) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					$this->l10n->t('This mailbox host cannot be reached from CustomerCheck.'),
				);
			}
			$port = (int)$row['imap_port'];
			$enc = ImapConnection::resolveImapEnc((string)$row['imap_enc'], $port > 0 ? $port : 993);
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
			$useLiveProbe = ($enc === ImapConnection::ENC_SSL && $port !== 143)
				|| $authMode === MailboxAuthService::MODE_XOAUTH2;
			if ($useLiveProbe) {
				$probe = ImapLiveProbe::fetchUidTextPreview(
					$host,
					$port,
					$user,
					$secret,
					$folder,
					$uid,
					Constants::MAIL_BODY_PLAIN_MAX_BYTES,
					20.0,
					$authMode,
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
				// php-imap cannot do XOAUTH2 — fail closed for OAuth mailboxes.
				$fallback = $authMode === MailboxAuthService::MODE_XOAUTH2
					? null
					: $this->fetchPreviewViaPhpImap($row, $folder, $uid);
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
				$plain = MailPreviewText::toPlainPreview($fallback, Constants::MAIL_BODY_PLAIN_MAX_BYTES);
				$truncated = str_ends_with($plain, '…')
					|| strlen($fallback) >= Constants::MAIL_BODY_PLAIN_MAX_BYTES;
			} else {
				$plain = (string)$probe['text'];
				$truncated = !empty($probe['truncated']);
			}

			// Charge only after a successful fetch (peek above already enforced the ceiling).
			$this->rateLimit->allow($userBucket, Constants::MAIL_PREVIEW_USER_LIMIT, Constants::MAIL_PREVIEW_USER_WINDOW, true);
			$this->rateLimit->allow($wsBucket, Constants::MAIL_PREVIEW_WS_LIMIT, Constants::MAIL_PREVIEW_WS_WINDOW, true);

			if ($plain !== '') {
				try {
					// MH-05: backfill body_text when empty; preview column stays preview-sized.
					$this->mailboxes->cacheMessageBodies($workspaceId, (int)$again['id'], $plain);
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
		$cooldownSkipped = 0;
		if ($this->db->tableExists('crm_mailbox')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('crm_mailbox')
				->where($qb->expr()->neq('status', $qb->createNamedParameter('error')));
			$r = $qb->executeQuery();
			$now = $this->timeFactory->getDateTime();
			while ($row = $r->fetch()) {
				$mailboxes++;
				$mailboxId = (int)$row['id'];
				$workspaceId = (int)$row['workspace_id'];
				if ($this->mailboxes->isSyncDialCooldownActive($row, $now)) {
					$cooldownSkipped++;
					continue;
				}
				try {
					$result = $this->syncOne($mailboxId, $workspaceId);
					$messages += (int)$result['ingested'];
					$this->afterCronSyncResult($mailboxId, $workspaceId, $row, $result);
				} catch (\Throwable $e) {
					$errors++;
					$this->mailboxes->recordSyncDialFailure(
						$mailboxId,
						(int)($row['sync_fail_streak'] ?? 0),
						$e->getMessage(),
					);
					$this->logPersonalSyncFail($mailboxId, $workspaceId, 'exception', $e->getMessage());
					$this->logger->warning('CustomerCheck mailbox sync failed', [
						'event' => 'personal_mail.sync_fail',
						'mailboxId' => $mailboxId,
						'workspaceId' => $workspaceId,
						'exception' => $e,
					]);
				}
			}
			$r->closeCursor();
			$this->emitSyncFailRateAlert($mailboxes, $errors, $cooldownSkipped);
		}
		// Retention purge runs after sync so a concurrent ingest cannot race a
		// mid-flight message into a half-deleted state for the same batch.
		$purge = $this->purgeExpiredAcrossWorkspaces();
		return [
			'mailboxes' => $mailboxes,
			'messages' => $messages,
			'errors' => $errors,
			'cooldownSkipped' => $cooldownSkipped,
			'purged' => $purge['purged'],
			'purgeRemaining' => $purge['remaining'],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array{ingested?: int, status?: string} $result
	 */
	private function afterCronSyncResult(int $mailboxId, int $workspaceId, array $row, array $result): void
	{
		$status = (string)($result['status'] ?? '');
		if ($status === 'ok' || in_array($status, ['locked', 'disabled', 'missing', 'cooldown'], true)) {
			return;
		}
		if (in_array($status, ['auth', 'error', 'no_imap', 'network'], true)) {
			$this->mailboxes->recordSyncDialFailure(
				$mailboxId,
				(int)($row['sync_fail_streak'] ?? 0),
				'status:' . $status,
			);
			$this->logPersonalSyncFail($mailboxId, $workspaceId, $status, 'status:' . $status);
		}
	}

	private function logPersonalSyncFail(int $mailboxId, int $workspaceId, string $reason, string $detail = ''): void
	{
		$this->logger->warning('personal_mail.sync_fail', [
			'event' => 'personal_mail.sync_fail',
			'reason' => $reason,
			'detail' => mb_substr($detail, 0, 512),
			'mailboxId' => $mailboxId,
			'workspaceId' => $workspaceId,
		]);
	}

	/**
	 * Spec §6 / Zeus SF-03 — structured rate signal for ops scrapers (alert if >10% over a run).
	 * Counts hard exceptions only (cooldown skips are not failures).
	 */
	private function emitSyncFailRateAlert(int $mailboxes, int $errors, int $cooldownSkipped): void
	{
		$attempted = max(0, $mailboxes - $cooldownSkipped);
		if ($attempted < 3 || $errors < 1) {
			return;
		}
		$rate = $errors / $attempted;
		if ($rate <= 0.10) {
			return;
		}
		$this->logger->error('personal_mail.sync_fail_rate', [
			'event' => 'personal_mail.sync_fail_rate',
			'mailboxes' => $mailboxes,
			'attempted' => $attempted,
			'errors' => $errors,
			'cooldownSkipped' => $cooldownSkipped,
			'rate' => round($rate, 4),
			'threshold' => 0.10,
		]);
	}

	/**
	 * Sync every healthy shared mailbox in one workspace (manual “Sync now”).
	 *
	 * @return array{
	 *   mailboxes: int,
	 *   attempted: int,
	 *   ok: int,
	 *   ingested: int,
	 *   locked: int,
	 *   errors: int,
	 *   lastSyncAt: ?string,
	 *   message: string
	 * }
	 */
	public function syncWorkspace(int $workspaceId): array
	{
		if ($workspaceId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'workspaceId',
				$this->l10n->t('Workspace not found.'),
			);
		}
		$boxes = $this->mailboxes->listMailboxes($workspaceId, null);
		$ingested = 0;
		$refreshed = 0;
		$attempted = 0;
		$ok = 0;
		$locked = 0;
		$errors = 0;
		foreach ($boxes as $box) {
			if ((string)($box['status'] ?? '') === 'error') {
				continue;
			}
			$id = (int)($box['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$attempted++;
			try {
				$result = $this->syncOne($id, $workspaceId);
				$ingested += (int)($result['ingested'] ?? 0);
				$refreshed += (int)($result['refreshed'] ?? 0);
				$st = (string)($result['status'] ?? '');
				if ($st === 'ok') {
					$ok++;
				} elseif ($st === 'locked') {
					$locked++;
				} elseif (in_array($st, ['auth', 'error', 'missing', 'no_imap'], true)) {
					$errors++;
				}
			} catch (\Throwable $e) {
				$errors++;
				$this->logger->warning('CustomerCheck workspace mailbox sync failed', [
					'workspaceId' => $workspaceId,
					'mailboxId' => $id,
					'exception' => $e,
				]);
			}
		}

		$lastSyncAt = self::newestLastSyncAt($this->mailboxes->listMailboxes($workspaceId, null));
		$boxCount = count($boxes);
		$multi = $boxCount > 1;

		if ($boxes === []) {
			$message = $this->l10n->t('No shared mailbox is connected for this workspace.');
		} elseif ($attempted < 1) {
			$message = $multi
				? $this->l10n->t('All shared mailboxes need attention before sync. Open Team & mailbox settings.')
				: $this->l10n->t('Shared mailbox needs attention before sync. Open Team & mailbox settings.');
		} elseif ($locked > 0 && $ok < 1 && $errors < 1) {
			$message = $multi
				? $this->l10n->t('Sync already running for one or more mailboxes. Try again in a moment.')
				: $this->l10n->t('Sync already running for this mailbox. Try again in a moment.');
		} elseif ($errors > 0 && $ok < 1) {
			$message = $multi
				? $this->l10n->t('Could not sync the shared mailboxes. Check Team & mailbox settings.')
				: $this->l10n->t('Could not sync the shared mailbox. Check Team & mailbox settings.');
		} elseif ($errors > 0 && $ok > 0) {
			$message = $ingested > 0
				? $this->l10n->n(
					'Synced %n new message. Some mailboxes need attention in Team & mailbox settings.',
					'Synced %n new messages. Some mailboxes need attention in Team & mailbox settings.',
					$ingested,
				)
				: $this->l10n->t('Some mailboxes synced; others need attention in Team & mailbox settings.');
		} elseif ($ingested > 0) {
			$message = $this->l10n->n(
				'Synced %n new message.',
				'Synced %n new messages.',
				$ingested,
			);
		} elseif ($refreshed > 0) {
			$message = $this->l10n->n(
				'Updated %n existing message for matching.',
				'Updated %n existing messages for matching.',
				$refreshed,
			);
		} else {
			$message = $multi
				? $this->l10n->t('All shared mailboxes are up to date.')
				: $this->l10n->t('Shared mailbox is up to date.');
		}

		return [
			'mailboxes' => $boxCount,
			'attempted' => $attempted,
			'ok' => $ok,
			'ingested' => $ingested,
			'refreshed' => $refreshed,
			'locked' => $locked,
			'errors' => $errors,
			'lastSyncAt' => $lastSyncAt,
			'message' => $message,
		];
	}

	/**
	 * Backfill missing To/CC, preview, and forward hints on open indexed mail via IMAP
	 * so reconcileKnownSharedMail can file known customers (production rows indexed
	 * before body preview persistence or with empty headers).
	 *
	 * @return int number of rows patched
	 */
	public function repairOpenMailMatchData(int $workspaceId, int $maxProbes = Constants::MAIL_FORWARD_BODY_PROBE_CAP): int
	{
		if ($workspaceId <= 0 || $maxProbes <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return 0;
		}
		$exclude = $this->matchExcludeEmails($workspaceId);
		$patched = 0;
		$afterId = 0;
		$remaining = $maxProbes;
		while ($remaining > 0) {
			$candidates = $this->mailboxes->listOpenMailForRepair($workspaceId, $afterId, $remaining);
			if ($candidates === []) {
				break;
			}
			/** @var array<string, array{host: string, port: int, user: string, secret: string, enc: string}> $mailboxCreds */
			$mailboxCreds = [];
			/** @var array<string, list<array<string, mixed>>> $byFolder */
			$byFolder = [];
			foreach ($candidates as $row) {
				$afterId = max($afterId, (int)$row['id']);
				$uid = trim((string)$row['messageUid']);
				if ($uid === '' || !preg_match('/^\d+$/', $uid)) {
					continue;
				}
				$toEmpty = trim((string)$row['toAddrs']) === '' || trim((string)$row['toAddrs']) === '[]';
				$hintsEmpty = trim((string)$row['forwardFromHints']) === '' || trim((string)$row['forwardFromHints']) === '[]';
				$previewEmpty = trim((string)$row['preview']) === '';
				$to = [];
				$cc = [];
				if (!$toEmpty) {
					$decoded = json_decode((string)$row['toAddrs'], true);
					if (is_array($decoded)) {
						foreach ($decoded as $addr) {
							$to[] = (string)$addr;
						}
					}
				}
				$headerHints = MailForwardLogic::hintsFromRedirectHeaders(null, null);
				if ($hintsEmpty && !$previewEmpty) {
					$fromPreview = MailForwardLogic::hintsFromBodyPrefix((string)$row['preview'], $exclude);
					if ($fromPreview !== []) {
						if ($this->mailboxes->patchOpenMailForRematch(
							$workspaceId,
							(int)$row['id'],
							null,
							null,
							$fromPreview,
							null,
						)) {
							$patched++;
						}
						$remaining--;
						continue;
					}
				}
				$needsBody = $hintsEmpty && MailForwardLogic::needsBodyProbe(
					(string)$row['from'],
					$to,
					$cc,
					(string)$row['subject'],
					$exclude,
					$headerHints,
				);
				if (!$toEmpty && !$needsBody) {
					continue;
				}
				$mailboxId = (int)$row['mailboxId'];
				$folderPath = (string)$row['folderPath'];
				$key = $mailboxId . "\0" . $folderPath;
				$byFolder[$key][] = $row;
			}
			if ($byFolder === []) {
				if (count($candidates) < $remaining) {
					break;
				}
				continue;
			}
			foreach ($byFolder as $key => $rows) {
				if ($remaining <= 0) {
					break;
				}
				[$mailboxIdRaw, $folderPath] = explode("\0", $key, 2);
				$mailboxId = (int)$mailboxIdRaw;
				if ($mailboxId <= 0) {
					continue;
				}
				if (!isset($mailboxCreds[(string)$mailboxId])) {
					$mailboxCreds[(string)$mailboxId] = $this->loadMailboxImapCreds($mailboxId, $workspaceId);
				}
				$creds = $mailboxCreds[(string)$mailboxId];
				if ($creds === []) {
					continue;
				}
				$uids = [];
				foreach ($rows as $row) {
					$uids[] = (string)$row['messageUid'];
				}
				$uids = array_slice(array_values(array_unique($uids)), 0, $remaining);
				if ($uids === []) {
					continue;
				}
				$headersByUid = ImapLiveProbe::fetchUidHeadersForUids(
					$creds['host'],
					$creds['port'],
					$creds['user'],
					$creds['secret'],
					$folderPath,
					$uids,
				);
				$bodyUids = [];
				foreach ($rows as $row) {
					$uid = (string)$row['messageUid'];
					$toEmpty = trim((string)$row['toAddrs']) === '' || trim((string)$row['toAddrs']) === '[]';
					$hintsEmpty = trim((string)$row['forwardFromHints']) === '' || trim((string)$row['forwardFromHints']) === '[]';
					$previewEmpty = trim((string)$row['preview']) === '';
					$hdr = $headersByUid[$uid] ?? null;
					$to = $hdr !== null && isset($hdr['to']) ? $hdr['to'] : [];
					$cc = $hdr !== null && isset($hdr['cc']) ? $hdr['cc'] : [];
					$redirectHints = $hdr !== null
						? MailForwardLogic::hintsFromRedirectHeaders($hdr['xOriginalFrom'] ?? null, $hdr['replyTo'] ?? null)
						: [];
					if ($hintsEmpty && MailForwardLogic::needsBodyProbe(
						$hdr !== null ? (string)$hdr['from'] : (string)$row['from'],
						$to,
						$cc,
						(string)$row['subject'],
						$exclude,
						$redirectHints,
					)) {
						$bodyUids[] = $uid;
					}
				}
				$bodyUids = array_slice(array_values(array_unique($bodyUids)), 0, $remaining);
				$bodyByUid = $bodyUids !== []
					? ImapLiveProbe::peekTextPrefixesForUids(
						$creds['host'],
						$creds['port'],
						$creds['user'],
						$creds['secret'],
						$folderPath,
						$bodyUids,
					)
					: [];
				foreach ($rows as $row) {
					if ($remaining <= 0) {
						break 2;
					}
					$uid = (string)$row['messageUid'];
					$mailId = (int)$row['id'];
					$hdr = $headersByUid[$uid] ?? null;
					$recipients = null;
					$fromAddr = null;
					if ($hdr !== null) {
						$toEmpty = trim((string)$row['toAddrs']) === '' || trim((string)$row['toAddrs']) === '[]';
						if ($toEmpty) {
							$recipients = array_merge(
								isset($hdr['to']) && is_array($hdr['to']) ? $hdr['to'] : [],
								isset($hdr['cc']) && is_array($hdr['cc']) ? $hdr['cc'] : [],
							);
						}
						if (trim((string)$row['from']) === '' && trim((string)$hdr['from']) !== '') {
							$fromAddr = (string)$hdr['from'];
						}
					}
					$bodyPrefix = $bodyByUid[$uid] ?? null;
					$forwardHints = null;
					$preview = null;
					if ($bodyPrefix !== null && $bodyPrefix !== '') {
						$preview = $bodyPrefix;
						$forwardHints = MailForwardLogic::hintsFromBodyPrefix($bodyPrefix, $exclude);
					} elseif (trim((string)$row['preview']) !== '') {
						$forwardHints = MailForwardLogic::hintsFromBodyPrefix((string)$row['preview'], $exclude);
						if ($forwardHints === []) {
							$forwardHints = null;
						}
					} elseif ($hdr !== null) {
						$forwardHints = MailForwardLogic::hintsFromRedirectHeaders(
							$hdr['xOriginalFrom'] ?? null,
							$hdr['replyTo'] ?? null,
						);
						if ($forwardHints === []) {
							$forwardHints = null;
						}
					}
					if ($this->mailboxes->patchOpenMailForRematch(
						$workspaceId,
						$mailId,
						$recipients,
						$fromAddr,
						$forwardHints,
						$preview,
					)) {
						$patched++;
					}
					$remaining--;
				}
			}
			if (count($candidates) < $remaining) {
				break;
			}
		}

		return $patched;
	}

	/**
	 * @return array{host: string, port: int, user: string, secret: string, enc: string}|array{}
	 */
	private function loadMailboxImapCreds(int $mailboxId, int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false || (string)($row['status'] ?? '') === 'error') {
			return [];
		}
		try {
			$secret = $this->crypto->decrypt((string)$row['secret_enc']);
		} catch (\Throwable) {
			return [];
		}
		$host = ImapConnection::normalizeHost((string)$row['imap_host']);
		$port = (int)$row['imap_port'];
		$enc = ImapConnection::resolveImapEnc((string)$row['imap_enc'], $port > 0 ? $port : 993);
		if ($port <= 0) {
			$port = $enc === ImapConnection::ENC_TLS ? 143 : 993;
		}
		if ($host === '' || ImapConnection::isBlockedMailHost($host)) {
			return [];
		}

		return [
			'host' => $host,
			'port' => $port,
			'user' => ImapConnection::normalizeUsername((string)$row['username']),
			'secret' => $secret,
			'enc' => $enc,
		];
	}

	/**
	 * @param list<array<string, mixed>> $boxes
	 */
	private static function newestLastSyncAt(array $boxes): ?string
	{
		$best = null;
		foreach ($boxes as $box) {
			$at = $box['lastSyncAt'] ?? null;
			if (!is_string($at) || trim($at) === '') {
				continue;
			}
			if ($best === null || $at > $best) {
				$best = $at;
			}
		}

		return $best;
	}

	/**
	 * @param list<array<string, mixed>> $boxes
	 * @return array{mailboxCount: int, lastSyncAt: ?string, backgroundIntervalMinutes: int}|null
	 */
	public static function mailSyncSummaryFromBoxes(array $boxes): ?array
	{
		if ($boxes === []) {
			return null;
		}

		return [
			'mailboxCount' => count($boxes),
			'lastSyncAt' => self::newestLastSyncAt($boxes),
			'backgroundIntervalMinutes' => (int)max(1, (int)round(Constants::MAILBOX_SYNC_INTERVAL_SECONDS / 60)),
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
			$result = $this->doSync($mailboxId, $workspaceId);
			if (($result['status'] ?? '') === 'ok') {
				$this->mailboxes->clearSyncDialFailure($mailboxId);
			}

			return $result;
		} catch (\Throwable $e) {
			$this->logger->error('CustomerCheck mailbox syncOne failed', [
				'mailboxId' => $mailboxId,
				'workspaceId' => $workspaceId,
				'exception' => $e,
			]);
			$this->logPersonalSyncFail($mailboxId, $workspaceId, 'exception', $e->getMessage());

			return ['ingested' => 0, 'status' => 'error'];
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
	 * @param bool $allowPersonal Owner disconnect only; team admin delete must leave personal alone.
	 * @throws \OCA\CustomerCheck\Exception\ValidationException when sync holds the lock
	 */
	public function deleteMailbox(int $workspaceId, int $mailboxId, bool $allowPersonal = false): void
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
			$this->mailboxes->delete($workspaceId, $mailboxId, $allowPersonal);
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * Assign path: never dial a personal mailbox (privacy / data-loss).
	 * Inbox Personal tab: pass the signed-in owner as $personalOwnerActor to allow dial.
	 */
	private function assertRemoteMutateAllowed(int $workspaceId, int $mailboxId, ?string $personalOwnerActor): void
	{
		if ($this->mailboxes->resolveMailboxKind($workspaceId, $mailboxId) !== MailAclLogic::KIND_PERSONAL) {
			return;
		}
		$actor = trim((string)$personalOwnerActor);
		if ($actor === '') {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Personal mailbox messages cannot be deleted or junked from Assign. Remove the Assign item only, or ask the owner.'),
			);
		}
		$owner = $this->mailboxes->resolveMailboxOwnerUserId($workspaceId, $mailboxId);
		if ($owner === null || !hash_equals($owner, $actor)) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Only the mailbox owner can delete or mark spam on personal mail.'),
			);
		}
	}

	/**
	 * Inbox Personal/Team delete — ACL read gate, IMAP dial (owner-safe for personal), CRM purge + Assign close.
	 *
	 * @return array{ok: true, dialedRemote: bool, assignClosed: int}
	 */
	public function deleteIndexedMessageFromInbox(
		int $workspaceId,
		int $messageId,
		string $actorUid,
		bool $canReadCompany,
		bool $canReadOrTriageMail,
	): array {
		$msg = $this->requireReadableIndexedMessage(
			$workspaceId,
			$messageId,
			$actorUid,
			$canReadCompany,
			$canReadOrTriageMail,
		);
		$kind = (string)($msg['mailboxKind'] ?? MailAclLogic::KIND_TEAM);
		$personalActor = $kind === MailAclLogic::KIND_PERSONAL ? $actorUid : null;
		$dialed = $this->deleteIndexedMessage($workspaceId, $messageId, $personalActor);
		$this->mailboxes->markRemoteDeleted($workspaceId, $messageId);
		$note = $dialed
			? $this->l10n->t('Deleted from mailbox')
			: $this->l10n->t('Removed from Assign (no live mail-server id yet)');
		$closed = $this->mailboxes->closeOpenAssignForMailMessage($workspaceId, $messageId, $actorUid, $note);

		return ['ok' => true, 'dialedRemote' => $dialed, 'assignClosed' => $closed];
	}

	/**
	 * Inbox Personal/Team mark-as-spam — same ACL as delete; Junk on host when live UID exists.
	 *
	 * @return array{ok: true, dialedRemote: bool, assignClosed: int}
	 */
	public function markIndexedMessageSpamFromInbox(
		int $workspaceId,
		int $messageId,
		string $actorUid,
		bool $canReadCompany,
		bool $canReadOrTriageMail,
	): array {
		$msg = $this->requireReadableIndexedMessage(
			$workspaceId,
			$messageId,
			$actorUid,
			$canReadCompany,
			$canReadOrTriageMail,
		);
		$kind = (string)($msg['mailboxKind'] ?? MailAclLogic::KIND_TEAM);
		$personalActor = $kind === MailAclLogic::KIND_PERSONAL ? $actorUid : null;
		$dialed = $this->moveIndexedMessageToJunk($workspaceId, $messageId, $personalActor);
		$this->mailboxes->markRemoteDeleted($workspaceId, $messageId);
		$note = $dialed
			? $this->l10n->t('Marked as spam on mailbox')
			: $this->l10n->t('Removed from Assign (no live mail-server id yet)');
		$closed = $this->mailboxes->closeOpenAssignForMailMessage($workspaceId, $messageId, $actorUid, $note);

		return ['ok' => true, 'dialedRemote' => $dialed, 'assignClosed' => $closed];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function requireReadableIndexedMessage(
		int $workspaceId,
		int $messageId,
		string $actorUid,
		bool $canReadCompany,
		bool $canReadOrTriageMail,
	): array {
		$msg = $this->mailboxes->findMessageForPreview($workspaceId, $messageId);
		if ($msg === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$actorUid = trim($actorUid);
		if ($actorUid === '') {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				$this->l10n->t('Sign in to manage this message.'),
			);
		}
		$companyId = isset($msg['companyId']) ? (int)$msg['companyId'] : 0;
		if (!MailAclLogic::canReadMessage(
			$actorUid,
			[
				'visibility' => (string)($msg['visibility'] ?? MailAclLogic::VISIBILITY_SHARED),
				'ownerUserId' => $msg['ownerUserId'] ?? null,
				'companyId' => $companyId > 0 ? $companyId : null,
				'mailboxKind' => (string)($msg['mailboxKind'] ?? MailAclLogic::KIND_TEAM),
			],
			$canReadCompany,
			$canReadOrTriageMail,
		)) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}

		return $msg;
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
		$exclude = $this->matchExcludeEmails($workspaceId);
		$n = 0;
		$refreshed = 0;
		foreach (array_slice($messages, 0, Constants::MAILBOX_SYNC_CAP) as $msg) {
			$enriched = $this->attachOriginalFromHints($msg, $exclude, null);
			$result = $this->mailboxes->ingestMessage($workspaceId, $mailboxId, $folderId, $enriched, $persons, $exclude);
			self::tallyIngest($result, $n, $refreshed);
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('last_sync_at', $qb->createNamedParameter($now))
			->set('status', $qb->createNamedParameter('ok'))
			->set('auth_fail_count', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->executeStatement();
		return ['ingested' => $n, 'refreshed' => $refreshed];
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
				'folders' => array_slice($folders, 0, Constants::MAILBOX_LIST_FOLDERS_MAX),
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
	 * Re-LIST selectable remote folders for an existing mailbox (UTF-8 paths).
	 * Used by personal-mail “Refresh folders” so newly created subfolders appear.
	 *
	 * @return list<string>
	 */
	public function listRemoteFoldersForMailbox(int $workspaceId, int $mailboxId): array
	{
		if ($workspaceId <= 0 || $mailboxId <= 0 || !$this->db->tableExists('crm_mailbox')) {
			return ['INBOX'];
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
			return ['INBOX'];
		}

		try {
			$auth = $this->mailboxAuth->resolve($row);
		} catch (\Throwable) {
			return ['INBOX'];
		}

		$host = ImapConnection::normalizeHost((string)($row['imap_host'] ?? ''));
		$port = (int)($row['imap_port'] ?? 993);
		$enc = ImapConnection::resolveImapEnc(
			isset($row['imap_enc']) ? (string)$row['imap_enc'] : null,
			$port > 0 ? $port : 993,
		);
		if ($port <= 0) {
			$port = $enc === ImapConnection::ENC_TLS ? 143 : 993;
		}
		$user = (string)$auth['user'];
		$secret = (string)$auth['secret'];
		$mode = (string)$auth['mode'];

		if ($host === '' || $user === '' || $secret === '' || ImapConnection::isBlockedMailHost($host)) {
			return ['INBOX'];
		}

		// OAuth / missing php-imap: live probe (SSL 993).
		if ($mode === MailboxAuthService::MODE_XOAUTH2 || !function_exists('imap_open') || !function_exists('imap_list')) {
			$probePort = $port === 143 ? 993 : ($port > 0 ? $port : 993);
			$probe = ImapLiveProbe::probeLogin(
				$host,
				$probePort,
				$user,
				$secret,
				15.0,
				$mode === MailboxAuthService::MODE_XOAUTH2 ? 'xoauth2' : 'password',
			);
			$folders = isset($probe['folders']) && is_array($probe['folders'])
				? array_values(array_filter(array_map('strval', $probe['folders'])))
				: [];
			return $this->normalizeDiscoveredFolders($folders);
		}

		if (function_exists('imap_errors')) {
			@imap_errors();
		}
		$stream = $this->openImapStream($host, $port, $enc, $user, $secret, false);
		$novalidate = false;
		if ($stream === false) {
			$stream = $this->openImapStream($host, $port, $enc, $user, $secret, true);
			$novalidate = $stream !== false;
		}
		if ($stream === false) {
			$probe = ImapLiveProbe::probeLogin($host, $port > 0 ? $port : 993, $user, $secret, 15.0);
			$folders = isset($probe['folders']) && is_array($probe['folders'])
				? array_values(array_filter(array_map('strval', $probe['folders'])))
				: [];
			return $this->normalizeDiscoveredFolders($folders);
		}
		try {
			return $this->normalizeDiscoveredFolders(
				$this->listRemoteFolders($stream, $host, $port, $enc, $novalidate),
			);
		} finally {
			@imap_close($stream);
		}
	}

	/**
	 * @param list<string> $folders
	 * @return list<string>
	 */
	private function normalizeDiscoveredFolders(array $folders): array
	{
		$out = [];
		$seen = [];
		foreach ($folders as $raw) {
			$path = ImapConnection::normalizeFolderPath((string)$raw);
			if ($path === '' || !ImapConnection::isSafeImapFolder($path)) {
				continue;
			}
			$key = \OCA\CustomerCheck\Support\ImapUtf7::folderKey($path);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $path;
			if (count($out) >= Constants::MAILBOX_LIST_FOLDERS_MAX) {
				break;
			}
		}

		return $out !== [] ? $out : ['INBOX'];
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
		$seen = [];
		foreach ($list as $full) {
			$path = (string)$full;
			if (str_starts_with($path, $root)) {
				$path = substr($path, strlen($root));
			}
			$path = ImapConnection::normalizeFolderPath($path);
			if ($path === '' || !ImapConnection::isSafeImapFolder($path)) {
				continue;
			}
			$key = \OCA\CustomerCheck\Support\ImapUtf7::folderKey($path);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $path;
			if (count($out) >= Constants::MAILBOX_LIST_FOLDERS_MAX) {
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

		$kind = strtolower(trim((string)($row['kind'] ?? MailAclLogic::KIND_TEAM)));
		if ($kind === MailAclLogic::KIND_PERSONAL) {
			$settings = $this->workspaces->getSettings($workspaceId);
			if (empty($settings['personalMailEnabled'])) {
				$this->logger->info('personal_mail.sync_skip', [
					'event' => 'personal_mail.sync_skip',
					'reason' => 'disabled',
					'mailboxId' => $mailboxId,
					'workspaceId' => $workspaceId,
				]);
				return ['ingested' => 0, 'status' => 'disabled'];
			}
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

		$authKind = strtolower(trim((string)($row['auth_kind'] ?? 'imap')));
		// OAuth mailboxes must use wire XOAUTH2 — php-imap has no XOAUTH2.
		if ($authKind === 'google' || $authKind === 'microsoft' || trim((string)($row['secret_enc'] ?? '')) === '') {
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
			$err = ImapConnection::collectErrors(
				function_exists('imap_errors') ? @imap_errors() : null,
				function_exists('imap_last_error') ? @imap_last_error() : null,
			);
			$errReason = ImapConnection::classifyError($err);
			// Only auth failures quarantine (US-002). Network/cert/unknown → dial backoff, not reconnect spam.
			if ($errReason === 'auth') {
				$status = $this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
				return ['ingested' => 0, 'status' => $status === 'error' ? 'error' : 'auth'];
			}
			$this->logger->warning('CustomerCheck mailbox sync IMAP open failed (non-auth)', [
				'mailboxId' => $mailboxId,
				'workspaceId' => $workspaceId,
				'reason' => $errReason,
				'detail' => ImapConnection::sanitizeError($err),
			]);
			return ['ingested' => 0, 'status' => $errReason === 'network' ? 'network' : 'error'];
		}

		$persons = $this->personIndex($workspaceId);
		$exclude = $this->matchExcludeEmails($workspaceId);
		$folders = $this->enabledFolders($mailboxId);
		$ingested = 0;
		$refreshed = 0;
		$forwardBodyProbes = 0;
		$bodyFetches = 0;
		try {
			foreach ($folders as $folder) {
				$folderPath = (string)$folder['folder_path'];
				if (!ImapConnection::isSafeImapFolder($folderPath)) {
					$this->logger->warning('CustomerCheck mailbox sync skipped unsafe IMAP folder', [
						'mailboxId' => $mailboxId,
						'workspaceId' => $workspaceId,
					]);
					continue;
				}
				$wirePath = ImapConnection::folderToWire($folderPath);
				$full = $mailboxPath . $wirePath;
				if (@imap_reopen($stream, $full) === false) {
					$this->logger->warning('CustomerCheck mailbox sync could not reopen IMAP folder', [
						'mailboxId' => $mailboxId,
						'workspaceId' => $workspaceId,
						'folder' => $folderPath,
					]);
					continue;
				}
				$n = @imap_num_msg($stream);
				if ($n === false || $n < 1) {
					continue;
				}
				$start = max(1, $n - Constants::MAILBOX_SYNC_CAP + 1);
				$scanned = 0;
				for ($i = $n; $i >= $start && $scanned < Constants::MAILBOX_SYNC_CAP; $i--) {
					try {
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
						$to = self::imapAddressList($hdr->to ?? null);
						$cc = self::imapAddressList($hdr->cc ?? null);
						$subject = isset($hdr->subject)
							? MimeHeader::decode((string)@imap_utf8($hdr->subject))
							: '';
						$sentAt = isset($hdr->date) ? date('Y-m-d H:i:s', strtotime((string)$hdr->date) ?: time()) : null;
						// php-imap headerinfo often omits Resent-From / X-Original-From — parse the raw header.
						$rawHdr = (string)(@imap_fetchheader($stream, $i) ?: '');
						$fwdHdr = $rawHdr !== '' ? ImapLiveProbe::parseRfc822Headers($rawHdr) : [
							'resentFrom' => null,
							'xOriginalFrom' => null,
						];
						$resentFrom = $fwdHdr['resentFrom'] ?? null;
						if (($resentFrom === null || $resentFrom === '') && isset($hdr->resentfromaddress)) {
							$resentRaw = trim((string)$hdr->resentfromaddress);
							$resentFrom = $resentRaw !== '' ? ImapLiveProbe::extractEmailAddress($resentRaw) : null;
						}
						$replyTo = $fwdHdr['replyTo'] ?? null;
						if (($replyTo === null || $replyTo === '') && !empty($hdr->reply_to) && is_array($hdr->reply_to)) {
							$rt = $hdr->reply_to[0];
							$replyTo = strtolower(trim(($rt->mailbox ?? '') . '@' . ($rt->host ?? '')));
							if ($replyTo === '@' || !str_contains($replyTo, '@')) {
								$replyTo = null;
							}
						}
						$msg = [
							'uid' => $uid,
							'from' => $from,
							'to' => $to,
							'cc' => $cc,
							'replyTo' => $replyTo,
							'resentFrom' => $resentFrom,
							'xOriginalFrom' => $fwdHdr['xOriginalFrom'] ?? null,
							'messageId' => $fwdHdr['messageId'] ?? null,
							'subject' => $subject,
							'snippet' => mb_substr($subject, 0, 200),
							'sentAt' => $sentAt,
							'direction' => 'inbound',
						];
						$bodyPrefix = null;
						// MH-05 / Q7: persist capped plain (+ HTML when part looks like HTML) during sync.
						if ($bodyFetches < Constants::MAIL_SYNC_BODY_FETCH_CAP) {
							$bodies = self::fetchPhpImapBodiesCapped($stream, $i);
							if ($bodies['plain'] !== '' || $bodies['html'] !== '') {
								if ($bodies['plain'] !== '') {
									$msg['bodyText'] = $bodies['plain'];
									$msg['preview'] = MailPreviewText::toPlainPreview(
										$bodies['plain'],
										Constants::MAIL_PREVIEW_MAX_BYTES,
									);
									$bodyPrefix = mb_substr($bodies['plain'], 0, Constants::MAIL_FORWARD_BODY_PEEK_BYTES);
								}
								if ($bodies['html'] !== '') {
									$msg['bodyHtml'] = $bodies['html'];
								}
								$bodyFetches++;
							}
						}
						$headerHints = MailForwardLogic::hintsFromRedirectHeaders(
							isset($msg['xOriginalFrom']) ? (string)$msg['xOriginalFrom'] : null,
							isset($msg['replyTo']) ? (string)$msg['replyTo'] : null,
						);
						if ($bodyPrefix === null
							&& MailForwardLogic::needsBodyProbe($from, $to, $cc, $subject, $exclude, $headerHints)
							&& $forwardBodyProbes < Constants::MAIL_FORWARD_BODY_PROBE_CAP) {
							// Part 1 only — never imap_body() (unbounded full MIME into memory).
							$rawBody = (string)(@imap_fetchbody($stream, $i, '1', FT_PEEK) ?: '');
							if ($rawBody !== '') {
								if (strlen($rawBody) > Constants::MAIL_FORWARD_BODY_PEEK_BYTES) {
									$rawBody = substr($rawBody, 0, Constants::MAIL_FORWARD_BODY_PEEK_BYTES);
								}
								$bodyPrefix = MailPreviewText::toPlainPreview(
									$rawBody,
									Constants::MAIL_FORWARD_BODY_PEEK_BYTES,
								);
								$forwardBodyProbes++;
							}
						}
						$enriched = $this->attachOriginalFromHints($msg, $exclude, $bodyPrefix);
						$result = $this->mailboxes->ingestMessage(
							$workspaceId,
							$mailboxId,
							(int)$folder['id'],
							$enriched,
							$persons,
							$exclude,
						);
						self::tallyIngest($result, $ingested, $refreshed);
						$scanned++;
					} catch (\Throwable $e) {
						$this->logger->warning('CustomerCheck mailbox sync skipped one message', [
							'mailboxId' => $mailboxId,
							'workspaceId' => $workspaceId,
							'exception' => $e,
						]);
					}
				}
			}
		} finally {
			@imap_close($stream);
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

		$this->logPersonalSyncOk($row, $workspaceId, $mailboxId, $ingested, $refreshed);

		return ['ingested' => $ingested, 'refreshed' => $refreshed, 'status' => 'ok'];
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
			$auth = $this->mailboxAuth->resolve($row);
		} catch (\Throwable) {
			$authKind = strtolower(trim((string)($row['auth_kind'] ?? 'imap')));
			// OAuth resolve already counted (and notified on ×3) via MailboxAuthService.
			if ($authKind !== 'google' && $authKind !== 'microsoft') {
				$this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
			}
			return ['ingested' => 0, 'status' => 'auth'];
		}

		$host = ImapConnection::normalizeHost((string)$row['imap_host']);
		$port = (int)$row['imap_port'];
		$enc = ImapConnection::resolveImapEnc((string)$row['imap_enc'], $port > 0 ? $port : 993);
		$user = $auth['user'];
		$secret = $auth['secret'];
		$authMode = $auth['mode'];
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
		// Live probe currently speaks SSL wrap (993). STARTTLS without php-imap is not supported
		// (OAuth always uses live probe — providers speak 993/SSL).
		if ($authMode !== MailboxAuthService::MODE_XOAUTH2 && ($enc !== ImapConnection::ENC_SSL || $port === 143)) {
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
		$exclude = $this->matchExcludeEmails($workspaceId);
		$folders = $this->enabledFolders($mailboxId);
		$ingested = 0;
		$refreshed = 0;
		foreach ($folders as $folder) {
			if ($ingested + $refreshed >= Constants::MAILBOX_SYNC_CAP) {
				break;
			}
			$folderPath = (string)$folder['folder_path'];
			if (!ImapConnection::isSafeImapFolder($folderPath)) {
				continue;
			}
			$result = ImapLiveProbe::fetchRecentMessages(
				$host,
				$port > 0 ? $port : 993,
				$user,
				$secret,
				$folderPath,
				Constants::MAIL_SYNC_MAX_MESSAGES,
				20.0,
				$authMode,
			);
			if (!$result['ok']) {
				if (($result['reason'] ?? '') === 'auth') {
					$status = $this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
					return ['ingested' => 0, 'status' => $status === 'error' ? 'error' : 'auth'];
				}
				$this->logger->warning('CustomerCheck live-probe sync folder failed', [
					'mailboxId' => $mailboxId,
					'folder' => $folderPath,
					'detail' => (string)($result['detail'] ?? ''),
				]);
				continue;
			}
			$probeUids = [];
			$bodyFetchUids = [];
			foreach ($result['messages'] as $msg) {
				$uid = (string)($msg['uid'] ?? '');
				if ($uid !== '' && count($bodyFetchUids) < Constants::MAIL_SYNC_BODY_FETCH_CAP) {
					$bodyFetchUids[] = $uid;
				}
				$headerHints = MailForwardLogic::hintsFromRedirectHeaders(
					isset($msg['xOriginalFrom']) ? (string)$msg['xOriginalFrom'] : null,
					isset($msg['xOriginalTo']) ? (string)$msg['xOriginalTo'] : null,
					isset($msg['deliveredTo']) ? (string)$msg['deliveredTo'] : null,
					isset($msg['resentFrom']) ? (string)$msg['resentFrom'] : null,
				);
				$from = (string)($msg['from'] ?? '');
				$to = (string)($msg['to'] ?? '');
				$cc = (string)($msg['cc'] ?? '');
				if (MailForwardLogic::shouldProbeBody(
					$from,
					$to,
					$cc,
					(string)($msg['subject'] ?? ''),
					$exclude,
					$headerHints,
				)) {
					if ($uid !== '') {
						$probeUids[] = $uid;
					}
				}
			}
			$bodyFetchUids = array_values(array_unique($bodyFetchUids));
			$fullBodyByUid = [];
			if ($bodyFetchUids !== []) {
				$fullBodyByUid = ImapLiveProbe::peekBodiesForUids(
					$host,
					$port > 0 ? $port : 993,
					$user,
					$secret,
					$folderPath,
					$bodyFetchUids,
					Constants::MAIL_BODY_PLAIN_MAX_BYTES,
					20.0,
					$authMode,
				);
			}
			$probeUids = array_values(array_diff(
				array_unique($probeUids),
				$bodyFetchUids,
			));
			$probeUids = array_slice($probeUids, 0, Constants::MAIL_FORWARD_BODY_PROBE_CAP);
			$bodyByUid = [];
			foreach ($fullBodyByUid as $u => $parts) {
				if (is_array($parts) && trim((string)($parts['plain'] ?? '')) !== '') {
					$bodyByUid[$u] = (string)$parts['plain'];
				}
			}
			if ($probeUids !== []) {
				$fwdPeek = ImapLiveProbe::peekTextPrefixesForUids(
					$host,
					$port > 0 ? $port : 993,
					$user,
					$secret,
					$folderPath,
					$probeUids,
					Constants::MAIL_FORWARD_BODY_PEEK_BYTES,
					20.0,
					$authMode,
				);
				foreach ($fwdPeek as $u => $txt) {
					$bodyByUid[$u] = $txt;
				}
			}
			$scanned = 0;
			foreach ($result['messages'] as $msg) {
				if ($scanned >= Constants::MAILBOX_SYNC_CAP) {
					break;
				}
				try {
					$uid = (string)($msg['uid'] ?? '');
					$bodyPrefix = ($uid !== '' && isset($bodyByUid[$uid])) ? $bodyByUid[$uid] : null;
					if ($uid !== '' && isset($fullBodyByUid[$uid]) && is_array($fullBodyByUid[$uid])) {
						$plainPart = trim((string)($fullBodyByUid[$uid]['plain'] ?? ''));
						$htmlPart = trim((string)($fullBodyByUid[$uid]['html'] ?? ''));
						if ($plainPart !== '') {
							$msg['bodyText'] = $plainPart;
							$msg['preview'] = MailPreviewText::toPlainPreview(
								$plainPart,
								Constants::MAIL_PREVIEW_MAX_BYTES,
							);
						}
						if ($htmlPart !== '') {
							$msg['bodyHtml'] = $htmlPart;
						}
					}
					$enriched = $this->attachOriginalFromHints($msg, $exclude, $bodyPrefix);
					$ingest = $this->mailboxes->ingestMessage(
						$workspaceId,
						$mailboxId,
						(int)$folder['id'],
						$enriched,
						$persons,
						$exclude,
					);
					self::tallyIngest($ingest, $ingested, $refreshed);
					$scanned++;
				} catch (\Throwable $e) {
					$this->logger->warning('CustomerCheck live-probe sync skipped one message', [
						'mailboxId' => $mailboxId,
						'workspaceId' => $workspaceId,
						'exception' => $e,
					]);
				}
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

		$this->logPersonalSyncOk($row, $workspaceId, $mailboxId, $ingested, $refreshed);

		return ['ingested' => $ingested, 'refreshed' => $refreshed, 'status' => 'ok'];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function logPersonalSyncOk(array $row, int $workspaceId, int $mailboxId, int $ingested, int $refreshed): void
	{
		$kind = strtolower(trim((string)($row['kind'] ?? MailAclLogic::KIND_TEAM)));
		if ($kind !== MailAclLogic::KIND_PERSONAL) {
			return;
		}
		$this->logger->info('personal_mail.sync_ok', [
			'event' => 'personal_mail.sync_ok',
			'workspaceId' => $workspaceId,
			'mailboxId' => $mailboxId,
			'ingested' => $ingested,
			'refreshed' => $refreshed,
		]);
	}

	/**
	 * @param array{inserted?: bool, touched?: bool} $result
	 */
	private static function tallyIngest(array $result, int &$ingested, int &$refreshed): void
	{
		if (!empty($result['inserted'])) {
			$ingested++;
		} elseif (!empty($result['touched'])) {
			$refreshed++;
		}
	}

	/**
	 * @param array<string, mixed> $msg
	 * @param list<string> $excludeEmails
	 * @return array<string, mixed>
	 */
	private function attachOriginalFromHints(array $msg, array $excludeEmails, ?string $bodyPrefix): array
	{
		$headerHints = MailForwardLogic::hintsFromRedirectHeaders(
			isset($msg['xOriginalFrom']) ? (string)$msg['xOriginalFrom'] : null,
			isset($msg['replyTo']) ? (string)$msg['replyTo'] : null,
		);
		$bodyHints = [];
		if ($bodyPrefix !== null && $bodyPrefix !== '') {
			$plain = MailPreviewText::toPlainPreview($bodyPrefix, Constants::MAIL_PREVIEW_MAX_BYTES);
			if ($plain !== '') {
				$msg['preview'] = $plain;
				$bodyHints = MailForwardLogic::hintsFromBodyPrefix($plain, $excludeEmails);
			}
		} elseif (isset($msg['forwardBodyPrefix']) && is_string($msg['forwardBodyPrefix'])) {
			$bodyHints = MailForwardLogic::hintsFromBodyPrefix($msg['forwardBodyPrefix'], $excludeEmails);
		}
		$msg['originalFromHints'] = MailForwardLogic::mergeHints($headerHints, $bodyHints, $excludeEmails);

		return $msg;
	}

	/**
	 * Cap-safe body fetch for php-imap sync (MH-05 / Q7). Never uses imap_body().
	 *
	 * @param resource|\IMAP\Connection $stream
	 * @return array{plain: string, html: string}
	 */
	private static function fetchPhpImapBodiesCapped($stream, int $msgNo): array
	{
		$plain = '';
		$html = '';
		$raw1 = (string)(@imap_fetchbody($stream, $msgNo, '1', FT_PEEK) ?: '');
		if ($raw1 === '') {
			return ['plain' => '', 'html' => ''];
		}
		if (strlen($raw1) > Constants::MAIL_BODY_PLAIN_MAX_BYTES) {
			$raw1 = substr($raw1, 0, Constants::MAIL_BODY_PLAIN_MAX_BYTES);
		}
		$plain = MailPreviewText::toPlainPreview($raw1, Constants::MAIL_BODY_PLAIN_MAX_BYTES);
		$looksHtml = (bool)preg_match('/<(?:html|body|div|p|br|table)\b/i', $raw1);
		if ($looksHtml) {
			$html = $raw1;
		} else {
			$raw2 = (string)(@imap_fetchbody($stream, $msgNo, '2', FT_PEEK) ?: '');
			if ($raw2 !== '' && preg_match('/<(?:html|body|div|p|br|table)\b/i', $raw2) === 1) {
				if (strlen($raw2) > Constants::MAIL_BODY_HTML_MAX_BYTES) {
					$raw2 = substr($raw2, 0, Constants::MAIL_BODY_HTML_MAX_BYTES);
				}
				$html = $raw2;
			}
		}

		return ['plain' => $plain, 'html' => $html];
	}

	/**
	 * @param mixed $list php-imap to/cc address objects
	 * @return list<string>
	 */
	private static function imapAddressList(mixed $list): array
	{
		if (!is_array($list) || $list === []) {
			return [];
		}
		$out = [];
		foreach ($list as $addr) {
			if (!is_object($addr)) {
				continue;
			}
			$mailbox = trim((string)($addr->mailbox ?? ''));
			$host = trim((string)($addr->host ?? ''));
			if ($mailbox === '' || $host === '') {
				continue;
			}
			$email = strtolower($mailbox . '@' . $host);
			$out[$email] = true;
		}

		return array_keys($out);
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
			->andWhere($qb->expr()->isNotNull('p.email'))
			->andWhere($qb->expr()->isNull('c.archived_at'));
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

	/**
	 * Person email → company index for MailMatchLogic (ingest + Assign rematch).
	 *
	 * @return list<array{email: string, companyId: int}>
	 */
	public function matchPersonIndex(int $workspaceId): array
	{
		return $this->personIndex($workspaceId);
	}

	/**
	 * Addresses that must never auto-match onto a customer (From only).
	 * Shared mailbox identities + Nextcloud emails of workspace members.
	 *
	 * @return list<string>
	 */
	private function matchExcludeEmails(int $workspaceId): array
	{
		$out = [];
		foreach ($this->mailboxes->listMailboxes($workspaceId, null) as $box) {
			$email = strtolower(trim((string)($box['emailAddress'] ?? '')));
			if ($email !== '' && str_contains($email, '@')) {
				$out[] = $email;
			}
		}
		foreach ($this->workspaces->listMemberEmails($workspaceId) as $email) {
			$out[] = $email;
		}

		return array_values(array_unique($out));
	}

	/**
	 * @return list<string>
	 */
	public function matchExcludeEmailsPublic(int $workspaceId): array
	{
		return $this->matchExcludeEmails($workspaceId);
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
