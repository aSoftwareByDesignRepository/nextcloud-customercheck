<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\SharedMailboxSmtpClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;

/**
 * Shared-mailbox SMTP send — product §14.3.
 *
 * Prefer mailbox SMTP credentials when present; fall back to instance IMailer
 * with the shared From identity. Rate-limited, Idempotency-Key safe, audited.
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
	 *   idempotencyKey?: string
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

		if (!$this->rateLimit->allow('crm_mail_user:' . $actor, Constants::MAIL_SEND_USER_LIMIT, Constants::MAIL_SEND_USER_WINDOW)) {
			throw ValidationException::field(Constants::ERR_RATE_LIMITED, 'rate', $this->l10n->t('Send rate limit reached for your user.'));
		}
		if (!$this->rateLimit->allow('crm_mail_ws:' . $workspaceId, Constants::MAIL_SEND_WS_LIMIT, Constants::MAIL_SEND_WS_WINDOW)) {
			throw ValidationException::field(Constants::ERR_RATE_LIMITED, 'rate', $this->l10n->t('Send rate limit reached for this workspace.'));
		}

		$idem = trim((string)($data['idempotencyKey'] ?? ''));
		if ($idem !== '') {
			$existing = $this->findByIdempotency($workspaceId, $idem);
			if ($existing !== null) {
				return $existing;
			}
		}

		$box = $this->loadMailbox($workspaceId, $mailboxId);
		$from = (string)$box['email_address'];
		$disclosure = $this->mailboxes->disclosure((string)$box['label'], 'workspace');
		$fullBody = $body . "\n\n—\n" . $disclosure;

		try {
			$secret = '';
			if (!empty($box['secret_enc'])) {
				try {
					$secret = $this->crypto->decrypt((string)$box['secret_enc']);
				} catch (\Throwable) {
					$secret = '';
				}
			}
			$smtpHost = trim((string)($box['smtp_host'] ?? ''));
			if ($smtpHost !== '' && $secret !== '') {
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
				]);
			} else {
				$message = $this->mailer->createMessage();
				$message->setFrom([$from => (string)$box['label']]);
				$message->setTo([$to => $to]);
				$message->setSubject($subject);
				$message->setPlainBody($fullBody);
				$this->mailer->send($message);
			}
		} catch (\Throwable $e) {
			$draftId = $this->saveDraft($workspaceId, $mailboxId, $to, $subject, $body, $actor, $idem, isset($data['companyId']) ? (int)$data['companyId'] : null);
			$msg = $e instanceof ValidationException ? $e->getMessage() : $this->l10n->t('SMTP send failed — draft kept (id %s).', [(string)$draftId]);
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'smtp',
				$msg,
			);
		}

		$now = $this->timeFactory->getDateTime();
		$companyId = isset($data['companyId']) ? (int)$data['companyId'] : 0;
		if ($companyId > 0) {
			$this->emailTouch->logCompose($companyId, $to, $subject, $actor, $from);
			$this->timeline->append($companyId, 'mail_sent', $actor, [
				'subject' => $subject,
				'to' => $to,
				'mailboxId' => $mailboxId,
			]);
		}

		if ($idem !== '') {
			$this->saveDraft($workspaceId, $mailboxId, $to, $subject, $body, $actor, $idem, $companyId > 0 ? $companyId : null);
		}

		return [
			'sent' => true,
			'from' => $from,
			'to' => $to,
			'subject' => $subject,
			'disclosure' => $disclosure,
			'sentAt' => $now->format(DATE_ATOM),
			'companyUrl' => $companyId > 0
				? $this->url->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $companyId])
				: null,
		];
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

	/** @return array<string, mixed>|null */
	private function findByIdempotency(int $workspaceId, string $key): ?array
	{
		if (!$this->db->tableExists('crm_mail_dft')) {
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
}
