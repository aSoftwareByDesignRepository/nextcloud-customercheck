<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;

/**
 * Shared mailbox config — product §14.
 * Secrets encrypted; never returned in GET JSON.
 */
class MailboxService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ICrypto $crypto,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function listMailboxes(int $workspaceId): array
	{
		if (!$this->db->tableExists('crm_mailbox')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'workspace_id', 'label', 'email_address', 'imap_host', 'imap_port', 'imap_enc',
			'smtp_host', 'smtp_port', 'smtp_enc', 'username', 'status', 'auth_fail_count', 'last_sync_at', 'last_error', 'dsgvo_ack_at')
			->from('crm_mailbox')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)));
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'label' => (string)$row['label'],
				'emailAddress' => (string)$row['email_address'],
				'imapHost' => (string)$row['imap_host'],
				'imapPort' => (int)$row['imap_port'],
				'imapEnc' => (string)$row['imap_enc'],
				'smtpHost' => (string)$row['smtp_host'],
				'smtpPort' => (int)$row['smtp_port'],
				'smtpEnc' => (string)$row['smtp_enc'],
				'username' => (string)$row['username'],
				'status' => (string)$row['status'],
				'authFailCount' => (int)$row['auth_fail_count'],
				'lastSyncAt' => $row['last_sync_at'] ?? null,
				'lastError' => $row['last_error'] ?? null,
				'dsgvoAckAt' => $row['dsgvo_ack_at'] ?? null,
				'hasSecret' => true,
			];
		}
		$r->closeCursor();
		return $out;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function save(int $workspaceId, array $data, string $actor): array
	{
		$existing = $this->listMailboxes($workspaceId);
		if (count($existing) >= Constants::MAILBOX_MAX_PER_WS && empty($data['id'])) {
			throw ValidationException::field(Constants::ERR_MAILBOX_LIMIT, 'mailbox', $this->l10n->t('Maximum of 3 shared mailboxes per workspace.'));
		}
		if (empty($data['dsgvoAck'])) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'dsgvoAck', $this->l10n->t('Confirm the shared-mailbox DSGVO acknowledgement.'));
		}
		$secret = (string)($data['password'] ?? '');
		if ($secret === '' && empty($data['id'])) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'password', $this->l10n->t('Enter the mailbox password.'));
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$enc = $secret !== '' ? $this->crypto->encrypt($secret) : null;

		if (!empty($data['id'])) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('crm_mailbox')
				->set('label', $qb->createNamedParameter((string)$data['label']))
				->set('email_address', $qb->createNamedParameter((string)$data['emailAddress']))
				->set('imap_host', $qb->createNamedParameter((string)$data['imapHost']))
				->set('imap_port', $qb->createNamedParameter((int)($data['imapPort'] ?? 993)))
				->set('smtp_host', $qb->createNamedParameter((string)$data['smtpHost']))
				->set('smtp_port', $qb->createNamedParameter((int)($data['smtpPort'] ?? 587)))
				->set('username', $qb->createNamedParameter((string)$data['username']))
				->set('dsgvo_ack_at', $qb->createNamedParameter($now))
				->set('dsgvo_ack_by', $qb->createNamedParameter($actor))
				->set('updated_at', $qb->createNamedParameter($now));
			if ($enc !== null) {
				$qb->set('secret_enc', $qb->createNamedParameter($enc));
			}
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$data['id'])))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
				->executeStatement();
			$id = (int)$data['id'];
		} else {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('crm_mailbox')->values([
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'label' => $ins->createNamedParameter((string)$data['label']),
				'email_address' => $ins->createNamedParameter((string)$data['emailAddress']),
				'imap_host' => $ins->createNamedParameter((string)$data['imapHost']),
				'imap_port' => $ins->createNamedParameter((int)($data['imapPort'] ?? 993)),
				'imap_enc' => $ins->createNamedParameter((string)($data['imapEnc'] ?? 'ssl')),
				'smtp_host' => $ins->createNamedParameter((string)$data['smtpHost']),
				'smtp_port' => $ins->createNamedParameter((int)($data['smtpPort'] ?? 587)),
				'smtp_enc' => $ins->createNamedParameter((string)($data['smtpEnc'] ?? 'tls')),
				'username' => $ins->createNamedParameter((string)$data['username']),
				'secret_enc' => $ins->createNamedParameter((string)$enc),
				'dsgvo_ack_at' => $ins->createNamedParameter($now),
				'dsgvo_ack_by' => $ins->createNamedParameter($actor),
				'status' => $ins->createNamedParameter('ok'),
				'auth_fail_count' => $ins->createNamedParameter(0),
				'created_by' => $ins->createNamedParameter($actor),
				'created_at' => $ins->createNamedParameter($now),
				'updated_at' => $ins->createNamedParameter($now),
			])->executeStatement();
			$id = (int)$this->db->lastInsertId('crm_mailbox');
		}

		$folders = array_slice(array_values(array_filter(array_map('strval', $data['folders'] ?? []))), 0, Constants::MAILBOX_MAX_FOLDERS);
		$this->replaceFolders($id, $folders);
		return ['id' => $id, 'label' => (string)$data['label']];
	}

	/** @param list<string> $folders */
	private function replaceFolders(int $mailboxId, array $folders): void
	{
		$del = $this->db->getQueryBuilder();
		$del->delete('crm_mail_fld')->where($del->expr()->eq('mailbox_id', $del->createNamedParameter($mailboxId)))->executeStatement();
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		foreach ($folders as $path) {
			$path = trim($path);
			if ($path === '') {
				continue;
			}
			$ins = $this->db->getQueryBuilder();
			$ins->insert('crm_mail_fld')->values([
				'mailbox_id' => $ins->createNamedParameter($mailboxId),
				'folder_path' => $ins->createNamedParameter($path),
				'sync_enabled' => $ins->createNamedParameter(1),
				'created_at' => $ins->createNamedParameter($now),
			])->executeStatement();
		}
	}

	public function disclosure(string $mailboxLabel, string $workspaceName): string
	{
		return $this->l10n->t(
			'These messages come from the shared mailbox %1$s. Everyone in workspace %2$s who can read mail can see them.',
			[$mailboxLabel, $workspaceName]
		);
	}

	/** Index a synced message + auto-match. */
	public function ingestMessage(int $workspaceId, int $mailboxId, int $folderId, array $msg, array $persons): array
	{
		$match = MailMatchLogic::match(
			isset($msg['manualCompanyId']) ? (int)$msg['manualCompanyId'] : null,
			(string)($msg['from'] ?? ''),
			$persons,
		);
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$preview = isset($msg['preview']) ? mb_substr((string)$msg['preview'], 0, Constants::MAIL_PREVIEW_MAX_BYTES) : null;
		$ins = $this->db->getQueryBuilder();
		try {
			$ins->insert('crm_mail_msg')->values([
				'mailbox_id' => $ins->createNamedParameter($mailboxId),
				'folder_id' => $ins->createNamedParameter($folderId),
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'company_id' => $ins->createNamedParameter($match['companyId']),
				'message_uid' => $ins->createNamedParameter((string)$msg['uid']),
				'message_id' => $ins->createNamedParameter($msg['messageId'] ?? null),
				'direction' => $ins->createNamedParameter((string)($msg['direction'] ?? 'inbound')),
				'from_addr' => $ins->createNamedParameter((string)($msg['from'] ?? '')),
				'to_addrs' => $ins->createNamedParameter(isset($msg['to']) ? json_encode($msg['to']) : null),
				'subject' => $ins->createNamedParameter(mb_substr((string)($msg['subject'] ?? ''), 0, 512)),
				'snippet' => $ins->createNamedParameter(mb_substr((string)($msg['snippet'] ?? ''), 0, 512)),
				'preview' => $ins->createNamedParameter($preview),
				'sent_at' => $ins->createNamedParameter($msg['sentAt'] ?? $now),
				'matched_how' => $ins->createNamedParameter($match['how']),
				'triage_status' => $ins->createNamedParameter($match['companyId'] ? 'matched' : 'open'),
				'created_at' => $ins->createNamedParameter($now),
			])->executeStatement();
		} catch (\Throwable) {
			// unique uid — already indexed
		}
		return $match;
	}

	public function recordAuthFailure(int $mailboxId, int $currentFails): string
	{
		$next = $currentFails + 1;
		$status = MailMatchLogic::nextAuthStatus($next);
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('auth_fail_count', $qb->createNamedParameter($next))
			->set('status', $qb->createNamedParameter($status))
			->set('last_error', $qb->createNamedParameter($status === 'error' ? 'IMAP auth failed ×3' : 'IMAP auth failed'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->executeStatement();
		return $status;
	}

	/** @return list<array<string, mixed>> */
	public function unmatched(int $workspaceId, int $limit = 50): array
	{
		if (!$this->db->tableExists('crm_mail_msg')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_mail_msg')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('open')))
			->orderBy('sent_at', 'DESC')
			->setMaxResults($limit);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'subject' => (string)($row['subject'] ?? ''),
				'from' => (string)($row['from_addr'] ?? ''),
				'sentAt' => $row['sent_at'] ?? null,
				'shared' => true,
			];
		}
		$r->closeCursor();
		return $out;
	}

	public function triageAssign(int $messageId, int $workspaceId, int $companyId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mail_msg')
			->set('company_id', $qb->createNamedParameter($companyId))
			->set('matched_how', $qb->createNamedParameter('manual'))
			->set('triage_status', $qb->createNamedParameter('matched'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->executeStatement();
	}

	/**
	 * Matched shared-mailbox messages for a company (Company 360 feed).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function matchedForCompany(int $companyId, int $limit = 50): array
	{
		if (!$this->db->tableExists('crm_mail_msg')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.*', 'b.label AS mailbox_label')
			->from('crm_mail_msg', 'm')
			->leftJoin('m', 'crm_mailbox', 'b', $qb->expr()->eq('m.mailbox_id', 'b.id'))
			->where($qb->expr()->eq('m.company_id', $qb->createNamedParameter($companyId)))
			->andWhere($qb->expr()->eq('m.triage_status', $qb->createNamedParameter('matched')))
			->orderBy('m.sent_at', 'DESC')
			->setMaxResults(max(1, min(100, $limit)));
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'subject' => (string)($row['subject'] ?? ''),
				'snippet' => (string)($row['snippet'] ?? ''),
				'direction' => (string)($row['direction'] ?? 'inbound'),
				'sentAt' => $row['sent_at'] ?? null,
				'mailboxLabel' => (string)($row['mailbox_label'] ?? 'shared'),
				'messageId' => $row['message_id'] ?? null,
				'shared' => true,
			];
		}
		$r->closeCursor();
		return $out;
	}
}
