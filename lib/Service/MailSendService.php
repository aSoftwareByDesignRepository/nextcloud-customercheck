<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\SharedMailboxSmtpClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;

/**
 * Shared-mailbox SMTP send — product §14.3.
 *
 * Prefer mailbox SMTP credentials when present. If SMTP host is configured,
 * decrypt/AUTH must succeed (fail closed — never silent IMailer From-spoof).
 * Instance IMailer is used only when SMTP is intentionally unset.
 * Idempotency key is claimed before SMTP so retries never double-send.
 * Secrets never returned. Viewer → 403 via capability mail.send.
 */
class MailSendService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IMailer $mailer,
		private readonly ICrypto $crypto,
		private readonly RateLimitService $rateLimit,
		private readonly EmailTouchService $emailTouch,
		private readonly TimelineService $timeline,
		private readonly MailboxService $mailboxes,
		private readonly FilesAccessService $filesAccess,
		private readonly ILockingProvider $locking,
		private readonly ITimeFactory $timeFactory,
		private readonly IURLGenerator $url,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @param array{
	 *   mailboxId: int,
	 *   to: string,
	 *   subject: string,
	 *   body: string,
	 *   companyId?: int,
	 *   idempotencyKey?: string,
	 *   attachmentFileIds?: list<int>,
	 *   reuseDraftId?: int
	 * } $data
	 * @return array<string, mixed>
	 */
	public function send(int $workspaceId, array $data, string $actor, bool $canMailSend): array
	{
		if (!$canMailSend) {
			throw ValidationException::field(
				Constants::ERR_MAIL_SEND_DENIED,
				'mail.send',
				$this->l10n->t('You do not have permission to send from the shared mailbox.'),
			);
		}

		$mailboxId = (int)($data['mailboxId'] ?? 0);
		$to = trim((string)($data['to'] ?? ''));
		$subject = trim((string)($data['subject'] ?? ''));
		$body = (string)($data['body'] ?? '');
		if ($mailboxId <= 0 || $to === '' || $subject === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'to', $this->l10n->t('Mailbox, recipient and subject are required.'));
		}
		if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
			throw ValidationException::field(Constants::ERR_EMAIL_INVALID, 'to', $this->l10n->t('Enter a valid recipient email.'));
		}
		if (strlen($body) > 200_000) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'body', $this->l10n->t('Message body is too large (max 200 KiB).'));
		}

		// Cross-book guard: never write email_touch / timeline onto a foreign company.
		$companyId = isset($data['companyId']) ? (int)$data['companyId'] : 0;
		if ($companyId > 0) {
			$this->assertCompanyInWorkspace($workspaceId, $companyId);
		}

		// Resolve Files attachments via actor ACL before rate-limit / SMTP.
		$rawIds = $data['attachmentFileIds'] ?? [];
		$attachmentIds = is_array($rawIds) ? $rawIds : [];
		$attachments = $attachmentIds !== []
			? $this->filesAccess->loadMailAttachments($actor, $attachmentIds)
			: [];

		$reuseDraftId = isset($data['reuseDraftId']) ? (int)$data['reuseDraftId'] : 0;

		// Idempotency-Key is mandatory — empty keys would skip the exclusive lock
		// and allow concurrent double-sends (§14.3 / §18.4).
		$idem = trim((string)($data['idempotencyKey'] ?? ''));
		if ($idem === '' || strlen($idem) > 64 || preg_match('/^[A-Za-z0-9:_.-]+$/', $idem) !== 1) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'idempotencyKey',
				$this->l10n->t('A valid Idempotency-Key is required to send from the shared mailbox.'),
			);
		}

		$lockKey = 'crm_mail_send/' . $workspaceId . '/' . $idem;
		$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			// Replay lookup must run before rate-limit tokens burn (§14.3 / §18.4).
			$existing = $this->findByIdempotency($workspaceId, $idem);
			if ($existing !== null) {
				return $existing;
			}
			$this->assertSendRateLimits($actor, $workspaceId);
			return $this->deliver($workspaceId, $mailboxId, $to, $subject, $body, $actor, $idem, $data, $attachments, $reuseDraftId);
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Fail closed when the client tries to attach a send to another book’s company.
	 *
	 * @throws NotFoundException
	 */
	private function assertCompanyInWorkspace(int $workspaceId, int $companyId): void
	{
		if ($workspaceId <= 0 || $companyId <= 0 || !$this->db->tableExists('crm_company')) {
			throw new NotFoundException($this->l10n->t('This company is not in the current workspace.'));
		}
		$check = $this->db->getQueryBuilder();
		$check->select('id')->from('crm_company')
			->where($check->expr()->eq('id', $check->createNamedParameter($companyId)))
			->andWhere($check->expr()->eq('workspace_id', $check->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $check->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('This company is not in the current workspace.'));
		}
	}

	private function assertSendRateLimits(string $actor, int $workspaceId): void
	{
		if (!$this->rateLimit->allow('crm_mail_user:' . $actor, Constants::MAIL_SEND_USER_LIMIT, Constants::MAIL_SEND_USER_WINDOW)) {
			throw ValidationException::field(Constants::ERR_RATE_LIMITED, 'rate', $this->l10n->t('Send rate limit reached for your user.'));
		}
		if (!$this->rateLimit->allow('crm_mail_ws:' . $workspaceId, Constants::MAIL_SEND_WS_LIMIT, Constants::MAIL_SEND_WS_WINDOW)) {
			throw ValidationException::field(Constants::ERR_RATE_LIMITED, 'rate', $this->l10n->t('Send rate limit reached for this workspace.'));
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<array{fileId: int, filename: string, mime: string, data: string, size: int}> $attachments
	 * @return array<string, mixed>
	 */
	private function deliver(
		int $workspaceId,
		int $mailboxId,
		string $to,
		string $subject,
		string $body,
		string $actor,
		string $idem,
		array $data,
		array $attachments = [],
		int $reuseDraftId = 0,
	): array {
		$box = $this->loadMailbox($workspaceId, $mailboxId);
		$from = (string)$box['email_address'];
		$workspaceName = $this->workspaceName($workspaceId);
		$disclosure = $this->mailboxes->disclosure((string)$box['label'], $workspaceName);
		$fullBody = $body . "\n\n—\n" . $disclosure;
		$companyId = isset($data['companyId']) ? (int)$data['companyId'] : 0;

		// Claim the idempotency key BEFORE SMTP so a successful DATA + failed
		// persist cannot invite a retry double-send (§14.3 / §18.4).
		$claimId = 0;
		$reused = false;
		if ($idem !== '') {
			try {
				if ($reuseDraftId > 0) {
					$claimId = $this->claimFailedDraft(
						$reuseDraftId,
						$workspaceId,
						$mailboxId,
						$to,
						$subject,
						$body,
						$actor,
						$idem,
						$companyId > 0 ? $companyId : null,
					);
					$reused = true;
				} else {
					$claimId = $this->saveDraft(
						$workspaceId,
						$mailboxId,
						$to,
						$subject,
						$body,
						$actor,
						$idem,
						$companyId > 0 ? $companyId : null,
					);
				}
			} catch (\Throwable $e) {
				if ($this->isUniqueConstraintViolation($e)) {
					$replay = $this->findByIdempotency($workspaceId, $idem);
					if ($replay !== null) {
						return $replay;
					}
				}
				throw $e;
			}
		}

		try {
			$this->dispatchMessage($box, $from, $to, $subject, $fullBody, $attachments);
		} catch (\Throwable $e) {
			if ($reused && $claimId > 0) {
				// Keep the same failed-draft row — release the claim so it stays in the inbox.
				$this->releaseFailedDraftClaim($claimId);
			} elseif ($claimId > 0) {
				// Drop the claim so a retry with the same key can still send.
				$this->deleteDraft($claimId);
				$draftId = $this->saveDraft(
					$workspaceId,
					$mailboxId,
					$to,
					$subject,
					$body,
					$actor,
					null,
					$companyId > 0 ? $companyId : null,
				);
			} else {
				$draftId = $this->saveDraft(
					$workspaceId,
					$mailboxId,
					$to,
					$subject,
					$body,
					$actor,
					null,
					$companyId > 0 ? $companyId : null,
				);
			}
			$keptId = $reused ? $claimId : (int)($draftId ?? 0);
			$msg = $e instanceof ValidationException
				? $e->getMessage()
				: $this->l10n->t('SMTP send failed — draft kept (id %s).', [(string)$keptId]);
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'smtp',
				$msg,
			);
		}

		$now = $this->timeFactory->getDateTime();
		if ($companyId > 0) {
			$this->emailTouch->logCompose($companyId, $to, $subject, $actor, $from);
			$this->timeline->append($companyId, 'mail_sent', $actor, [
				'subject' => $subject,
				'to' => $to,
				'mailboxId' => $mailboxId,
				'attachmentCount' => count($attachments),
			]);
		}

		return [
			'sent' => true,
			'from' => $from,
			'to' => $to,
			'subject' => $subject,
			'disclosure' => $disclosure,
			'attachmentCount' => count($attachments),
			'sentAt' => $now->format(DATE_ATOM),
			'companyUrl' => $companyId > 0
				? $this->url->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $companyId])
				: null,
		];
	}

	/**
	 * CAS: attach an idempotency key to an existing failed draft (null key).
	 *
	 * @throws NotFoundException
	 */
	private function claimFailedDraft(
		int $draftId,
		int $workspaceId,
		int $mailboxId,
		string $to,
		string $subject,
		string $body,
		string $actor,
		string $idem,
		?int $companyId,
	): int {
		if (!$this->db->tableExists('crm_mail_dft') || $draftId <= 0) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists.'));
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_mail_dft')
			->set('mailbox_id', $qb->createNamedParameter($mailboxId))
			->set('to_addrs', $qb->createNamedParameter($to))
			->set('subject', $qb->createNamedParameter(mb_substr($subject, 0, 512)))
			->set('body_text', $qb->createNamedParameter($body))
			->set('idempotency_key', $qb->createNamedParameter($idem))
			->set('created_by', $qb->createNamedParameter($actor))
			->set('company_id', $qb->createNamedParameter($companyId))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($draftId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->isNull('idempotency_key'))
			->executeStatement();
		if ($affected !== 1) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists (or was already sent).'));
		}

		return $draftId;
	}

	private function releaseFailedDraftClaim(int $draftId): void
	{
		if ($draftId <= 0 || !$this->db->tableExists('crm_mail_dft')) {
			return;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mail_dft')
			->set('idempotency_key', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($draftId)))
			->executeStatement();
	}

	/**
	 * @param array<string, mixed> $box
	 * @param list<array{fileId: int, filename: string, mime: string, data: string, size: int}> $attachments
	 */
	private function dispatchMessage(
		array $box,
		string $from,
		string $to,
		string $subject,
		string $fullBody,
		array $attachments = [],
	): void {
		$smtpHost = trim((string)($box['smtp_host'] ?? ''));
		$secret = '';
		$decryptFailed = false;
		if (!empty($box['secret_enc'])) {
			try {
				$secret = $this->crypto->decrypt((string)$box['secret_enc']);
			} catch (\Throwable) {
				$secret = '';
				$decryptFailed = true;
			}
		}

		// Fail closed: configured SMTP must AUTH. Never silent-IMailer spoof
		// of the shared From when credentials are broken/rotated.
		if ($smtpHost !== '') {
			if ($secret === '') {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'smtp',
					$decryptFailed
						? $this->l10n->t('Shared mailbox password could not be decrypted. Re-save the mailbox credentials in Workspace settings.')
						: $this->l10n->t('Shared mailbox SMTP is configured but the password is missing. Re-save the mailbox credentials.'),
				);
			}
			SharedMailboxSmtpClient::send([
				'host' => $smtpHost,
				'port' => (int)($box['smtp_port'] ?? 587),
				'enc' => (string)($box['smtp_enc'] ?? 'tls'),
				'username' => (string)($box['username'] ?? ''),
				'password' => $secret,
				'from' => $from,
				'fromName' => (string)$box['label'],
				'to' => $to,
				'subject' => $subject,
				'body' => $fullBody,
				'attachments' => array_map(static fn (array $a): array => [
					'filename' => $a['filename'],
					'mime' => $a['mime'],
					'data' => $a['data'],
				], $attachments),
			]);
			return;
		}

		$message = $this->mailer->createMessage();
		$message->setFrom([$from => (string)$box['label']]);
		$message->setTo([$to => $to]);
		$message->setSubject($subject);
		$message->setPlainBody($fullBody);
		foreach ($attachments as $att) {
			$message->attach($this->mailer->createAttachment(
				$att['data'],
				$att['filename'],
				$att['mime'],
			));
		}
		$this->mailer->send($message);
	}

	/** @return array<string, mixed> */
	private function loadMailbox(int $workspaceId, int $mailboxId): array
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
			throw ValidationException::field(Constants::ERR_NOT_FOUND, 'mailboxId', $this->l10n->t('Mailbox not found.'));
		}
		return $row;
	}

	private function workspaceName(int $workspaceId): string
	{
		if ($workspaceId <= 0 || !$this->db->tableExists('crm_workspace')) {
			return $this->l10n->t('this workspace');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('name')->from('crm_workspace')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		$name = trim((string)($row['name'] ?? ''));

		return $name !== '' ? $name : $this->l10n->t('this workspace');
	}

	/** @return array<string, mixed>|null */
	private function findByIdempotency(int $workspaceId, string $key): ?array
	{
		if (!$this->db->tableExists('crm_mail_dft') || $key === '') {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_mail_dft')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('idempotency_key', $qb->createNamedParameter($key)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return null;
		}
		$to = (string)($row['to_addrs'] ?? '');
		return [
			'sent' => true,
			'replay' => true,
			'to' => $to,
			'subject' => (string)($row['subject'] ?? ''),
			'sentAt' => $row['updated_at'] ?? null,
		];
	}

	private function saveDraft(
		int $workspaceId,
		int $mailboxId,
		string $to,
		string $subject,
		string $body,
		string $actor,
		?string $idem,
		?int $companyId,
	): int {
		if (!$this->db->tableExists('crm_mail_dft')) {
			return 0;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_mail_dft')->values([
			'workspace_id' => $ins->createNamedParameter($workspaceId),
			'mailbox_id' => $ins->createNamedParameter($mailboxId),
			'company_id' => $ins->createNamedParameter($companyId),
			'to_addrs' => $ins->createNamedParameter($to),
			'subject' => $ins->createNamedParameter(mb_substr($subject, 0, 512)),
			'body_text' => $ins->createNamedParameter($body),
			'idempotency_key' => $ins->createNamedParameter($idem),
			'created_by' => $ins->createNamedParameter($actor),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();
		return (int)$this->db->lastInsertId('crm_mail_dft');
	}

	private function deleteDraft(int $draftId): void
	{
		if ($draftId <= 0 || !$this->db->tableExists('crm_mail_dft')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('crm_mail_dft')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($draftId)))
			->executeStatement();
	}

	private function isUniqueConstraintViolation(\Throwable $e): bool
	{
		if ($e instanceof UniqueConstraintViolationException) {
			return true;
		}
		$previous = $e->getPrevious();

		return $previous instanceof \Throwable && $this->isUniqueConstraintViolation($previous);
	}
}
