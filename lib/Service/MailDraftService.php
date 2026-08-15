<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Shared-mailbox failed drafts inbox — product §14.4.
 *
 * User-visible drafts are rows with idempotency_key IS NULL (SMTP failure
 * recovery). Claim rows (non-null key) are the idempotency ledger and must
 * never appear in the inbox (never “private draft” fiction — workspace
 * mail.read sees failed outbox only).
 */
class MailDraftService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly MailSendService $mailSend,
		private readonly MailboxService $mailboxes,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return array{items: list<array<string, mixed>>, disclosure: string}
	 */
	public function listFailed(int $workspaceId, string $workspaceName, string $actor, int $limit = 50): array
	{
		$limit = max(1, min(100, $limit));
		$actor = trim($actor);
		$items = [];
		if ($actor !== '' && $this->db->tableExists('crm_mail_dft')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select(
				'd.id',
				'd.mailbox_id',
				'd.company_id',
				'd.to_addrs',
				'd.subject',
				'd.created_by',
				'd.created_at',
				'd.updated_at',
				'b.kind AS mailbox_kind',
				'b.owner_user_id AS mailbox_owner',
			)
				->from('crm_mail_dft', 'd')
				->leftJoin('d', 'crm_mailbox', 'b', $qb->expr()->eq('d.mailbox_id', 'b.id'))
				->where($qb->expr()->eq('d.workspace_id', $qb->createNamedParameter($workspaceId)))
				->andWhere($qb->expr()->isNull('d.idempotency_key'))
				->orderBy('d.updated_at', 'DESC')
				->setMaxResults($limit * 3);
			$r = $qb->executeQuery();
			while ($row = $r->fetch()) {
				if (!$this->actorMaySeeFailedDraft($row, $actor)) {
					continue;
				}
				$items[] = $this->summarise($row, false);
				if (count($items) >= $limit) {
					break;
				}
			}
			$r->closeCursor();
		}

		return [
			'items' => $items,
			'disclosure' => $this->mailboxes->disclosure('shared', $workspaceName),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getFailed(int $workspaceId, int $draftId, string $actor): array
	{
		$row = $this->loadFailedRow($workspaceId, $draftId, $actor);

		return $this->summarise($row, true);
	}

	public function deleteFailed(int $workspaceId, int $draftId, string $actor): void
	{
		$row = $this->loadFailedRow($workspaceId, $draftId, $actor);
		$id = (int)($row['id'] ?? 0);
		if (!$this->db->tableExists('crm_mail_dft') || $id <= 0) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists.'));
		}
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->delete('crm_mail_dft')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->isNull('idempotency_key'))
			->executeStatement();
		if ($affected !== 1) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists (or was already sent).'));
		}
	}

	/**
	 * Resend a failed draft with a fresh idempotency key (CAS claim on the same row).
	 *
	 * @return array<string, mixed>
	 */
	public function resendFailed(
		int $workspaceId,
		int $draftId,
		string $actor,
		bool $canMailSend,
		string $idempotencyKey,
	): array {
		$row = $this->loadFailedRow($workspaceId, $draftId, $actor);
		$key = trim($idempotencyKey);
		if ($key === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'idempotencyKey',
				$this->l10n->t('A send key is required so this message is not sent twice.'),
			);
		}

		$attachIds = [];
		$rawAttach = trim((string)($row['attachment_file_ids'] ?? ''));
		if ($rawAttach !== '') {
			try {
				$decoded = json_decode($rawAttach, true, 32, JSON_THROW_ON_ERROR);
				if (is_array($decoded)) {
					$attachIds = array_values(array_map('intval', $decoded));
				}
			} catch (\Throwable) {
				$attachIds = [];
			}
		}

		return $this->mailSend->send($workspaceId, [
			'mailboxId' => (int)$row['mailbox_id'],
			'to' => (string)($row['to_addrs'] ?? ''),
			'subject' => (string)($row['subject'] ?? ''),
			'body' => (string)($row['body_text'] ?? ''),
			'bodyHtml' => (string)($row['body_html'] ?? ''),
			'includeSignature' => (int)($row['include_signature'] ?? 1) === 1,
			'attachmentFileIds' => $attachIds,
			'quotedBody' => (string)($row['quoted_text'] ?? ''),
			'companyId' => isset($row['company_id']) && $row['company_id'] !== null
				? (int)$row['company_id']
				: 0,
			'idempotencyKey' => $key,
			'reuseDraftId' => $draftId,
		], $actor, $canMailSend);
	}

	/**
	 * Scrub PII on drafts linked to an erased company (§18.9).
	 */
	public function scrubCompany(int $companyId): int
	{
		if ($companyId <= 0 || !$this->db->tableExists('crm_mail_dft')) {
			return 0;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();

		return $qb->update('crm_mail_dft')
			->set('to_addrs', $qb->createNamedParameter(''))
			->set('subject', $qb->createNamedParameter('[erased]'))
			->set('body_text', $qb->createNamedParameter(''))
			->set('company_id', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->executeStatement();
	}

	/**
	 * Personal failed drafts: owner (created_by / mailbox owner) only.
	 * Team failed drafts: any workspace mail.read (existing shared outbox).
	 *
	 * @param array<string, mixed> $row
	 */
	private function actorMaySeeFailedDraft(array $row, string $actor): bool
	{
		return MailAclLogic::actorMayAccessFailedDraft(
			$actor,
			isset($row['mailbox_kind']) ? (string)$row['mailbox_kind'] : null,
			isset($row['created_by']) ? (string)$row['created_by'] : null,
			isset($row['mailbox_owner']) ? (string)$row['mailbox_owner'] : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loadFailedRow(int $workspaceId, int $draftId, string $actor): array
	{
		if (!$this->db->tableExists('crm_mail_dft') || $draftId <= 0 || $workspaceId <= 0) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists.'));
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.*', 'b.kind AS mailbox_kind', 'b.owner_user_id AS mailbox_owner')
			->from('crm_mail_dft', 'd')
			->leftJoin('d', 'crm_mailbox', 'b', $qb->expr()->eq('d.mailbox_id', 'b.id'))
			->where($qb->expr()->eq('d.id', $qb->createNamedParameter($draftId)))
			->andWhere($qb->expr()->eq('d.workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->isNull('d.idempotency_key'))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false || !$this->actorMaySeeFailedDraft($row, $actor)) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists (or was already sent).'));
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function summarise(array $row, bool $includeBody): array
	{
		$out = [
			'id' => (int)$row['id'],
			'mailboxId' => (int)$row['mailbox_id'],
			'companyId' => isset($row['company_id']) && $row['company_id'] !== null
				? (int)$row['company_id']
				: null,
			'to' => (string)($row['to_addrs'] ?? ''),
			'subject' => (string)($row['subject'] ?? ''),
			'createdBy' => (string)($row['created_by'] ?? ''),
			'createdAt' => $row['created_at'] ?? null,
			'updatedAt' => $row['updated_at'] ?? null,
			'status' => 'failed',
		];
		if ($includeBody) {
			$out['body'] = (string)($row['body_text'] ?? '');
		}

		return $out;
	}

	/**
	 * SF-04 — purge aged failed drafts and abandoned hollow claims.
	 *
	 * @return array{failedDrafts: int, hollowClaims: int}
	 */
	public function purgeStale(int $batch = Constants::MAIL_DRAFT_PURGE_BATCH): array
	{
		$batch = max(1, min(500, $batch));
		$failed = 0;
		$hollow = 0;
		if (!$this->db->tableExists('crm_mail_dft')) {
			return ['failedDrafts' => 0, 'hollowClaims' => 0];
		}

		$now = $this->timeFactory->getDateTime();
		$failedCutoff = (clone $now)
			->modify('-' . Constants::MAIL_FAILED_DRAFT_TTL_DAYS . ' days')
			->format('Y-m-d H:i:s');
		$hollowCutoff = (clone $now)
			->modify('-' . Constants::MAIL_HOLLOW_CLAIM_TTL_HOURS . ' hours')
			->format('Y-m-d H:i:s');

		$failed = $this->deleteDraftBatch(
			static function ($qb) use ($failedCutoff) {
				$qb->andWhere($qb->expr()->isNull('idempotency_key'))
					->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($failedCutoff)));
			},
			$batch,
		);

		// Hollow claims: key present, never SMTP-acked. Successful ledger rows (smtp_acked=1) must stay.
		try {
			$hollowQb = $this->db->getQueryBuilder();
			$hollowQb->select('id')->from('crm_mail_dft')
				->where($hollowQb->expr()->isNotNull('idempotency_key'))
				->andWhere($hollowQb->expr()->eq('smtp_acked', $hollowQb->createNamedParameter(0)))
				->andWhere($hollowQb->expr()->lt('updated_at', $hollowQb->createNamedParameter($hollowCutoff)))
				->setMaxResults($batch);
			$hr = $hollowQb->executeQuery();
			$ids = [];
			while ($row = $hr->fetch()) {
				$ids[] = (int)$row['id'];
			}
			$hr->closeCursor();
			foreach ($ids as $id) {
				if ($id <= 0) {
					continue;
				}
				$del = $this->db->getQueryBuilder();
				$del->delete('crm_mail_dft')
					->where($del->expr()->eq('id', $del->createNamedParameter($id)))
					->executeStatement();
				$hollow++;
			}
		} catch (\Throwable) {
			// smtp_acked absent mid-upgrade — skip hollow claim purge (do not delete success ledger).
		}

		return ['failedDrafts' => $failed, 'hollowClaims' => $hollow];
	}

	/**
	 * @param callable(\OCP\DB\QueryBuilder\IQueryBuilder):void $constrain
	 */
	private function deleteDraftBatch(callable $constrain, int $batch): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('crm_mail_dft')->setMaxResults($batch);
		$constrain($qb);
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$r->closeCursor();
		$n = 0;
		foreach ($ids as $id) {
			if ($id <= 0) {
				continue;
			}
			$del = $this->db->getQueryBuilder();
			$del->delete('crm_mail_dft')
				->where($del->expr()->eq('id', $del->createNamedParameter($id)))
				->executeStatement();
			$n++;
		}

		return $n;
	}
}
