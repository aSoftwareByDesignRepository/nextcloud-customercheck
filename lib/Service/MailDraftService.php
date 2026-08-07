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
	public function listFailed(int $workspaceId, string $workspaceName, int $limit = 50): array
	{
		$limit = max(1, min(100, $limit));
		$items = [];
		if ($this->db->tableExists('crm_mail_dft')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'mailbox_id', 'company_id', 'to_addrs', 'subject', 'created_by', 'created_at', 'updated_at')
				->from('crm_mail_dft')
				->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
				->andWhere($qb->expr()->isNull('idempotency_key'))
				->orderBy('updated_at', 'DESC')
				->setMaxResults($limit);
			$r = $qb->executeQuery();
			while ($row = $r->fetch()) {
				$items[] = $this->summarise($row, false);
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
	public function getFailed(int $workspaceId, int $draftId): array
	{
		$row = $this->loadFailedRow($workspaceId, $draftId);

		return $this->summarise($row, true);
	}

	public function deleteFailed(int $workspaceId, int $draftId): void
	{
		if (!$this->db->tableExists('crm_mail_dft') || $draftId <= 0) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists.'));
		}
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->delete('crm_mail_dft')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($draftId)))
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
		$row = $this->loadFailedRow($workspaceId, $draftId);
		$key = trim($idempotencyKey);
		if ($key === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'idempotencyKey',
				$this->l10n->t('A send key is required so this message is not sent twice.'),
			);
		}

		return $this->mailSend->send($workspaceId, [
			'mailboxId' => (int)$row['mailbox_id'],
			'to' => (string)($row['to_addrs'] ?? ''),
			'subject' => (string)($row['subject'] ?? ''),
			'body' => (string)($row['body_text'] ?? ''),
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
	 * @return array<string, mixed>
	 */
	private function loadFailedRow(int $workspaceId, int $draftId): array
	{
		if (!$this->db->tableExists('crm_mail_dft') || $draftId <= 0 || $workspaceId <= 0) {
			throw new NotFoundException($this->l10n->t('This draft no longer exists.'));
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_mail_dft')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($draftId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->isNull('idempotency_key'))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
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
}
