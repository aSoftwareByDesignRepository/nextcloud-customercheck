<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\ImapConnection;
use OCA\CustomerCheck\Support\SharedMailboxSmtpClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

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
		private readonly HtmlSanitizeService $htmlSanitize,
		private readonly MailboxAuthService $mailboxAuth,
		private readonly LoggerInterface $logger,
		private readonly WorkspaceService $workspaces,
	) {
	}

	/**
	 * @param array{
	 *   mailboxId: int,
	 *   to: string,
	 *   cc?: list<string>|string,
	 *   subject: string,
	 *   body?: string,
	 *   bodyHtml?: string,
	 *   includeSignature?: bool,
	 *   companyId?: int,
	 *   idempotencyKey?: string,
	 *   attachmentFileIds?: list<int>,
	 *   reuseDraftId?: int,
	 *   quotedBody?: string,
	 *   quotedHtml?: string
	 * } $data
	 * @return array<string, mixed>
	 */
	public function send(int $workspaceId, array $data, string $actor, bool $canMailSend): array
	{
		$mailboxId = (int)($data['mailboxId'] ?? 0);
		$to = trim((string)($data['to'] ?? ''));
		$subject = trim((string)($data['subject'] ?? ''));
		$body = (string)($data['body'] ?? '');
		$bodyHtmlRaw = (string)($data['bodyHtml'] ?? '');
		$ccList = $this->normalizeCcList($data['cc'] ?? null);
		if ($mailboxId <= 0 || $to === '' || $subject === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'to', $this->l10n->t('Mailbox, recipient and subject are required.'));
		}
		if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
			throw ValidationException::field(Constants::ERR_EMAIL_INVALID, 'to', $this->l10n->t('Enter a valid recipient email.'));
		}
		if (count($ccList) > 20) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'cc',
				$this->l10n->t('Too many Cc recipients (max 20).'),
			);
		}
		foreach ($ccList as $ccAddr) {
			if (!filter_var($ccAddr, FILTER_VALIDATE_EMAIL)) {
				throw ValidationException::field(
					Constants::ERR_EMAIL_INVALID,
					'cc',
					$this->l10n->t('Enter valid Cc addresses.'),
				);
			}
		}
		if (strlen($body) > Constants::MAIL_BODY_PLAIN_MAX_BYTES) {
			throw ValidationException::field(
				Constants::ERR_MAIL_BODY_TOO_LARGE,
				'body',
				$this->l10n->t('Message body is too large (max %s KiB plain text).', [(string)(int)(Constants::MAIL_BODY_PLAIN_MAX_BYTES / 1024)]),
			);
		}
		if (strlen($bodyHtmlRaw) > Constants::MAIL_BODY_HTML_MAX_BYTES) {
			throw ValidationException::field(
				Constants::ERR_MAIL_BODY_TOO_LARGE,
				'bodyHtml',
				$this->l10n->t('Message HTML is too large (max %s KiB).', [(string)(int)(Constants::MAIL_BODY_HTML_MAX_BYTES / 1024)]),
			);
		}

		// Idempotency-Key is mandatory — validate before mailbox I/O / rate-limit (§14.3 / §18.4).
		$idem = trim((string)($data['idempotencyKey'] ?? ''));
		if ($idem === '' || strlen($idem) > 64 || preg_match('/^[A-Za-z0-9:_.-]+$/', $idem) !== 1) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'idempotencyKey',
				$this->l10n->t('A valid Idempotency-Key is required to send from the shared mailbox.'),
			);
		}

		$box = $this->loadMailbox($workspaceId, $mailboxId);
		$this->assertMaySendAs($box, $actor, $canMailSend);

		// Cross-book guard: never write email_touch / timeline onto a foreign company.
		$companyId = isset($data['companyId']) ? (int)$data['companyId'] : 0;
		if ($companyId > 0) {
			$this->assertCompanyInWorkspace($workspaceId, $companyId);
		}

		$reuseDraftId = isset($data['reuseDraftId']) ? (int)$data['reuseDraftId'] : 0;

		$lockKey = 'crm_mail_send/' . $workspaceId . '/' . $idem;
		$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			// Replay lookup must run before rate-limit tokens burn (§14.3 / §18.4).
			$existing = $this->findByIdempotency($workspaceId, $idem);
			if ($existing !== null) {
				return $existing;
			}

			// Resolve Files attachments via actor ACL under the send lock (FAC-13 / FAC-14).
			$rawIds = $data['attachmentFileIds'] ?? [];
			$attachmentIds = is_array($rawIds) ? $rawIds : [];
			try {
				$attachments = $attachmentIds !== []
					? $this->filesAccess->loadMailAttachments($actor, $attachmentIds)
					: [];
			} catch (ValidationException $e) {
				// FAC-13: keep a failed draft so compose is not lost when attach is rejected.
				$draftMeta = [
					'bodyHtml' => (string)($data['bodyHtml'] ?? ''),
					'includeSignature' => !array_key_exists('includeSignature', $data) || !empty($data['includeSignature']) ? 1 : 0,
					'attachmentFileIds' => array_values(array_map('intval', $attachmentIds)),
					'quotedBody' => (string)($data['quotedBody'] ?? $data['quotedText'] ?? ''),
				];
				$draftId = $this->saveDraft(
					$workspaceId,
					$mailboxId,
					$to,
					$subject,
					$body,
					$actor,
					null,
					$companyId > 0 ? $companyId : null,
					$draftMeta,
				);
				$fields = $e->getFields();
				$fields['draftId'] = (string)$draftId;
				throw new ValidationException(
					$e->getErrorCode(),
					$this->l10n->t('Attachment rejected — draft kept (id %s).', [(string)$draftId]),
					$fields,
				);
			}

			$this->assertSendRateLimits($actor, $workspaceId);
			$data['cc'] = $ccList;
			return $this->deliver($workspaceId, $mailboxId, $to, $subject, $body, $actor, $idem, $data, $attachments, $reuseDraftId, $box);
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * From picker accounts: personal owned + Team when mail.send is allowed.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listSendAccounts(int $workspaceId, string $actor, bool $canMailSend): array
	{
		$out = [];
		if ($canMailSend) {
			foreach ($this->mailboxes->listMailboxes($workspaceId, MailAclLogic::KIND_TEAM) as $box) {
				$out[] = $this->serializeSendAccount($box, MailAclLogic::KIND_TEAM);
			}
		}
		$settings = $this->workspaces->getSettings($workspaceId);
		if (!empty($settings['personalMailEnabled']) && $this->db->tableExists('crm_mailbox')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('crm_mailbox')
				->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
				->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(MailAclLogic::KIND_PERSONAL)))
				->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($actor)))
				->orderBy('id', 'ASC');
			$r = $qb->executeQuery();
			while ($row = $r->fetch()) {
				$out[] = $this->serializeSendAccount([
					'id' => (int)$row['id'],
					'label' => (string)($row['label'] ?? ''),
					'emailAddress' => (string)($row['email_address'] ?? ''),
					'signatureHtml' => (string)($row['signature_html'] ?? ''),
					'signaturePlain' => (string)($row['signature_plain'] ?? ''),
				], MailAclLogic::KIND_PERSONAL);
			}
			$r->closeCursor();
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $box
	 * @return array<string, mixed>
	 */
	private function serializeSendAccount(array $box, string $kind): array
	{
		$id = (int)($box['id'] ?? 0);
		$label = (string)($box['label'] ?? '');
		$email = (string)($box['emailAddress'] ?? $box['email_address'] ?? '');
		$sigHtml = (string)($box['signatureHtml'] ?? $box['signature_html'] ?? '');
		$sigPlain = (string)($box['signaturePlain'] ?? $box['signature_plain'] ?? '');

		return [
			'id' => $id,
			'kind' => $kind,
			'label' => $label !== '' ? $label : $email,
			'emailAddress' => $email,
			'hasSignature' => trim($sigHtml) !== '' || trim($sigPlain) !== '',
			// FAC-11 / NN-14: clients swap signature preview on From change (never concatenate).
			'signaturePlain' => $sigPlain,
			'signatureHtml' => $sigHtml,
		];
	}

	/**
	 * @param array<string, mixed> $box
	 */
	private function assertMaySendAs(array $box, string $actor, bool $canMailSend): void
	{
		$kind = (string)($box['kind'] ?? MailAclLogic::KIND_TEAM);
		if ($kind === MailAclLogic::KIND_PERSONAL) {
			$owner = trim((string)($box['owner_user_id'] ?? ''));
			if ($owner === '' || !hash_equals($owner, $actor)) {
				throw ValidationException::field(
					Constants::ERR_MAIL_SEND_DENIED,
					'mailboxId',
					$this->l10n->t('You can only send from your own personal mailbox.'),
				);
			}
			$workspaceId = (int)($box['workspace_id'] ?? 0);
			if ($workspaceId <= 0) {
				throw ValidationException::field(
					Constants::ERR_MAIL_SEND_DENIED,
					'mailboxId',
					$this->l10n->t('You can only send from your own personal mailbox.'),
				);
			}
			$settings = $this->workspaces->getSettings($workspaceId);
			if (empty($settings['personalMailEnabled'])) {
				throw ValidationException::field(
					Constants::ERR_MAIL_SEND_DENIED,
					'personalMail',
					$this->l10n->t('Personal email sync is not enabled for this workspace. Ask a manager to turn it on under Team & mailbox settings.'),
				);
			}

			return;
		}
		if (!$canMailSend) {
			throw ValidationException::field(
				Constants::ERR_MAIL_SEND_DENIED,
				'mail.send',
				$this->l10n->t('You do not have permission to send from the shared mailbox.'),
			);
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
		?array $box = null,
	): array {
		$box = $box ?? $this->loadMailbox($workspaceId, $mailboxId);
		$from = (string)$box['email_address'];
		$workspaceName = $this->workspaceName($workspaceId);
		$boxKind = strtolower(trim((string)($box['kind'] ?? MailAclLogic::KIND_TEAM)));
		$disclosure = $this->mailboxes->disclosure((string)$box['label'], $workspaceName, $boxKind);

		$includeSignature = !array_key_exists('includeSignature', $data) || !empty($data['includeSignature']);
		$bodyHtml = $this->htmlSanitize->sanitize((string)($data['bodyHtml'] ?? ''));
		if ($body === '' && $bodyHtml !== '') {
			$body = $this->htmlSanitize->htmlToPlain($bodyHtml);
		}
		$quotedText = trim((string)($data['quotedBody'] ?? $data['quotedText'] ?? ''));
		$quotedHtml = $this->htmlSanitize->sanitize((string)($data['quotedHtml'] ?? ''));
		$sigPlain = trim((string)($box['signature_plain'] ?? ''));
		$sigHtml = $this->htmlSanitize->sanitizeSignature((string)($box['signature_html'] ?? ''));
		// HTML-only signatures (common after WYSIWYG save) must still apply to plain twin.
		if ($sigPlain === '' && $sigHtml !== '') {
			$sigPlain = trim($this->htmlSanitize->htmlToPlain($sigHtml));
		}
		$signatureApplied = false;
		if ($includeSignature) {
			if ($sigPlain !== '') {
				$body = rtrim($body) . "\n\n-- \n" . $sigPlain;
				$signatureApplied = true;
			}
			if ($bodyHtml !== '' && $sigHtml !== '') {
				$bodyHtml = rtrim($bodyHtml) . '<br><hr><div class="crm-mail-sig">' . $sigHtml . '</div>';
				$signatureApplied = true;
			} elseif ($bodyHtml !== '' && $sigPlain !== '') {
				$bodyHtml = rtrim($bodyHtml) . '<br><hr><pre>' . htmlspecialchars($sigPlain, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</pre>';
				$signatureApplied = true;
			} elseif ($bodyHtml === '' && $sigHtml !== '' && $sigPlain !== '') {
				// Plain-only compose still gets an HTML alternative when only HTML signature exists.
				$bodyHtml = '<pre>' . htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</pre>'
					. '<br><hr><div class="crm-mail-sig">' . $sigHtml . '</div>';
				$signatureApplied = true;
			}
		}
		// AS-19: new content → signature → quoted original (never signature after quote).
		if ($quotedText !== '') {
			$body = rtrim($body) . "\n\n" . $quotedText;
		}
		if ($quotedHtml !== '') {
			$bodyHtml = ($bodyHtml !== '' ? rtrim($bodyHtml) : '') . '<blockquote class="crm-mail-quote">' . $quotedHtml . '</blockquote>';
		} elseif ($quotedText !== '' && $bodyHtml !== '') {
			$bodyHtml = rtrim($bodyHtml) . '<blockquote class="crm-mail-quote"><pre>'
				. htmlspecialchars($quotedText, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</pre></blockquote>';
		}
		// Disclosure is for in-app UI / API only — never append to customer-facing mail.
		$fullBody = $body;
		$fullHtml = $bodyHtml !== '' ? $bodyHtml : '';
		$companyId = isset($data['companyId']) ? (int)$data['companyId'] : 0;

		$draftMeta = [
			'bodyHtml' => (string)($data['bodyHtml'] ?? ''),
			'includeSignature' => $includeSignature ? 1 : 0,
			'attachmentFileIds' => array_values(array_map(
				static fn (array $a): int => (int)$a['fileId'],
				$attachments,
			)),
			'quotedBody' => (string)($data['quotedBody'] ?? $data['quotedText'] ?? ''),
		];

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
						$draftMeta,
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
						$draftMeta,
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

		$rfcMessageId = SharedMailboxSmtpClient::generateMessageId($from);
		try {
			$this->dispatchMessage(
				$box,
				$from,
				$to,
				$subject,
				$fullBody,
				$attachments,
				$fullHtml,
				$this->normalizeCcList($data['cc'] ?? null),
				$rfcMessageId,
			);
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
					$draftMeta,
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
					$draftMeta,
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

		// Zeus: durable SMTP ACK before CRM index / timeline so crash replay never fakes success.
		if ($claimId > 0) {
			$this->markSmtpAcked($claimId);
		}

		$now = $this->timeFactory->getDateTime();
		$ccList = $this->normalizeCcList($data['cc'] ?? null);
		$this->indexOutboundMessage(
			$workspaceId,
			$mailboxId,
			$box,
			$from,
			$to,
			$ccList,
			$subject,
			$body,
			$fullHtml !== '' ? $bodyHtml : '',
			$attachments,
			$signatureApplied,
			$companyId > 0 ? $companyId : null,
			$idem,
			$actor,
			$now->format('Y-m-d H:i:s'),
			$rfcMessageId,
		);
		if ($companyId > 0) {
			try {
				$this->emailTouch->logCompose($companyId, $to, $subject, $actor, $from);
				$this->timeline->append($companyId, 'mail_sent', $actor, [
					'subject' => $subject,
					'to' => $to,
					'cc' => $ccList,
					'mailboxId' => $mailboxId,
					'attachmentCount' => count($attachments),
					'contentType' => count($attachments) > 0
						? 'multipart/mixed'
						: ($fullHtml !== '' ? 'multipart/alternative' : 'text/plain'),
					'signatureIncluded' => $signatureApplied,
				]);
			} catch (\Throwable $e) {
				$this->logger->warning('personal_mail.side_effect_skip', [
					'event' => 'personal_mail.side_effect_skip',
					'workspaceId' => $workspaceId,
					'mailboxId' => $mailboxId,
					'companyId' => $companyId,
					'error' => $e->getMessage(),
				]);
			}
		}

		$this->loggerInfo('personal_mail.sent', [
			'workspaceId' => $workspaceId,
			'mailboxId' => $mailboxId,
			'mailboxKind' => (string)($box['kind'] ?? 'team'),
			'attachmentCount' => count($attachments),
			'content_type' => count($attachments) > 0
				? 'multipart/mixed'
				: ($fullHtml !== '' ? 'multipart/alternative' : 'text/plain'),
			'signature_included' => $signatureApplied,
			'actor' => $actor,
		]);

		return [
			'sent' => true,
			'from' => $from,
			'to' => $to,
			'subject' => $subject,
			'disclosure' => $disclosure,
			'attachmentCount' => count($attachments),
			'contentType' => count($attachments) > 0
				? 'multipart/mixed'
				: ($fullHtml !== '' ? 'multipart/alternative' : 'text/plain'),
			'signatureIncluded' => $signatureApplied,
			'sentAt' => $now->format(DATE_ATOM),
			'companyUrl' => $companyId > 0
				? $this->url->linkToRouteAbsolute('customercheck.page.companyDetail', ['id' => $companyId])
				: null,
		];
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private function loggerInfo(string $event, array $ctx): void
	{
		try {
			$this->logger->info($event, array_merge(['event' => $event], $ctx));
		} catch (\Throwable) {
			// Never fail send on observability.
		}
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
		array $meta = [],
	): int {
		if (!$this->db->tableExists('crm_mail_dft') || $draftId <= 0) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists.'));
		}
		// Argus residual: never let reuseDraftId hijack a peer's personal failed draft.
		$load = $this->db->getQueryBuilder();
		$load->select('d.id', 'd.created_by', 'b.kind AS mailbox_kind', 'b.owner_user_id AS mailbox_owner')
			->from('crm_mail_dft', 'd')
			->leftJoin('d', 'crm_mailbox', 'b', $load->expr()->eq('d.mailbox_id', 'b.id'))
			->where($load->expr()->eq('d.id', $load->createNamedParameter($draftId)))
			->andWhere($load->expr()->eq('d.workspace_id', $load->createNamedParameter($workspaceId)))
			->andWhere($load->expr()->isNull('d.idempotency_key'))
			->setMaxResults(1);
		$lr = $load->executeQuery();
		$existing = $lr->fetch();
		$lr->closeCursor();
		if ($existing === false
			|| false
		) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists (or was already sent).'));
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mail_dft')
			->set('mailbox_id', $qb->createNamedParameter($mailboxId))
			->set('to_addrs', $qb->createNamedParameter($to))
			->set('subject', $qb->createNamedParameter(mb_substr($subject, 0, 512)))
			->set('body_text', $qb->createNamedParameter($body))
			->set('idempotency_key', $qb->createNamedParameter($idem))
			->set('created_by', $qb->createNamedParameter($actor))
			->set('company_id', $qb->createNamedParameter($companyId))
			->set('updated_at', $qb->createNamedParameter($now));
		try {
			$qb->set('smtp_acked', $qb->createNamedParameter(0));
		} catch (\Throwable) {
			// Column may be absent mid-upgrade.
		}
		$this->applyDraftMetaSets($qb, $meta);
		$affected = $qb
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
			->set('updated_at', $qb->createNamedParameter($now));
		try {
			$qb->set('smtp_acked', $qb->createNamedParameter(0));
		} catch (\Throwable) {
			// Column may be absent mid-upgrade.
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($draftId)))
			->andWhere($qb->expr()->isNotNull('idempotency_key'))
			->executeStatement();
	}

	private function markSmtpAcked(int $draftId): void
	{
		if ($draftId <= 0 || !$this->db->tableExists('crm_mail_dft')) {
			return;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->update('crm_mail_dft')
				->set('smtp_acked', $qb->createNamedParameter(1))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($draftId)))
				->executeStatement();
		} catch (\Throwable) {
			// Column may be absent mid-upgrade — outbound CRM row remains the legacy proof.
		}
	}

	/**
	 * @param array<string, mixed> $box
	 * @param list<array{fileId: int, filename: string, mime: string, data: string, size: int}> $attachments
	 * @param list<string> $cc
	 */
	private function dispatchMessage(
		array $box,
		string $from,
		string $to,
		string $subject,
		string $fullBody,
		array $attachments = [],
		string $fullHtml = '',
		array $cc = [],
		string $rfcMessageId = '',
	): void {
		$smtpHost = trim((string)($box['smtp_host'] ?? ''));
		$secret = '';
		$authMode = MailboxAuthService::MODE_PASSWORD;
		$user = ImapConnection::normalizeUsername((string)($box['username'] ?? ''));
		$decryptFailed = false;
		$oauthResolveFailed = false;
		$authKind = strtolower(trim((string)($box['auth_kind'] ?? 'imap')));
		$boxKind = strtolower(trim((string)($box['kind'] ?? MailAclLogic::KIND_TEAM)));
		$isPersonal = $boxKind === MailAclLogic::KIND_PERSONAL;
		if ($authKind === 'google' || $authKind === 'microsoft') {
			try {
				$auth = $this->mailboxAuth->resolve($box);
				$secret = $auth['secret'];
				$authMode = $auth['mode'];
				$user = $auth['user'] !== '' ? $auth['user'] : $user;
			} catch (\Throwable) {
				$secret = '';
				$decryptFailed = true;
				$oauthResolveFailed = true;
			}
		} elseif (!empty($box['secret_enc'])) {
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
			$smtpHost = ImapConnection::normalizeHost($smtpHost);
			if (ImapConnection::isBlockedMailHost($smtpHost)) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'smtp',
					$this->l10n->t('Use a public mail host — localhost and private network addresses are not allowed.'),
				);
			}
			if ($secret === '') {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'smtp',
					$this->smtpCredentialMissingMessage($decryptFailed, $oauthResolveFailed, $isPersonal),
				);
			}
			$payload = [
				'host' => $smtpHost,
				'port' => (int)($box['smtp_port'] ?? 465),
				'enc' => ImapConnection::resolveSmtpEnc(
					isset($box['smtp_enc']) ? (string)$box['smtp_enc'] : null,
					(int)($box['smtp_port'] ?? 465),
				),
				'username' => $user,
				'password' => $secret,
				'authMode' => $authMode,
				'from' => $from,
				'fromName' => (string)$box['label'],
				'to' => $to,
				'cc' => $cc,
				'subject' => $subject,
				'body' => $fullBody,
				'messageId' => $rfcMessageId !== '' ? $rfcMessageId : SharedMailboxSmtpClient::generateMessageId($from),
				'attachments' => array_map(static fn (array $a): array => [
					'filename' => $a['filename'],
					'mime' => $a['mime'],
					'data' => $a['data'],
				], $attachments),
			];
			if ($fullHtml !== '') {
				$payload['bodyHtml'] = $fullHtml;
			}
			SharedMailboxSmtpClient::send($payload);
			return;
		}

		$message = $this->mailer->createMessage();
		$message->setFrom([$from => (string)$box['label']]);
		$message->setTo([$to => $to]);
		if ($cc !== []) {
			$ccMap = [];
			foreach ($cc as $addr) {
				$ccMap[$addr] = $addr;
			}
			$message->setCc($ccMap);
		}
		$message->setSubject($subject);
		$message->setPlainBody($fullBody);
		if ($fullHtml !== '' && method_exists($message, 'setHtmlBody')) {
			$message->setHtmlBody($fullHtml);
		}
		if ($rfcMessageId !== '' && method_exists($message, 'setId')) {
			// Strip angle brackets — Symfony Mailer Message-ID setter expects bare id.
			$bare = trim($rfcMessageId, "<> \t");
			if ($bare !== '') {
				$message->setId($bare);
			}
		}
		foreach ($attachments as $att) {
			$message->attach($this->mailer->createAttachment(
				$att['data'],
				$att['filename'],
				$att['mime'],
			));
		}
		$this->mailer->send($message);
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	private function normalizeCcList(mixed $raw): array
	{
		if ($raw === null || $raw === '') {
			return [];
		}
		$parts = [];
		if (is_string($raw)) {
			foreach (preg_split('/[,;]+/', $raw) ?: [] as $p) {
				$p = strtolower(trim($p));
				if ($p !== '') {
					$parts[] = $p;
				}
			}
		} elseif (is_array($raw)) {
			foreach ($raw as $p) {
				$p = strtolower(trim((string)$p));
				if ($p !== '') {
					$parts[] = $p;
				}
			}
		}
		$out = [];
		$seen = [];
		foreach ($parts as $p) {
			if (isset($seen[$p])) {
				continue;
			}
			$seen[$p] = true;
			$out[] = $p;
		}

		return $out;
	}

	/**
	 * Persist outbound CRM index row (spec §9) — metadata only; no attachment blobs (NN-12).
	 *
	 * @param array<string, mixed> $box
	 * @param list<string> $cc
	 * @param list<array{fileId: int, filename: string, mime: string, data: string, size: int}> $attachments
	 */
	private function indexOutboundMessage(
		int $workspaceId,
		int $mailboxId,
		array $box,
		string $from,
		string $to,
		array $cc,
		string $subject,
		string $bodyPlain,
		string $bodyHtml,
		array $attachments,
		bool $signatureIncluded,
		?int $companyId,
		string $idem,
		string $actor,
		string $now,
		string $rfcMessageId = '',
	): void {
		$mboxLock = $mailboxId > 0 ? 'crm_mbox_' . $mailboxId : '';
		if ($mboxLock !== '') {
			try {
				$this->locking->acquireLock($mboxLock, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
				$mboxLock = '';
			}
		}
		try {
			if (!$this->db->tableExists('crm_mail_msg')) {
				return;
			}
			if ($idem !== '' && $this->outboundExistsForIdempotency($workspaceId, $idem)) {
				return;
			}
			$folderMeta = $this->resolveOutboundFolderMeta($mailboxId);
			$folderId = $folderMeta['id'];
			if ($folderId <= 0) {
				return;
			}
			$kind = (string)($box['kind'] ?? MailAclLogic::KIND_TEAM);
			// MH-07 / Q10: personal CRM outbound follows the Sent (or fallback) folder share flag.
			// Shared + company-linked ⇒ teammates with company.read see Talk; private stays owner-only.
			if ($kind === MailAclLogic::KIND_PERSONAL) {
				$visibility = $folderMeta['shareWithWorkspace']
					? MailAclLogic::VISIBILITY_SHARED
					: MailAclLogic::VISIBILITY_PRIVATE;
			} else {
				$visibility = MailAclLogic::VISIBILITY_SHARED;
			}
			$owner = $kind === MailAclLogic::KIND_PERSONAL
				? trim((string)($box['owner_user_id'] ?? $actor))
				: null;
			$uid = 'outbound:' . ($idem !== '' ? $idem : bin2hex(random_bytes(8)));
			$messageId = SharedMailboxSmtpClient::normalizeMessageId($rfcMessageId);
			if ($messageId === '') {
				$messageId = SharedMailboxSmtpClient::generateMessageId($from);
			}
			$attachMeta = [];
			foreach ($attachments as $a) {
				$attachMeta[] = [
					'fileId' => (int)$a['fileId'],
					'filename' => (string)$a['filename'],
					'mime' => (string)$a['mime'],
					'size' => (int)$a['size'],
				];
			}
			$plain = mb_substr($bodyPlain, 0, Constants::MAIL_BODY_PLAIN_MAX_BYTES);
			$html = $bodyHtml !== ''
				? mb_substr($this->htmlSanitize->sanitize($bodyHtml), 0, Constants::MAIL_BODY_HTML_MAX_BYTES)
				: null;
			$ins = $this->db->getQueryBuilder();
			$values = [
				'mailbox_id' => $ins->createNamedParameter($mailboxId),
				'folder_id' => $ins->createNamedParameter($folderId),
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'company_id' => $ins->createNamedParameter($companyId),
				'message_uid' => $ins->createNamedParameter($uid),
				'message_id' => $ins->createNamedParameter($messageId),
				'direction' => $ins->createNamedParameter('outbound'),
				'from_addr' => $ins->createNamedParameter($from),
				'to_addrs' => $ins->createNamedParameter(json_encode([$to], JSON_THROW_ON_ERROR)),
				'cc_addrs' => $ins->createNamedParameter($cc !== [] ? json_encode(array_values($cc), JSON_THROW_ON_ERROR) : null),
				'subject' => $ins->createNamedParameter(mb_substr($subject, 0, 512)),
				'snippet' => $ins->createNamedParameter(mb_substr($plain, 0, 512)),
				'preview' => $ins->createNamedParameter(mb_substr($plain, 0, Constants::MAIL_PREVIEW_MAX_BYTES)),
				'body_text' => $ins->createNamedParameter($plain !== '' ? $plain : null),
				'body_html' => $ins->createNamedParameter($html),
				'sent_at' => $ins->createNamedParameter($now),
				'matched_how' => $ins->createNamedParameter($companyId ? 'manual' : 'unmatched'),
				// Never open Assign/Unassigned for CRM-sent copies (synthetic outbound: uid; not IMAP-deletable).
				'triage_status' => $ins->createNamedParameter('matched'),
				'visibility' => $ins->createNamedParameter($visibility),
				'owner_user_id' => $ins->createNamedParameter($owner),
				'created_at' => $ins->createNamedParameter($now),
				'attachment_meta_json' => $ins->createNamedParameter(
					$attachMeta !== [] ? json_encode($attachMeta, JSON_THROW_ON_ERROR) : null,
				),
				'signature_included' => $ins->createNamedParameter($signatureIncluded ? 1 : 0),
			];
			$ins->insert('crm_mail_msg')->values($values)->executeStatement();
		} catch (\Throwable $e) {
			// Never fail the send after SMTP succeeded (unique race / unit mocks / schema lag).
			$this->logger->warning('personal_mail.outbound_index_skip', [
				'event' => 'personal_mail.outbound_index_skip',
				'workspaceId' => $workspaceId,
				'mailboxId' => $mailboxId,
				'error' => $e->getMessage(),
			]);
		} finally {
			if ($mboxLock !== '') {
				try {
					$this->locking->releaseLock($mboxLock, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable) {
					// Best-effort unlock.
				}
			}
		}
	}

	/**
	 * Prefer Sent folder for outbound CRM index; fall back to first folder.
	 *
	 * @return array{id: int, shareWithWorkspace: bool}
	 */
	private function resolveOutboundFolderMeta(int $mailboxId): array
	{
		if ($mailboxId <= 0 || !$this->db->tableExists('crm_mail_fld')) {
			return ['id' => 0, 'shareWithWorkspace' => false];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'folder_path', 'share_with_workspace')
			->from('crm_mail_fld')
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
			->orderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$fallback = ['id' => 0, 'shareWithWorkspace' => false];
		while (($row = $r->fetch()) !== false) {
			if (!is_array($row)) {
				break;
			}
			$id = (int)($row['id'] ?? 0);
			$share = (int)($row['share_with_workspace'] ?? 0) === 1;
			if ($fallback['id'] <= 0 && $id > 0) {
				$fallback = ['id' => $id, 'shareWithWorkspace' => $share];
			}
			$path = strtoupper(trim((string)($row['folder_path'] ?? '')));
			if ($id > 0 && ($path === 'SENT' || str_starts_with($path, 'SENT/') || str_contains($path, 'SENT'))) {
				$r->closeCursor();
				return ['id' => $id, 'shareWithWorkspace' => $share];
			}
		}
		$r->closeCursor();

		return $fallback;
	}

	/** @deprecated Prefer resolveOutboundFolderMeta — kept for tests/callers expecting an id. */
	private function resolveOutboundFolderId(int $mailboxId): int
	{
		return $this->resolveOutboundFolderMeta($mailboxId)['id'];
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
			// Legacy / index-only proof: outbound CRM row with synthetic UID.
			if ($this->outboundExistsForIdempotency($workspaceId, $key)) {
				return [
					'sent' => true,
					'replay' => true,
					'to' => '',
					'subject' => '',
					'sentAt' => null,
				];
			}

			return null;
		}

		$acked = array_key_exists('smtp_acked', $row) && (int)$row['smtp_acked'] === 1;
		$outbound = $this->outboundExistsForIdempotency($workspaceId, $key);
		if (!$acked && !$outbound) {
			// In-flight claim under our exclusive send lock ⇒ prior worker died before SMTP ACK.
			// Release so this attempt can re-send (does not fake success).
			$this->releaseFailedDraftClaim((int)$row['id']);

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

	private function outboundExistsForIdempotency(int $workspaceId, string $key): bool
	{
		if ($workspaceId <= 0 || $key === '' || !$this->db->tableExists('crm_mail_msg')) {
			return false;
		}
		$uid = 'outbound:' . $key;
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('crm_mail_msg')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('message_uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();

		return $row !== false;
	}

	/**
	 * @param array{
	 *   bodyHtml?: string,
	 *   includeSignature?: int|bool,
	 *   attachmentFileIds?: list<int>,
	 *   quotedBody?: string
	 * } $meta
	 */
	private function saveDraft(
		int $workspaceId,
		int $mailboxId,
		string $to,
		string $subject,
		string $body,
		string $actor,
		?string $idem,
		?int $companyId,
		array $meta = [],
	): int {
		if (!$this->db->tableExists('crm_mail_dft')) {
			return 0;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$bodyHtml = trim((string)($meta['bodyHtml'] ?? ''));
		$quoted = trim((string)($meta['quotedBody'] ?? ''));
		$include = !empty($meta['includeSignature']) ? 1 : 0;
		$attachIds = $meta['attachmentFileIds'] ?? [];
		$attachJson = is_array($attachIds)
			? json_encode(array_values(array_map('intval', $attachIds)), JSON_THROW_ON_ERROR)
			: '[]';
		$values = [
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
			'body_html' => $ins->createNamedParameter($bodyHtml !== '' ? $bodyHtml : null),
			'include_signature' => $ins->createNamedParameter($include),
			'attachment_file_ids' => $ins->createNamedParameter($attachJson !== '[]' ? $attachJson : null),
			'quoted_text' => $ins->createNamedParameter($quoted !== '' ? $quoted : null),
			'smtp_acked' => $ins->createNamedParameter(0),
		];
		try {
			$ins->insert('crm_mail_dft')->values($values)->executeStatement();
		} catch (\Throwable) {
			// Pre-migration fallback: plain columns only.
			$ins2 = $this->db->getQueryBuilder();
			$ins2->insert('crm_mail_dft')->values([
				'workspace_id' => $ins2->createNamedParameter($workspaceId),
				'mailbox_id' => $ins2->createNamedParameter($mailboxId),
				'company_id' => $ins2->createNamedParameter($companyId),
				'to_addrs' => $ins2->createNamedParameter($to),
				'subject' => $ins2->createNamedParameter(mb_substr($subject, 0, 512)),
				'body_text' => $ins2->createNamedParameter($body),
				'idempotency_key' => $ins2->createNamedParameter($idem),
				'created_by' => $ins2->createNamedParameter($actor),
				'created_at' => $ins2->createNamedParameter($now),
				'updated_at' => $ins2->createNamedParameter($now),
			])->executeStatement();
		}
		return (int)$this->db->lastInsertId('crm_mail_dft');
	}

	/**
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb
	 * @param array<string, mixed> $meta
	 */
	private function applyDraftMetaSets($qb, array $meta): void
	{
		try {
			$html = trim((string)($meta['bodyHtml'] ?? ''));
			$quoted = trim((string)($meta['quotedBody'] ?? ''));
			$attachIds = $meta['attachmentFileIds'] ?? [];
			$attachJson = is_array($attachIds)
				? json_encode(array_values(array_map('intval', $attachIds)), JSON_THROW_ON_ERROR)
				: '[]';
			$qb->set('body_html', $qb->createNamedParameter($html !== '' ? $html : null));
			$qb->set('include_signature', $qb->createNamedParameter(!empty($meta['includeSignature']) ? 1 : 0));
			$qb->set('attachment_file_ids', $qb->createNamedParameter($attachJson !== '[]' ? $attachJson : null));
			$qb->set('quoted_text', $qb->createNamedParameter($quoted !== '' ? $quoted : null));
		} catch (\Throwable) {
			// Columns may be absent mid-upgrade — keep body_text claim only.
		}
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

	/**
	 * Prefer My email reconnect copy for personal / OAuth; Team & mailbox for shared IMAP.
	 */
	private function smtpCredentialMissingMessage(bool $decryptFailed, bool $oauthResolveFailed, bool $isPersonal): string
	{
		if ($oauthResolveFailed) {
			return $this->l10n->t('Mail provider sign-in expired. Reconnect your mailbox under My email.');
		}
		if ($decryptFailed) {
			return $isPersonal
				? $this->l10n->t('Mailbox password could not be unlocked. Re-save credentials under Team & mailbox or My email.')
				: $this->l10n->t('Shared mailbox password could not be decrypted. Re-save the mailbox credentials in Team & mailbox.');
		}
		if ($isPersonal) {
			return $this->l10n->t('Mailbox password could not be unlocked. Re-save credentials under Team & mailbox or My email.');
		}

		return $this->l10n->t('Shared mailbox SMTP is configured but the password is missing. Re-save the mailbox credentials.');
	}
}
