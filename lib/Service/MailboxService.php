<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\ImapConnection;
use OCA\CustomerCheck\Support\MailPreviewText;
use OCA\CustomerCheck\Support\MimeHeader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
			'smtp_host', 'smtp_port', 'smtp_enc', 'username', 'secret_enc', 'status', 'auth_fail_count', 'last_sync_at', 'last_error', 'dsgvo_ack_at')
			->from('crm_mailbox')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)));
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$id = (int)$row['id'];
			$out[] = [
				'id' => $id,
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
				'hasSecret' => trim((string)($row['secret_enc'] ?? '')) !== '',
				'folders' => $this->listFolderPaths($id),
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

		$imapPort = (int)($data['imapPort'] ?? 993);
		$smtpPort = (int)($data['smtpPort'] ?? 465);
		if ($imapPort <= 0) {
			$imapPort = 993;
		}
		if ($smtpPort <= 0) {
			$smtpPort = 465;
		}
		$imapHost = ImapConnection::normalizeHost((string)($data['imapHost'] ?? ''));
		$smtpHost = ImapConnection::normalizeHost((string)($data['smtpHost'] ?? ''));
		$username = ImapConnection::normalizeUsername((string)($data['username'] ?? ''));
		$emailAddress = trim((string)($data['emailAddress'] ?? $data['email'] ?? ''));
		$imapEnc = ImapConnection::resolveImapEnc(
			isset($data['imapEnc']) ? (string)$data['imapEnc'] : null,
			$imapPort,
		);
		$smtpEnc = ImapConnection::resolveSmtpEnc(
			isset($data['smtpEnc']) ? (string)$data['smtpEnc'] : null,
			$smtpPort,
		);
		if ($imapHost === '' || $smtpHost === '' || $username === '' || $emailAddress === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'imapHost',
				$this->l10n->t('Email, username, IMAP host and SMTP host are required.'),
			);
		}
		if (ImapConnection::isBlockedMailHost($imapHost) || ImapConnection::isBlockedMailHost($smtpHost)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'imapHost',
				$this->l10n->t('Use a public mail host — localhost and private network addresses are not allowed.'),
			);
		}

		if (!empty($data['id'])) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('crm_mailbox')
				->set('label', $qb->createNamedParameter((string)$data['label']))
				->set('email_address', $qb->createNamedParameter($emailAddress))
				->set('imap_host', $qb->createNamedParameter($imapHost))
				->set('imap_port', $qb->createNamedParameter($imapPort))
				->set('imap_enc', $qb->createNamedParameter($imapEnc))
				->set('smtp_host', $qb->createNamedParameter($smtpHost))
				->set('smtp_port', $qb->createNamedParameter($smtpPort))
				->set('smtp_enc', $qb->createNamedParameter($smtpEnc))
				->set('username', $qb->createNamedParameter($username))
				->set('dsgvo_ack_at', $qb->createNamedParameter($now))
				->set('dsgvo_ack_by', $qb->createNamedParameter($actor))
				->set('updated_at', $qb->createNamedParameter($now));
			if ($enc !== null) {
				$qb->set('secret_enc', $qb->createNamedParameter($enc));
			}
			// Successful save follows a connection test — clear hard-error quarantine so
			// MailboxSyncJob / Sync now can run again after credential fixes.
			$qb->set('status', $qb->createNamedParameter('ok'))
				->set('auth_fail_count', $qb->createNamedParameter(0))
				->set('last_error', $qb->createNamedParameter(null));
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$data['id'])))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
				->executeStatement();
			$id = (int)$data['id'];
		} else {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('crm_mailbox')->values([
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'label' => $ins->createNamedParameter((string)$data['label']),
				'email_address' => $ins->createNamedParameter($emailAddress),
				'imap_host' => $ins->createNamedParameter($imapHost),
				'imap_port' => $ins->createNamedParameter($imapPort),
				'imap_enc' => $ins->createNamedParameter($imapEnc),
				'smtp_host' => $ins->createNamedParameter($smtpHost),
				'smtp_port' => $ins->createNamedParameter($smtpPort),
				'smtp_enc' => $ins->createNamedParameter($smtpEnc),
				'username' => $ins->createNamedParameter($username),
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

		// Only replace folders when the client explicitly sends them. Omitting
		// the key on update keeps existing sync folders (edit-without-wipe).
		if (array_key_exists('folders', $data)) {
			$folders = array_slice(array_values(array_filter(array_map('strval', is_array($data['folders']) ? $data['folders'] : []))), 0, Constants::MAILBOX_MAX_FOLDERS);
			if ($folders === [] && empty($data['id'])) {
				$folders = ['INBOX'];
			}
			if ($folders !== [] || empty($data['id'])) {
				$this->replaceFolders($id, $folders === [] ? ['INBOX'] : $folders);
			}
		} elseif (empty($data['id'])) {
			$this->replaceFolders($id, ['INBOX']);
		}
		return ['id' => $id, 'label' => (string)$data['label']];
	}

	/**
	 * Remove a shared mailbox and its CRM index (folders, messages, drafts).
	 * Also drops open/snoozed shared-mail Review rows that pointed at those messages
	 * so Assign cannot hit a missing mailMsgId after remove.
	 * Does not touch the remote IMAP account.
	 */
	public function delete(int $workspaceId, int $mailboxId): void
	{
		if ($workspaceId <= 0 || $mailboxId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'id',
				$this->l10n->t('Choose a mailbox to remove.'),
			);
		}
		if (!$this->db->tableExists('crm_mailbox')) {
			throw new NotFoundException($this->l10n->t('Mailbox not found in this workspace.'));
		}
		$check = $this->db->getQueryBuilder();
		$check->select('id')->from('crm_mailbox')
			->where($check->expr()->eq('id', $check->createNamedParameter($mailboxId)))
			->andWhere($check->expr()->eq('workspace_id', $check->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $check->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('Mailbox not found in this workspace.'));
		}

		$mailMsgIds = $this->listMessageIdsForMailbox($workspaceId, $mailboxId);
		$this->purgeOpenSharedMailReviewForMessages($workspaceId, $mailMsgIds);

		if ($this->db->tableExists('crm_mail_msg')) {
			$delMsg = $this->db->getQueryBuilder();
			$delMsg->delete('crm_mail_msg')
				->where($delMsg->expr()->eq('mailbox_id', $delMsg->createNamedParameter($mailboxId)))
				->andWhere($delMsg->expr()->eq('workspace_id', $delMsg->createNamedParameter($workspaceId)))
				->executeStatement();
		}
		if ($this->db->tableExists('crm_mail_dft')) {
			$delDft = $this->db->getQueryBuilder();
			$delDft->delete('crm_mail_dft')
				->where($delDft->expr()->eq('mailbox_id', $delDft->createNamedParameter($mailboxId)))
				->andWhere($delDft->expr()->eq('workspace_id', $delDft->createNamedParameter($workspaceId)))
				->executeStatement();
		}
		if ($this->db->tableExists('crm_mail_fld')) {
			$delFld = $this->db->getQueryBuilder();
			$delFld->delete('crm_mail_fld')
				->where($delFld->expr()->eq('mailbox_id', $delFld->createNamedParameter($mailboxId)))
				->executeStatement();
		}
		$delBox = $this->db->getQueryBuilder();
		$delBox->delete('crm_mailbox')
			->where($delBox->expr()->eq('id', $delBox->createNamedParameter($mailboxId)))
			->andWhere($delBox->expr()->eq('workspace_id', $delBox->createNamedParameter($workspaceId)))
			->executeStatement();
	}

	/**
	 * @return list<int>
	 */
	private function listMessageIdsForMailbox(int $workspaceId, int $mailboxId): array
	{
		if (!$this->db->tableExists('crm_mail_msg')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('crm_mail_msg')
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)));
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$id = (int)($row['id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		$r->closeCursor();

		return $ids;
	}

	/**
	 * Drop non-terminal shared_mail Review rows whose source_ref.mailMsgId was indexed
	 * from this mailbox — prevents Assign against deleted CRM mail rows.
	 *
	 * @param list<int> $mailMsgIds
	 */
	private function purgeOpenSharedMailReviewForMessages(int $workspaceId, array $mailMsgIds): void
	{
		if ($mailMsgIds === [] || !$this->db->tableExists('crm_review_item')) {
			return;
		}
		$wanted = [];
		foreach ($mailMsgIds as $id) {
			$id = (int)$id;
			if ($id > 0) {
				$wanted[$id] = true;
			}
		}
		if ($wanted === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'source_ref')->from('crm_review_item')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('source_kind', $qb->createNamedParameter('shared_mail')))
			->andWhere($qb->expr()->in(
				'status',
				$qb->createNamedParameter(['open', 'follow_up', 'snoozed'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY),
			));
		$r = $qb->executeQuery();
		$drop = [];
		while ($row = $r->fetch()) {
			$ref = json_decode((string)($row['source_ref'] ?? ''), true);
			$mailId = is_array($ref) ? (int)($ref['mailMsgId'] ?? 0) : 0;
			if ($mailId > 0 && isset($wanted[$mailId])) {
				$drop[] = (int)$row['id'];
			}
		}
		$r->closeCursor();
		if ($drop === []) {
			return;
		}
		foreach (array_chunk($drop, 100) as $chunk) {
			$del = $this->db->getQueryBuilder();
			$del->delete('crm_review_item')
				->where($del->expr()->in('id', $del->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($del->expr()->eq('workspace_id', $del->createNamedParameter($workspaceId)))
				->executeStatement();
		}
	}

	/**
	 * @return list<string>
	 */
	public function listFolderPaths(int $mailboxId): array
	{
		if ($mailboxId <= 0 || !$this->db->tableExists('crm_mail_fld')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('folder_path')
			->from('crm_mail_fld')
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
			->orderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$path = trim((string)($row['folder_path'] ?? ''));
			if ($path !== '') {
				$out[] = $path;
			}
		}
		$r->closeCursor();

		return $out;
	}

	/** @param list<string> $folders */
	private function replaceFolders(int $mailboxId, array $folders): void
	{
		$del = $this->db->getQueryBuilder();
		$del->delete('crm_mail_fld')->where($del->expr()->eq('mailbox_id', $del->createNamedParameter($mailboxId)))->executeStatement();
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		foreach ($folders as $path) {
			$path = trim($path);
			if ($path === '' || !ImapConnection::isSafeImapFolder($path)) {
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
		$subject = mb_substr(MimeHeader::decode((string)($msg['subject'] ?? '')), 0, 512);
		$snippetRaw = (string)($msg['snippet'] ?? '');
		$snippet = mb_substr(MimeHeader::decode($snippetRaw !== '' ? $snippetRaw : $subject), 0, 512);
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
				'subject' => $ins->createNamedParameter($subject),
				'snippet' => $ins->createNamedParameter($snippet),
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
				'subject' => MimeHeader::decode((string)($row['subject'] ?? '')),
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
		if ($messageId <= 0 || $workspaceId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'messageId',
				$this->l10n->t('Choose a message and workspace to triage.'),
			);
		}
		if ($companyId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'companyId',
				$this->l10n->t('Pick a company in this workspace.'),
			);
		}
		// Cross-book guard: never attach shared mail to a foreign workspace company.
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

		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_mail_msg')
			->set('company_id', $qb->createNamedParameter($companyId))
			->set('matched_how', $qb->createNamedParameter('manual'))
			->set('triage_status', $qb->createNamedParameter('matched'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			// Only open triage rows — never silently rematch an already-matched message.
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('open')))
			->executeStatement();
		if ($affected < 1) {
			// Concurrent assign/discard race — fail closed with a clear reload cue.
			throw new NotFoundException($this->l10n->t('Someone else already handled this message. Reload Review and try again.'));
		}
	}

	/**
	 * Re-open matched shared-mail rows for a company sender so they can be
	 * triaged again in Review (assign to another company). Does not delete IMAP.
	 * Skips remote_deleted / discarded rows.
	 *
	 * @return list<int> message ids that were unmatched
	 */
	public function unmatchByCompanyAndFrom(int $workspaceId, int $companyId, string $fromEmail): array
	{
		$fromEmail = strtolower(trim($fromEmail));
		if ($workspaceId <= 0 || $companyId <= 0 || $fromEmail === '' || !$this->db->tableExists('crm_mail_msg')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'from_addr')
			->from('crm_mail_msg')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('matched')));
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$from = strtolower(trim((string)($row['from_addr'] ?? '')));
			if ($from !== '' && strcasecmp($fromEmail, $from) === 0) {
				$ids[] = (int)$row['id'];
			}
		}
		$r->closeCursor();
		if ($ids === []) {
			return [];
		}
		foreach (array_chunk($ids, 100) as $chunk) {
			$upd = $this->db->getQueryBuilder();
			$params = [];
			foreach ($chunk as $i => $mid) {
				$params[] = $upd->createNamedParameter($mid, IQueryBuilder::PARAM_INT);
			}
			$upd->update('crm_mail_msg')
				->set('company_id', $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('matched_how', $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('triage_status', $upd->createNamedParameter('open'))
				->where($upd->expr()->in('id', $params))
				->andWhere($upd->expr()->eq('workspace_id', $upd->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('triage_status', $upd->createNamedParameter('matched')))
				->executeStatement();
		}

		return $ids;
	}

	/**
	 * Drop an unmatched shared-mail copy from CRM triage after Review discard.
	 * Remote IMAP delete (when requested) is handled by MailboxSyncService — this
	 * only updates the CRM index. Idempotent when already discarded / purged.
	 */
	public function discardUnmatchedMessage(int $workspaceId, int $messageId): void
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mail_msg')
			->set('triage_status', $qb->createNamedParameter('discarded'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('open')))
			->executeStatement();
	}

	/**
	 * Mark CRM copy as purged after a successful remote IMAP delete so manager
	 * reopen cannot resurrect triage for a message that no longer exists on the host.
	 */
	public function markRemoteDeleted(int $workspaceId, int $messageId): void
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mail_msg')
			->set('triage_status', $qb->createNamedParameter('remote_deleted'))
			->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('matched_how', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('triage_status', $qb->createNamedParameter('remote_deleted')))
			->executeStatement();
	}

	/**
	 * Whether CRM already recorded a successful remote IMAP delete for this message.
	 */
	public function isRemoteDeleted(int $workspaceId, int $messageId): bool
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('crm_mail_msg')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('remote_deleted')))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$found = $r->fetch() !== false;
		$r->closeCursor();

		return $found;
	}

	/**
	 * Restore a discarded shared-mail copy so manager reopen → Assign can triage again.
	 * Never resurrects remote_deleted (IMAP copy is gone).
	 */
	public function restoreDiscardedMessage(int $workspaceId, int $messageId): void
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mail_msg')
			->set('triage_status', $qb->createNamedParameter('open'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('discarded')))
			->executeStatement();
	}

	/**
	 * Load one indexed message for preview (workspace-scoped).
	 *
	 * @return ?array{id: int, mailboxId: int, folderId: int, folderPath: string, messageUid: string, subject: string, from: string, preview: string, triageStatus: string}
	 */
	public function findMessageForPreview(int $workspaceId, int $messageId): ?array
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.*', 'f.folder_path')
			->from('crm_mail_msg', 'm')
			->leftJoin('m', 'crm_mail_fld', 'f', $qb->expr()->eq('m.folder_id', 'f.id'))
			->where($qb->expr()->eq('m.id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return null;
		}

		return [
			'id' => (int)$row['id'],
			'mailboxId' => (int)$row['mailbox_id'],
			'folderId' => (int)$row['folder_id'],
			'folderPath' => trim((string)($row['folder_path'] ?? '')) !== '' ? (string)$row['folder_path'] : 'INBOX',
			'messageUid' => (string)($row['message_uid'] ?? ''),
			'subject' => MimeHeader::decode((string)($row['subject'] ?? '')),
			'from' => (string)($row['from_addr'] ?? ''),
			'preview' => (string)($row['preview'] ?? ''),
			'triageStatus' => (string)($row['triage_status'] ?? ''),
		];
	}

	/**
	 * Cache a plain-text preview on the indexed message (best-effort).
	 */
	public function cacheMessagePreview(int $workspaceId, int $messageId, string $preview): void
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return;
		}
		// Byte-cap + HTML strip — never store more than MAIL_PREVIEW_MAX_BYTES on disk.
		$preview = MailPreviewText::toPlainPreview($preview, Constants::MAIL_PREVIEW_MAX_BYTES);
		if ($preview === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mail_msg')
			->set('preview', $qb->createNamedParameter($preview))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
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
			$preview = trim((string)($row['preview'] ?? ''));
			$out[] = [
				'id' => (int)$row['id'],
				'subject' => MimeHeader::decode((string)($row['subject'] ?? '')),
				'snippet' => MimeHeader::decode((string)($row['snippet'] ?? '')),
				'from' => (string)($row['from_addr'] ?? ''),
				'hasPreview' => $preview !== '',
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

	/**
	 * Delete aged shared-mailbox index rows for one workspace (§5.10 / §18.9).
	 * Uses COALESCE(sent_at, created_at) semantics without raw SQL — portable
	 * across MySQL/PostgreSQL/Oracle. Does not touch IMAP; CRM index only.
	 *
	 * @return int number of rows deleted in this batch
	 */
	public function purgeExpired(
		int $workspaceId,
		int $retentionDays,
		int $limit = Constants::MAIL_PURGE_BATCH,
	): int {
		if ($workspaceId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return 0;
		}
		$retentionDays = max(Constants::MAIL_RETENTION_DAYS_MIN, $retentionDays);
		$limit = max(1, min(Constants::MAIL_PURGE_BATCH, $limit));
		$cutoff = $this->retentionCutoff($retentionDays);

		$select = $this->db->getQueryBuilder();
		$select->select('id')->from('crm_mail_msg')
			->where($select->expr()->eq('workspace_id', $select->createNamedParameter($workspaceId)))
			->andWhere($this->expiredExpr($select, $cutoff))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		$result = $select->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		if ($ids === []) {
			return 0;
		}

		$deleted = 0;
		foreach (array_chunk($ids, 100) as $chunk) {
			$del = $this->db->getQueryBuilder();
			$del->delete('crm_mail_msg')
				->where($del->expr()->in('id', $del->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($del->expr()->eq('workspace_id', $del->createNamedParameter($workspaceId)));
			$deleted += $del->executeStatement();
		}
		return $deleted;
	}

	/**
	 * How many aged CRM index rows remain for this workspace after a purge pass.
	 */
	public function countExpired(int $workspaceId, int $retentionDays): int
	{
		if ($workspaceId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return 0;
		}
		$retentionDays = max(Constants::MAIL_RETENTION_DAYS_MIN, $retentionDays);
		$cutoff = $this->retentionCutoff($retentionDays);
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('crm_mail_msg')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($this->expiredExpr($qb, $cutoff));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	private function retentionCutoff(int $retentionDays): string
	{
		return (clone $this->timeFactory->getDateTime())
			->modify('-' . $retentionDays . ' days')
			->format('Y-m-d H:i:s');
	}

	/**
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb
	 */
	private function expiredExpr($qb, string $cutoff): mixed
	{
		return $qb->expr()->orX(
			$qb->expr()->andX(
				$qb->expr()->isNotNull('sent_at'),
				$qb->expr()->lt('sent_at', $qb->createNamedParameter($cutoff)),
			),
			$qb->expr()->andX(
				$qb->expr()->isNull('sent_at'),
				$qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff)),
			),
		);
	}
}
