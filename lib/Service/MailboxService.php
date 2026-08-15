<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\ImapConnection;
use OCA\CustomerCheck\Support\MailPreviewText;
use OCA\CustomerCheck\Support\MimeHeader;
use OCA\CustomerCheck\Support\SharedMailboxSmtpClient;
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
		private readonly HtmlSanitizeService $htmlSanitize,
		private readonly ?NotificationService $notifications = null,
	) {
	}

	/**
	 * @param 'team'|'personal'|null $kind null = all kinds
	 * @return list<array<string, mixed>>
	 */
	public function listMailboxes(int $workspaceId, ?string $kind = MailAclLogic::KIND_TEAM): array
	{
		if (!$this->db->tableExists('crm_mailbox')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'workspace_id', 'label', 'email_address', 'imap_host', 'imap_port', 'imap_enc',
			'smtp_host', 'smtp_port', 'smtp_enc', 'username', 'secret_enc', 'status', 'auth_fail_count',
			'last_sync_at', 'last_error', 'dsgvo_ack_at', 'kind', 'owner_user_id', 'auth_kind',
			'signature_html', 'signature_plain')
			->from('crm_mailbox')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)));
		if ($kind !== null && $kind !== '') {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$id = (int)$row['id'];
			$boxKind = (string)($row['kind'] ?? MailAclLogic::KIND_TEAM);
			$sigHtml = (string)($row['signature_html'] ?? '');
			$sigPlain = (string)($row['signature_plain'] ?? '');
			$out[] = [
				'id' => $id,
				'kind' => $boxKind !== '' ? $boxKind : MailAclLogic::KIND_TEAM,
				'ownerUserId' => $row['owner_user_id'] ?? null,
				'authKind' => (string)($row['auth_kind'] ?? 'imap'),
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
				'signatureHtml' => $sigHtml,
				'signaturePlain' => $sigPlain,
				'hasSignature' => trim($sigHtml) !== '' || trim($sigPlain) !== '',
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
		$existing = $this->listMailboxes($workspaceId, MailAclLogic::KIND_TEAM);
		if (count($existing) >= Constants::MAILBOX_MAX_PER_WS && empty($data['id'])) {
			throw ValidationException::field(Constants::ERR_MAILBOX_LIMIT, 'mailbox', $this->l10n->t('Maximum of 3 shared mailboxes per workspace.'));
		}
		if (!empty($data['id'])) {
			$existingKind = $this->mailboxKind((int)$data['id'], $workspaceId);
			if ($existingKind === MailAclLogic::KIND_PERSONAL) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'id',
					$this->l10n->t('Use My email settings to change a personal mailbox.'),
				);
			}
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
		$emailAddress = strtolower(trim((string)($data['emailAddress'] ?? $data['email'] ?? '')));
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
			$this->clearSyncDialFailure($id);
		} else {
			$ins = $this->db->getQueryBuilder();
			$ins->insert('crm_mailbox')->values([
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'kind' => $ins->createNamedParameter(MailAclLogic::KIND_TEAM),
				'owner_user_id' => $ins->createNamedParameter(null),
				'auth_kind' => $ins->createNamedParameter('imap'),
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
	 * Team mailbox signature — mailbox admins only (caller enforces capability).
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function saveTeamSignature(int $workspaceId, int $mailboxId, string $actor, array $data): array
	{
		if ($mailboxId <= 0 || $workspaceId <= 0) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'id', $this->l10n->t('Choose a Team mailbox.'));
		}
		$row = null;
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('Mailbox not found.'));
		}
		$kind = (string)($row['kind'] ?? MailAclLogic::KIND_TEAM);
		if ($kind === MailAclLogic::KIND_PERSONAL) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'id',
				$this->l10n->t('Use My email to edit a personal signature.'),
			);
		}
		$html = $this->htmlSanitize->sanitizeSignature((string)($data['signatureHtml'] ?? $data['html'] ?? ''));
		$plain = trim((string)($data['signaturePlain'] ?? $data['plain'] ?? ''));
		if ($plain === '' && $html !== '') {
			$plain = $this->htmlSanitize->signatureHtmlToPlain($html);
		}
		if (strlen($html) > 65536 || strlen($plain) > 32768) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'signatureHtml',
				$this->l10n->t('Signature is too large (max 64 KiB HTML / 32 KiB plain).'),
			);
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$upd = $this->db->getQueryBuilder();
		$upd->update('crm_mailbox')
			->set('signature_html', $upd->createNamedParameter($html !== '' ? $html : null))
			->set('signature_plain', $upd->createNamedParameter($plain !== '' ? $plain : null))
			->set('signature_updated_at', $upd->createNamedParameter($now))
			->set('signature_updated_by', $upd->createNamedParameter($actor))
			->set('updated_at', $upd->createNamedParameter($now))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('workspace_id', $upd->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$boxes = $this->listMailboxes($workspaceId, MailAclLogic::KIND_TEAM);
		foreach ($boxes as $box) {
			if ((int)$box['id'] === $mailboxId) {
				return $box;
			}
		}

		throw new NotFoundException($this->l10n->t('Mailbox not found.'));
	}

	/**
	 * Remove a shared mailbox and its CRM index (folders, messages, drafts).
	 * Also drops open/snoozed shared-mail Assign rows that pointed at those messages
	 * so Assign cannot hit a missing mailMsgId after remove.
	 * Does not touch the remote IMAP account.
	 *
	 * Personal accounts: only when $allowPersonal (owner disconnect). Team admin delete refuses them.
	 */
	public function delete(int $workspaceId, int $mailboxId, bool $allowPersonal = false): void
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
		$check->select('id', 'kind')->from('crm_mailbox')
			->where($check->expr()->eq('id', $check->createNamedParameter($mailboxId)))
			->andWhere($check->expr()->eq('workspace_id', $check->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $check->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('Mailbox not found in this workspace.'));
		}
		$kind = strtolower(trim((string)($row['kind'] ?? MailAclLogic::KIND_TEAM)));
		if ($kind === MailAclLogic::KIND_PERSONAL && !$allowPersonal) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'id',
				$this->l10n->t('Personal mailboxes can only be disconnected by their owner under My email.'),
			);
		}

		$mailMsgIds = $this->listMessageIdsForMailbox($workspaceId, $mailboxId);
		$this->purgeOpenSharedMailAssignForMessages($workspaceId, $mailMsgIds);

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
	 * Drop non-terminal shared_mail Assign rows whose source_ref.mailMsgId was indexed
	 * from this mailbox — prevents Assign against deleted CRM mail rows.
	 *
	 * @param list<int> $mailMsgIds
	 */
	private function purgeOpenSharedMailAssignForMessages(int $workspaceId, array $mailMsgIds): void
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
			->andWhere($qb->expr()->in(
				'source_kind',
				$qb->createNamedParameter(
					['shared_mail', 'personal_mail_shared_unmatched'],
					\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY,
				),
			))
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
			$path = ImapConnection::normalizeFolderPath((string)$path);
			if ($path === '' || !ImapConnection::isSafeImapFolder($path)) {
				continue;
			}
			$ins = $this->db->getQueryBuilder();
			$ins->insert('crm_mail_fld')->values([
				'mailbox_id' => $ins->createNamedParameter($mailboxId),
				'folder_path' => $ins->createNamedParameter($path),
				'sync_enabled' => $ins->createNamedParameter(1),
				'share_with_workspace' => $ins->createNamedParameter(1),
				'created_at' => $ins->createNamedParameter($now),
			])->executeStatement();
		}
	}

	private function mailboxKind(int $mailboxId, int $workspaceId): ?string
	{
		if ($mailboxId <= 0 || !$this->db->tableExists('crm_mailbox')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('kind')->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return null;
		}

		return (string)($row['kind'] ?? MailAclLogic::KIND_TEAM);
	}

	/**
	 * Resolve mailbox owner uid (personal accounts) for remote mutate ACL.
	 */
	public function resolveMailboxOwnerUserId(int $workspaceId, int $mailboxId): ?string
	{
		if ($workspaceId <= 0 || $mailboxId <= 0 || !$this->db->tableExists('crm_mailbox')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner_user_id')->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return null;
		}
		$owner = trim((string)($row['owner_user_id'] ?? ''));

		return $owner !== '' ? $owner : null;
	}

	/**
	 * Close open Assign rows that point at this indexed mail (after inbox delete/spam).
	 * Soft-close (no_relation) — keeps audit trail unlike mailbox-disconnect purge.
	 *
	 * @return int number of Assign rows closed
	 */
	public function closeOpenAssignForMailMessage(
		int $workspaceId,
		int $mailMsgId,
		string $actor,
		string $note,
	): int {
		if ($workspaceId <= 0 || $mailMsgId <= 0 || !$this->db->tableExists('crm_review_item')) {
			return 0;
		}
		$actor = trim($actor);
		$note = mb_substr(trim($note), 0, 500);
		$ids = $this->findOpenAssignIdsForMailMessage($workspaceId, $mailMsgId);
		if ($ids === []) {
			return 0;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$closed = 0;
		foreach ($ids as $rid) {
			$upd = $this->db->getQueryBuilder();
			$closed += $upd->update('crm_review_item')
				->set('status', $upd->createNamedParameter('no_relation'))
				->set('resolution_note', $upd->createNamedParameter($note))
				->set('assigned_by', $upd->createNamedParameter($actor !== '' ? $actor : null))
				->set('assigned_at', $upd->createNamedParameter($now))
				->set('updated_at', $upd->createNamedParameter($now))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($rid, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('workspace_id', $upd->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->in(
					'status',
					$upd->createNamedParameter(['open', 'follow_up', 'snoozed'], IQueryBuilder::PARAM_STR_ARRAY),
				))
				->executeStatement();
		}

		return $closed;
	}

	/**
	 * Zeus SF-04: prefer indexed mail_msg_id; fall back to source_ref parse for pre-migration rows.
	 *
	 * @return list<int>
	 */
	private function findOpenAssignIdsForMailMessage(int $workspaceId, int $mailMsgId): array
	{
		$qb = $this->db->getQueryBuilder();
		if ($this->reviewHasMailMsgIdColumn()) {
			$qb->select('id')->from('crm_review_item')
				->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('mail_msg_id', $qb->createNamedParameter($mailMsgId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in(
					'source_kind',
					$qb->createNamedParameter(
						['shared_mail', 'personal_mail_shared_unmatched'],
						IQueryBuilder::PARAM_STR_ARRAY,
					),
				))
				->andWhere($qb->expr()->in(
					'status',
					$qb->createNamedParameter(['open', 'follow_up', 'snoozed'], IQueryBuilder::PARAM_STR_ARRAY),
				));
			$r = $qb->executeQuery();
			$ids = [];
			while ($row = $r->fetch()) {
				$ids[] = (int)$row['id'];
			}
			$r->closeCursor();

			return $ids;
		}

		// Pre-migration fallback (LIKE + exact JSON int match).
		$likeExact = '%"mailMsgId":' . $mailMsgId . '%';
		$qb->select('id', 'source_ref')->from('crm_review_item')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'source_kind',
				$qb->createNamedParameter(
					['shared_mail', 'personal_mail_shared_unmatched'],
					IQueryBuilder::PARAM_STR_ARRAY,
				),
			))
			->andWhere($qb->expr()->in(
				'status',
				$qb->createNamedParameter(['open', 'follow_up', 'snoozed'], IQueryBuilder::PARAM_STR_ARRAY),
			))
			->andWhere($qb->expr()->like('source_ref', $qb->createNamedParameter($likeExact)));
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$ref = json_decode((string)($row['source_ref'] ?? ''), true);
			$mid = is_array($ref) ? (int)($ref['mailMsgId'] ?? 0) : 0;
			if ($mid === $mailMsgId) {
				$ids[] = (int)$row['id'];
			}
		}
		$r->closeCursor();

		return $ids;
	}

	private function reviewHasMailMsgIdColumn(): bool
	{
		static $ready = null;
		if ($ready !== null) {
			return $ready;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('mail_msg_id')->from('crm_review_item')->setMaxResults(1);
			$r = $qb->executeQuery();
			$r->closeCursor();
			$ready = true;
		} catch (\Throwable) {
			$ready = false;
		}

		return $ready;
	}

	/**
	 * Resolve mailbox kind for ACL / mutate gates (defaults to team when unknown).
	 */
	public function resolveMailboxKind(int $workspaceId, int $mailboxId): string
	{
		$kind = strtolower(trim((string)($this->mailboxKind($mailboxId, $workspaceId) ?? MailAclLogic::KIND_TEAM)));
		return $kind === MailAclLogic::KIND_PERSONAL ? MailAclLogic::KIND_PERSONAL : MailAclLogic::KIND_TEAM;
	}

	/**
	 * @return array{kind: string, ownerUserId: ?string, shareWithWorkspace: bool}
	 */
	public function ingestMetaForFolder(int $mailboxId, int $folderId): array
	{
		$kind = MailAclLogic::KIND_TEAM;
		$owner = null;
		$share = true;
		if ($this->db->tableExists('crm_mailbox')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('kind', 'owner_user_id')->from('crm_mailbox')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
				->setMaxResults(1);
			$r = $qb->executeQuery();
			$row = $r->fetch();
			$r->closeCursor();
			if ($row !== false) {
				$kind = (string)($row['kind'] ?? MailAclLogic::KIND_TEAM);
				$owner = isset($row['owner_user_id']) && $row['owner_user_id'] !== null && $row['owner_user_id'] !== ''
					? (string)$row['owner_user_id']
					: null;
			}
		}
		if ($kind === MailAclLogic::KIND_PERSONAL && $this->db->tableExists('crm_mail_fld')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('share_with_workspace')->from('crm_mail_fld')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($folderId)))
				->andWhere($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
				->setMaxResults(1);
			$r = $qb->executeQuery();
			$row = $r->fetch();
			$r->closeCursor();
			$share = $row !== false && (int)($row['share_with_workspace'] ?? 0) === 1;
		}

		return [
			'kind' => $kind !== '' ? $kind : MailAclLogic::KIND_TEAM,
			'ownerUserId' => $owner,
			'shareWithWorkspace' => $share,
		];
	}

	public function disclosure(string $mailboxLabel, string $workspaceName, string $kind = MailAclLogic::KIND_TEAM): string
	{
		$kind = strtolower(trim($kind));
		if ($kind === MailAclLogic::KIND_PERSONAL) {
			return $this->l10n->t(
				'Sent from your personal email %1$s (connected in workspace %2$s).',
				[$mailboxLabel, $workspaceName]
			);
		}

		return $this->l10n->t(
			'These messages come from the shared mailbox %1$s. Everyone in workspace %2$s who can read mail can see them.',
			[$mailboxLabel, $workspaceName]
		);
	}

	/**
	 * Index a synced message + auto-match.
	 * From wins for inbound customer mail; otherwise To/CC can uniquely file a CC’d copy.
	 *
	 * @param list<array{companyId: int, email: string}> $persons
	 * @param list<string> $excludeEmails Shared-mailbox + workspace-member addresses
	 * @return array{companyId: ?int, how: string, inserted: bool, touched: bool}
	 */
	public function ingestMessage(
		int $workspaceId,
		int $mailboxId,
		int $folderId,
		array $msg,
		array $persons,
		array $excludeEmails = [],
	): array {
		$rawRecipients = [];
		if (isset($msg['to']) && is_array($msg['to'])) {
			foreach ($msg['to'] as $addr) {
				$rawRecipients[] = (string)$addr;
			}
		}
		if (isset($msg['cc']) && is_array($msg['cc'])) {
			foreach ($msg['cc'] as $addr) {
				$rawRecipients[] = (string)$addr;
			}
		}
		$fromStored = MailMatchLogic::normalizeFromAddress((string)($msg['from'] ?? ''));
		$recipients = MailMatchLogic::normalizeAddressList($rawRecipients);
		$meta = $this->ingestMetaForFolder($mailboxId, $folderId);
		$forwardHints = [];
		if ($meta['kind'] !== MailAclLogic::KIND_PERSONAL) {
			// Forward hints are Team Inbox / legacy only — never auto-file personal mail via Fwd: parsers.
			$forwardHints = MailMatchLogic::normalizeAddressList(
				isset($msg['originalFromHints']) && is_array($msg['originalFromHints'])
					? array_map('strval', $msg['originalFromHints'])
					: [],
			);
		}
		$allowDomain = $meta['kind'] !== MailAclLogic::KIND_PERSONAL;
		$match = MailMatchLogic::match(
			isset($msg['manualCompanyId']) ? (int)$msg['manualCompanyId'] : null,
			$fromStored,
			$persons,
			$excludeEmails,
			$recipients,
			$forwardHints,
			$allowDomain,
		);
		$visibility = MailAclLogic::visibilityForIngest($meta['kind'], $meta['shareWithWorkspace']);
		// Private personal mail may still match for the owner's Inbox UX, but never
		// appears on company Talk for teammates (visibility stays private).
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$preview = isset($msg['preview']) ? mb_substr((string)$msg['preview'], 0, Constants::MAIL_PREVIEW_MAX_BYTES) : null;
		$bodyText = null;
		if (isset($msg['bodyText']) && is_string($msg['bodyText']) && $msg['bodyText'] !== '') {
			$bodyText = mb_substr($msg['bodyText'], 0, Constants::MAIL_BODY_PLAIN_MAX_BYTES);
		} elseif ($preview !== null && $preview !== '') {
			$bodyText = mb_substr($preview, 0, Constants::MAIL_BODY_PLAIN_MAX_BYTES);
		}
		$bodyHtml = null;
		if (isset($msg['bodyHtml']) && is_string($msg['bodyHtml']) && $msg['bodyHtml'] !== '') {
			$sanitized = $this->htmlSanitize->sanitize($msg['bodyHtml']);
			$bodyHtml = $sanitized !== '' ? mb_substr($sanitized, 0, Constants::MAIL_BODY_HTML_MAX_BYTES) : null;
		}
		$rfcMessageId = SharedMailboxSmtpClient::normalizeMessageId((string)($msg['messageId'] ?? ''));
		// Dedupe CRM outbound vs IMAP Sent re-ingest (same RFC Message-ID).
		if ($rfcMessageId !== '') {
			$byMid = $this->findMessageIdByRfcId($workspaceId, $mailboxId, $rfcMessageId);
			if ($byMid !== null) {
				$touched = $this->mergeDuplicateByMessageId(
					$workspaceId,
					$byMid,
					(string)$msg['uid'],
					$folderId,
					$bodyText,
					$bodyHtml,
					$preview,
				);

				return array_merge($match, ['inserted' => false, 'touched' => $touched]);
			}
		}
		$subject = mb_substr(MimeHeader::decode((string)($msg['subject'] ?? '')), 0, 512);
		$snippetRaw = (string)($msg['snippet'] ?? '');
		$snippet = mb_substr(MimeHeader::decode($snippetRaw !== '' ? $snippetRaw : $subject), 0, 512);
		$toStore = $recipients !== [] ? $recipients : null;
		$ccStore = null;
		if (isset($msg['cc']) && is_array($msg['cc'])) {
			$ccNorm = MailMatchLogic::normalizeAddressList(array_map('strval', $msg['cc']));
			$ccStore = $ccNorm !== [] ? $ccNorm : null;
		}
		$forwardHintsJson = $forwardHints !== [] ? json_encode($forwardHints, JSON_THROW_ON_ERROR) : null;
		$ins = $this->db->getQueryBuilder();
		try {
			$values = [
				'mailbox_id' => $ins->createNamedParameter($mailboxId),
				'folder_id' => $ins->createNamedParameter($folderId),
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'company_id' => $ins->createNamedParameter($match['companyId']),
				'message_uid' => $ins->createNamedParameter((string)$msg['uid']),
				'message_id' => $ins->createNamedParameter($rfcMessageId !== '' ? $rfcMessageId : null),
				'direction' => $ins->createNamedParameter((string)($msg['direction'] ?? 'inbound')),
				'from_addr' => $ins->createNamedParameter($fromStored !== '' ? $fromStored : null),
				'to_addrs' => $ins->createNamedParameter($toStore !== null ? json_encode(array_values($toStore)) : null),
				'cc_addrs' => $ins->createNamedParameter($ccStore !== null ? json_encode(array_values($ccStore)) : null),
				'subject' => $ins->createNamedParameter($subject),
				'snippet' => $ins->createNamedParameter($snippet),
				'preview' => $ins->createNamedParameter($preview),
				'body_text' => $ins->createNamedParameter($bodyText),
				'body_html' => $ins->createNamedParameter($bodyHtml),
				'visibility' => $ins->createNamedParameter($visibility),
				'owner_user_id' => $ins->createNamedParameter($meta['ownerUserId']),
				'sent_at' => $ins->createNamedParameter($msg['sentAt'] ?? $now),
				'matched_how' => $ins->createNamedParameter($match['how']),
				'triage_status' => $ins->createNamedParameter($match['companyId'] ? 'matched' : 'open'),
				'created_at' => $ins->createNamedParameter($now),
			];
			if ($forwardHintsJson !== null) {
				$values['forward_from_hints'] = $ins->createNamedParameter($forwardHintsJson);
			}
			$ins->insert('crm_mail_msg')->values($values)->executeStatement();
		} catch (\Throwable) {
			// Unique (mailbox, folder, uid) — already indexed. Older rows often lack
			// To/CC (pre-recipient matching). Refresh open rows so Sync now can
			// file CC’d / team-sent copies onto customers and into Assign.
			// Also backfill empty body_text / body_html when sync now peeks bodies (MH-05).
			$refreshed = $this->refreshOpenIndexedMessage(
				$workspaceId,
				$mailboxId,
				$folderId,
				(string)$msg['uid'],
				$match,
				$toStore !== null ? array_values($toStore) : [],
				$fromStored,
				$forwardHints,
				$preview,
				$bodyText,
				$bodyHtml,
			);

			return array_merge($refreshed, ['inserted' => false]);
		}

		return array_merge($match, ['inserted' => true, 'touched' => true]);
	}

	/**
	 * After a unique-uid collision: backfill empty To/CC / bodies and apply a fresh match
	 * while triage is still open. Never touches matched / discarded / remote_deleted.
	 *
	 * @param array{companyId: ?int, how: string} $match
	 * @param list<string> $recipients
	 * @return array{companyId: ?int, how: string, touched: bool}
	 */
	private function refreshOpenIndexedMessage(
		int $workspaceId,
		int $mailboxId,
		int $folderId,
		string $uid,
		array $match,
		array $recipients,
		string $fromAddr,
		array $forwardHints = [],
		?string $preview = null,
		?string $bodyText = null,
		?string $bodyHtml = null,
	): array {
		if ($uid === '' || !$this->db->tableExists('crm_mail_msg')) {
			return array_merge($match, ['touched' => false]);
		}
		$fromAddr = MailMatchLogic::normalizeFromAddress($fromAddr);
		$recipients = MailMatchLogic::normalizeAddressList($recipients);
		$forwardHints = MailMatchLogic::normalizeAddressList($forwardHints);
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			'id',
			'to_addrs',
			'from_addr',
			'triage_status',
			'company_id',
			'forward_from_hints',
			'preview',
			'body_text',
			'body_html',
		)
			->from('crm_mail_msg')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('message_uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return array_merge($match, ['touched' => false]);
		}
		if ((string)($row['triage_status'] ?? '') !== 'open') {
			return array_merge($match, ['touched' => false]);
		}
		$mailId = (int)$row['id'];
		$existingTo = trim((string)($row['to_addrs'] ?? ''));
		$needsTo = $recipients !== [] && ($existingTo === '' || $existingTo === '[]' || $existingTo === 'null');
		$existingFrom = trim((string)($row['from_addr'] ?? ''));
		$needsFrom = $fromAddr !== '' && str_contains($fromAddr, '@') && ($existingFrom === '' || $existingFrom === '@');
		$existingHints = trim((string)($row['forward_from_hints'] ?? ''));
		$needsHints = $forwardHints !== [] && ($existingHints === '' || $existingHints === '[]' || $existingHints === 'null');
		$existingPreview = trim((string)($row['preview'] ?? ''));
		$previewStored = $preview !== null ? MailPreviewText::toPlainPreview($preview, Constants::MAIL_PREVIEW_MAX_BYTES) : '';
		$needsPreview = $previewStored !== '' && $existingPreview === '';
		$bodyPlainStored = $bodyText !== null && $bodyText !== ''
			? MailPreviewText::toPlainPreview($bodyText, Constants::MAIL_BODY_PLAIN_MAX_BYTES)
			: '';
		$needsBodyText = $bodyPlainStored !== '' && trim((string)($row['body_text'] ?? '')) === '';
		$bodyHtmlStored = '';
		if ($bodyHtml !== null && $bodyHtml !== '') {
			$san = $this->htmlSanitize->sanitize($bodyHtml);
			$bodyHtmlStored = $san !== '' ? mb_substr($san, 0, Constants::MAIL_BODY_HTML_MAX_BYTES) : '';
		}
		$needsBodyHtml = $bodyHtmlStored !== '' && trim((string)($row['body_html'] ?? '')) === '';

		if ($needsTo || $needsFrom || $needsHints || $needsPreview || $needsBodyText || $needsBodyHtml) {
			$upd = $this->db->getQueryBuilder();
			$upd->update('crm_mail_msg');
			if ($needsTo) {
				$upd->set('to_addrs', $upd->createNamedParameter(json_encode(array_values($recipients))));
			}
			if ($needsFrom) {
				$upd->set('from_addr', $upd->createNamedParameter($fromAddr));
			}
			if ($needsHints) {
				$upd->set(
					'forward_from_hints',
					$upd->createNamedParameter(json_encode(array_values($forwardHints), JSON_THROW_ON_ERROR)),
				);
			}
			if ($needsPreview) {
				$upd->set('preview', $upd->createNamedParameter($previewStored));
			}
			if ($needsBodyText) {
				$upd->set('body_text', $upd->createNamedParameter($bodyPlainStored));
				if (!$needsPreview && $existingPreview === '') {
					$upd->set(
						'preview',
						$upd->createNamedParameter(
							MailPreviewText::toPlainPreview($bodyPlainStored, Constants::MAIL_PREVIEW_MAX_BYTES),
						),
					);
				}
			}
			if ($needsBodyHtml) {
				$upd->set('body_html', $upd->createNamedParameter($bodyHtmlStored));
			}
			$upd->where($upd->expr()->eq('id', $upd->createNamedParameter($mailId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('workspace_id', $upd->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('triage_status', $upd->createNamedParameter('open')))
				->executeStatement();
		}

		$companyId = isset($match['companyId']) ? (int)$match['companyId'] : 0;
		$assigned = false;
		if ($companyId > 0) {
			try {
				$this->triageAssign($mailId, $workspaceId, $companyId, (string)($match['how'] ?? 'manual'));
				$assigned = true;
			} catch (\Throwable) {
				// Concurrent triage — leave open for Assign / next sync.
			}
		}

		return array_merge($match, [
			'touched' => $needsTo || $needsFrom || $needsHints || $needsPreview
				|| $needsBodyText || $needsBodyHtml || $assigned,
		]);
	}

	/**
	 * Find CRM row by RFC Message-ID within a mailbox (outbound↔Sent dedupe).
	 *
	 * @return array{id: int, message_uid: string, body_text: ?string, body_html: ?string, preview: ?string, folder_id: int}|null
	 */
	private function findMessageIdByRfcId(int $workspaceId, int $mailboxId, string $rfcMessageId): ?array
	{
		if ($workspaceId <= 0 || $mailboxId <= 0 || $rfcMessageId === '' || !$this->db->tableExists('crm_mail_msg')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'message_uid', 'body_text', 'body_html', 'preview', 'folder_id')
			->from('crm_mail_msg')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('message_id', $qb->createNamedParameter($rfcMessageId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if (!is_array($row)) {
			return null;
		}

		return [
			'id' => (int)$row['id'],
			'message_uid' => (string)($row['message_uid'] ?? ''),
			'body_text' => isset($row['body_text']) ? (string)$row['body_text'] : null,
			'body_html' => isset($row['body_html']) ? (string)$row['body_html'] : null,
			'preview' => isset($row['preview']) ? (string)$row['preview'] : null,
			'folder_id' => (int)($row['folder_id'] ?? 0),
		];
	}

	/**
	 * Merge IMAP re-ingest into existing CRM row that shares the RFC Message-ID.
	 * Prefer adopting real IMAP UID when the existing row is a synthetic outbound:* uid.
	 */
	private function mergeDuplicateByMessageId(
		int $workspaceId,
		array $existing,
		string $imapUid,
		int $folderId,
		?string $bodyText,
		?string $bodyHtml,
		?string $preview,
	): bool {
		$id = (int)($existing['id'] ?? 0);
		if ($id <= 0) {
			return false;
		}
		$upd = $this->db->getQueryBuilder();
		$upd->update('crm_mail_msg');
		$changed = false;
		$existingUid = (string)($existing['message_uid'] ?? '');
		if ($imapUid !== '' && (str_starts_with($existingUid, 'outbound:') || $existingUid === '')) {
			$upd->set('message_uid', $upd->createNamedParameter($imapUid));
			$changed = true;
		}
		if ($folderId > 0 && (int)($existing['folder_id'] ?? 0) !== $folderId && str_starts_with($existingUid, 'outbound:')) {
			$upd->set('folder_id', $upd->createNamedParameter($folderId, IQueryBuilder::PARAM_INT));
			$changed = true;
		}
		$plain = $bodyText !== null && $bodyText !== ''
			? MailPreviewText::toPlainPreview($bodyText, Constants::MAIL_BODY_PLAIN_MAX_BYTES)
			: '';
		if ($plain !== '' && trim((string)($existing['body_text'] ?? '')) === '') {
			$upd->set('body_text', $upd->createNamedParameter($plain));
			$changed = true;
		}
		$html = '';
		if ($bodyHtml !== null && $bodyHtml !== '') {
			$html = mb_substr($this->htmlSanitize->sanitize($bodyHtml), 0, Constants::MAIL_BODY_HTML_MAX_BYTES);
		}
		if ($html !== '' && trim((string)($existing['body_html'] ?? '')) === '') {
			$upd->set('body_html', $upd->createNamedParameter($html));
			$changed = true;
		}
		$previewStored = $preview !== null
			? MailPreviewText::toPlainPreview($preview, Constants::MAIL_PREVIEW_MAX_BYTES)
			: '';
		if ($previewStored !== '' && trim((string)($existing['preview'] ?? '')) === '') {
			$upd->set('preview', $upd->createNamedParameter($previewStored));
			$changed = true;
		}
		if (!$changed) {
			return false;
		}
		$upd->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('workspace_id', $upd->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		return true;
	}

	public function recordAuthFailure(int $mailboxId, int $currentFails): string
	{
		// Atomic increment — concurrent dials must not skip/under-count the quarantine threshold.
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('auth_fail_count', $qb->createFunction('auth_fail_count + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->executeStatement();

		$next = $this->readAuthFailCount($mailboxId);
		if ($next < 0) {
			$next = $currentFails + 1;
		}
		$status = MailMatchLogic::nextAuthStatus($next);
		$qb2 = $this->db->getQueryBuilder();
		$qb2->update('crm_mailbox')
			->set('status', $qb2->createNamedParameter($status))
			->set('last_error', $qb2->createNamedParameter($status === 'error' ? 'IMAP auth failed ×3' : 'IMAP auth failed'))
			->where($qb2->expr()->eq('id', $qb2->createNamedParameter($mailboxId)))
			->executeStatement();
		// Notify exactly once when the atomic counter first hits quarantine (=== 3).
		if ($status === 'error' && $next === 3) {
			$this->notifyMailboxAuthQuarantine($mailboxId);
		}
		return $status;
	}

	private function readAuthFailCount(int $mailboxId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('auth_fail_count')
			->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if (!is_array($row)) {
			return -1;
		}

		return (int)($row['auth_fail_count'] ?? 0);
	}

	/**
	 * Personal mailbox quarantine → owner only; team mailbox → workspace fan-out.
	 */
	private function notifyMailboxAuthQuarantine(int $mailboxId): void
	{
		if ($mailboxId <= 0 || $this->notifications === null || !$this->db->tableExists('crm_mailbox')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'workspace_id', 'label', 'kind', 'owner_user_id')
			->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if (!is_array($row)) {
			return;
		}
		$wsId = (int)($row['workspace_id'] ?? 0);
		if ($wsId <= 0) {
			return;
		}
		$params = ['label' => (string)($row['label'] ?? '')];
		$kind = strtolower(trim((string)($row['kind'] ?? MailAclLogic::KIND_TEAM)));
		$owner = trim((string)($row['owner_user_id'] ?? ''));
		if ($kind === MailAclLogic::KIND_PERSONAL && $owner !== '') {
			$this->notifications->notifyUser(
				$owner,
				Constants::NOTIFY_DIGEST,
				'mailbox_error',
				(string)$mailboxId,
				$params,
			);
			return;
		}
		$this->notifications->notifyWorkspaceUsers(
			$wsId,
			Constants::NOTIFY_DIGEST,
			'mailbox_error',
			(string)$mailboxId,
			$params,
		);
	}

	/**
	 * Cron dial backoff after transient sync failure (Aristoteles SF-02).
	 * Does not quarantine the mailbox — only delays the next automatic dial.
	 */
	public function recordSyncDialFailure(int $mailboxId, int $currentStreak, string $reason): void
	{
		if ($mailboxId <= 0 || !$this->db->tableExists('crm_mailbox')) {
			return;
		}
		$streak = max(0, $currentStreak) + 1;
		$exp = min(Constants::MAIL_SYNC_DIAL_BACKOFF_EXP_CAP, max(0, $streak - 1));
		$seconds = (int)min(
			Constants::MAIL_SYNC_DIAL_BACKOFF_MAX_SECONDS,
			Constants::MAIL_SYNC_DIAL_BACKOFF_BASE_SECONDS * (2 ** $exp),
		);
		$until = (clone $this->timeFactory->getDateTime())
			->modify('+' . $seconds . ' seconds')
			->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->update('crm_mailbox')
				->set('sync_fail_streak', $qb->createNamedParameter($streak))
				->set('sync_cooldown_until', $qb->createNamedParameter($until))
				->set('last_error', $qb->createNamedParameter(mb_substr($reason !== '' ? $reason : 'sync failed', 0, 512)))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
				->executeStatement();
		} catch (\Throwable) {
			// Columns absent mid-upgrade — skip backoff until migration lands.
		}
	}

	/** Clear dial backoff after a successful sync (or credential save). */
	public function clearSyncDialFailure(int $mailboxId): void
	{
		if ($mailboxId <= 0 || !$this->db->tableExists('crm_mailbox')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->update('crm_mailbox')
				->set('sync_fail_streak', $qb->createNamedParameter(0))
				->set('sync_cooldown_until', $qb->createNamedParameter(null))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
				->executeStatement();
		} catch (\Throwable) {
			// Mid-upgrade: ignore.
		}
	}

	/**
	 * @param array<string, mixed> $row crm_mailbox row (may omit cooldown columns mid-upgrade)
	 */
	public function isSyncDialCooldownActive(array $row, ?\DateTimeInterface $now = null): bool
	{
		if (!array_key_exists('sync_cooldown_until', $row)) {
			return false;
		}
		$raw = $row['sync_cooldown_until'];
		if ($raw === null || $raw === '') {
			return false;
		}
		$ts = strtotime((string)$raw);
		if ($ts === false) {
			return false;
		}
		$nowTs = $now !== null ? $now->getTimestamp() : $this->timeFactory->getDateTime()->getTimestamp();

		return $ts > $nowTs;
	}

	/**
	 * Open unmatched CRM mail rows (newest sent first).
	 *
	 * @return list<array{id: int, subject: string, from: string, sentAt: mixed, shared: true}>
	 */
	public function unmatched(int $workspaceId, int $limit = 50): array
	{
		return $this->unmatchedPage($workspaceId, 0, $limit, true);
	}

	/**
	 * Page open unmatched mail for Assign promotion.
	 * Default order is id ASC (stable drain). Pass $newestFirst for UI-style newest sent.
	 *
	 * @return list<array{id: int, subject: string, from: string, sentAt: mixed, shared: true}>
	 */
	public function unmatchedPage(int $workspaceId, int $afterId, int $limit = 200, bool $newestFirst = false): array
	{
		if ($workspaceId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return [];
		}
		$limit = max(1, min(500, $limit));
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.*', 'b.kind AS mailbox_kind')
			->from('crm_mail_msg', 'm')
			->leftJoin('m', 'crm_mailbox', 'b', $qb->expr()->eq('m.mailbox_id', 'b.id'))
			->where($qb->expr()->eq('m.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.triage_status', $qb->createNamedParameter('open')))
			// Outbound CRM-indexed sends are not Assign fodder (synthetic outbound: uids break IMAP delete).
			->andWhere($qb->expr()->neq('m.direction', $qb->createNamedParameter('outbound')))
			->andWhere($qb->expr()->notLike('m.message_uid', $qb->createNamedParameter('outbound:%')));
		// AS-12: never promote private personal mail into Assign / unmatched UI.
		// Legacy rows without visibility column value are treated as shared (Team Inbox era).
		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->eq('m.visibility', $qb->createNamedParameter(MailAclLogic::VISIBILITY_SHARED)),
			$qb->expr()->isNull('m.visibility'),
			$qb->expr()->eq('m.visibility', $qb->createNamedParameter('')),
		));
		if ($afterId > 0) {
			$qb->andWhere($qb->expr()->gt('m.id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
		}
		if ($newestFirst) {
			$qb->orderBy('m.sent_at', 'DESC')->addOrderBy('m.id', 'DESC');
		} else {
			$qb->orderBy('m.id', 'ASC');
		}
		$qb->setMaxResults($limit);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$kind = strtolower(trim((string)($row['mailbox_kind'] ?? MailAclLogic::KIND_TEAM)));
			if ($kind === '') {
				$kind = MailAclLogic::KIND_TEAM;
			}
			$out[] = [
				'id' => (int)$row['id'],
				'subject' => MimeHeader::decode((string)($row['subject'] ?? '')),
				'from' => (string)($row['from_addr'] ?? ''),
				'preview' => (string)($row['preview'] ?? ''),
				'forwardFromHints' => (string)($row['forward_from_hints'] ?? ''),
				'sentAt' => $row['sent_at'] ?? null,
				'shared' => true,
				'visibility' => MailAclLogic::VISIBILITY_SHARED,
				'mailboxKind' => $kind,
			];
		}
		$r->closeCursor();
		return $out;
	}

	public function triageAssign(int $messageId, int $workspaceId, int $companyId, string $matchedHow = 'manual'): void
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
		$allowedHow = [
			'manual', 'person_email', 'domain', 'recipient_email', 'recipient_domain',
			'forward_from', 'forward_domain',
		];
		if (!in_array($matchedHow, $allowedHow, true)) {
			$matchedHow = 'manual';
		}
		// Cross-book guard: never attach shared mail to a foreign workspace company.
		$check = $this->db->getQueryBuilder();
		$check->select('id')->from('crm_company')
			->where($check->expr()->eq('id', $check->createNamedParameter($companyId)))
			->andWhere($check->expr()->eq('workspace_id', $check->createNamedParameter($workspaceId)))
			->andWhere($check->expr()->isNull('archived_at'))
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
			->set('matched_how', $qb->createNamedParameter($matchedHow))
			->set('triage_status', $qb->createNamedParameter('matched'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			// Only open triage rows — never silently rematch an already-matched message.
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('open')))
			// Never triage private personal mail (privacy escalation if later shared).
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('visibility', $qb->createNamedParameter(MailAclLogic::VISIBILITY_SHARED)),
				$qb->expr()->isNull('visibility'),
				$qb->expr()->eq('visibility', $qb->createNamedParameter('')),
			))
			->executeStatement();
		if ($affected < 1) {
			// Concurrent assign/discard race — fail closed with a clear reload cue.
			throw new NotFoundException($this->l10n->t('Someone else already handled this message. Reload Assign and try again.'));
		}
	}

	/**
	 * Re-open one matched message so Assign / rematch can file it on an active company.
	 * Used when mail was filed on an archived company or a stale match must be retried.
	 */
	public function triageReopenMatched(int $messageId, int $workspaceId): bool
	{
		if ($messageId <= 0 || $workspaceId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_mail_msg')
			->set('triage_status', $qb->createNamedParameter('open'))
			->set('company_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('matched_how', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('matched')))
			->executeStatement();

		return $affected > 0;
	}

	/**
	 * Re-open matched shared-mail rows for a company sender so they can be
	 * triaged again in Assign (assign to another company). Does not delete IMAP.
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
	 * Drop an unmatched shared-mail copy from CRM triage after Assign discard.
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
		$qb->select('m.*', 'f.folder_path', 'b.kind AS mailbox_kind')
			->from('crm_mail_msg', 'm')
			->leftJoin('m', 'crm_mail_fld', 'f', $qb->expr()->eq('m.folder_id', 'f.id'))
			->leftJoin('m', 'crm_mailbox', 'b', $qb->expr()->eq('m.mailbox_id', 'b.id'))
			->where($qb->expr()->eq('m.id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return null;
		}

		$mailboxKind = strtolower(trim((string)($row['mailbox_kind'] ?? MailAclLogic::KIND_TEAM)));
		if ($mailboxKind === '') {
			$mailboxKind = MailAclLogic::KIND_TEAM;
		}

			return [
			'id' => (int)$row['id'],
			'mailboxId' => (int)$row['mailbox_id'],
			'folderId' => (int)$row['folder_id'],
			'folderPath' => trim((string)($row['folder_path'] ?? '')) !== '' ? (string)$row['folder_path'] : 'INBOX',
			'messageUid' => (string)($row['message_uid'] ?? ''),
			'subject' => MimeHeader::decode((string)($row['subject'] ?? '')),
			'from' => (string)($row['from_addr'] ?? ''),
			'preview' => (string)(($row['body_text'] ?? '') !== '' && trim((string)$row['body_text']) !== ''
				? $row['body_text']
				: ($row['preview'] ?? '')),
			// FAC-16 / NN-10: re-sanitize on every read — never trust stored HTML alone.
			'bodyHtml' => $this->htmlSanitize->sanitize(trim((string)($row['body_html'] ?? ''))),
			'triageStatus' => (string)($row['triage_status'] ?? ''),
			'visibility' => MailAclLogic::normalizeVisibility((string)($row['visibility'] ?? 'shared')),
			'ownerUserId' => $row['owner_user_id'] ?? null,
			'companyId' => isset($row['company_id']) && $row['company_id'] !== null ? (int)$row['company_id'] : null,
			'mailboxKind' => $mailboxKind === MailAclLogic::KIND_PERSONAL
				? MailAclLogic::KIND_PERSONAL
				: MailAclLogic::KIND_TEAM,
		];
	}

	/**
	 * Cache a plain-text preview on the indexed message (best-effort).
	 * Also backfills body_text when empty so Talk/Inbox can show full body after open (MH-05).
	 */
	public function cacheMessagePreview(int $workspaceId, int $messageId, string $preview): void
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return;
		}
		// Byte-cap + HTML strip — never store more than MAIL_PREVIEW_MAX_BYTES on disk for preview.
		$preview = MailPreviewText::toPlainPreview($preview, Constants::MAIL_PREVIEW_MAX_BYTES);
		if ($preview === '') {
			return;
		}
		$bodyPlain = MailPreviewText::toPlainPreview($preview, Constants::MAIL_BODY_PLAIN_MAX_BYTES);
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mail_msg')
			->set('preview', $qb->createNamedParameter($preview))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		// Only fill body_text when missing — never clobber a richer sync-ingested body with a shorter peek.
		$sel = $this->db->getQueryBuilder();
		$sel->select('body_text')->from('crm_mail_msg')
			->where($sel->expr()->eq('id', $sel->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($sel->expr()->eq('workspace_id', $sel->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $sel->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		$existingBody = is_array($row) ? trim((string)($row['body_text'] ?? '')) : '';
		if ($existingBody === '' && $bodyPlain !== '') {
			$qb->set('body_text', $qb->createNamedParameter($bodyPlain));
		}
		$qb->executeStatement();
	}

	/**
	 * Persist full bodies on an open indexed row when still empty (sync refresh / MH-05).
	 */
	public function cacheMessageBodies(int $workspaceId, int $messageId, string $bodyText, string $bodyHtml = ''): void
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return;
		}
		$plain = MailPreviewText::toPlainPreview($bodyText, Constants::MAIL_BODY_PLAIN_MAX_BYTES);
		$html = '';
		if ($bodyHtml !== '') {
			$html = mb_substr($this->htmlSanitize->sanitize($bodyHtml), 0, Constants::MAIL_BODY_HTML_MAX_BYTES);
		}
		if ($plain === '' && $html === '') {
			return;
		}
		$sel = $this->db->getQueryBuilder();
		$sel->select('body_text', 'body_html', 'preview')->from('crm_mail_msg')
			->where($sel->expr()->eq('id', $sel->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($sel->expr()->eq('workspace_id', $sel->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $sel->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if (!is_array($row)) {
			return;
		}
		$upd = $this->db->getQueryBuilder();
		$upd->update('crm_mail_msg');
		$changed = false;
		if ($plain !== '' && trim((string)($row['body_text'] ?? '')) === '') {
			$upd->set('body_text', $upd->createNamedParameter($plain));
			$changed = true;
			if (trim((string)($row['preview'] ?? '')) === '') {
				$upd->set(
					'preview',
					$upd->createNamedParameter(MailPreviewText::toPlainPreview($plain, Constants::MAIL_PREVIEW_MAX_BYTES)),
				);
			}
		}
		if ($html !== '' && trim((string)($row['body_html'] ?? '')) === '') {
			$upd->set('body_html', $upd->createNamedParameter($html));
			$changed = true;
		}
		if (!$changed) {
			return;
		}
		$upd->where($upd->expr()->eq('id', $upd->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('workspace_id', $upd->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Persist forward hints extracted from an already-stored preview (no IMAP).
	 * Runs before IMAP repair so reopened team forwards auto-file when body text exists.
	 *
	 * @param list<string> $excludeEmails Shared-mailbox + workspace-member addresses
	 * @return int number of rows patched
	 */
	public function backfillForwardHintsFromPreview(int $workspaceId, array $excludeEmails, int $maxRows = Constants::MAIL_SYNC_HINT_BACKFILL_CAP): int
	{
		if ($workspaceId <= 0 || $maxRows <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return 0;
		}
		$patched = 0;
		$afterId = 0;
		$pageSize = min(200, $maxRows);
		do {
			if ($patched >= $maxRows) {
				break;
			}
			$qb = $this->db->getQueryBuilder();
			$expr = $qb->expr();
			$qb->select('id', 'preview', 'forward_from_hints')
				->from('crm_mail_msg')
				->where($expr->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($expr->eq('triage_status', $qb->createNamedParameter('open')))
				->andWhere($expr->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->andWhere($expr->orX(
					$expr->isNull('forward_from_hints'),
					$expr->eq('forward_from_hints', $qb->createNamedParameter('')),
					$expr->eq('forward_from_hints', $qb->createNamedParameter('[]')),
				))
				->andWhere($expr->isNotNull('preview'))
				->andWhere($expr->neq('preview', $qb->createNamedParameter('')))
				->orderBy('id', 'ASC')
				->setMaxResults(min($pageSize, $maxRows - $patched));
			$r = $qb->executeQuery();
			$rows = [];
			while ($row = $r->fetch()) {
				$rows[] = $row;
			}
			$r->closeCursor();
			if ($rows === []) {
				break;
			}
			foreach ($rows as $row) {
				$afterId = max($afterId, (int)$row['id']);
				if ($patched >= $maxRows) {
					break 2;
				}
				$preview = trim((string)($row['preview'] ?? ''));
				if ($preview === '') {
					continue;
				}
				$hints = MailForwardLogic::hintsFromBodyPrefix($preview, $excludeEmails);
				if ($hints === []) {
					continue;
				}
				if ($this->patchOpenMailForRematch($workspaceId, (int)$row['id'], null, null, $hints, null)) {
					$patched++;
				}
			}
		} while (count($rows) === $pageSize);

		return $patched;
	}

	/**
	 * Open shared-mail rows that may need IMAP header/body backfill before rematch.
	 *
	 * @return list<array{
	 *   id: int,
	 *   mailboxId: int,
	 *   folderId: int,
	 *   folderPath: string,
	 *   messageUid: string,
	 *   from: string,
	 *   subject: string,
	 *   toAddrs: string,
	 *   preview: string,
	 *   forwardFromHints: string
	 * }>
	 */
	public function listOpenMailForRepair(int $workspaceId, int $afterId, int $limit): array
	{
		if ($workspaceId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return [];
		}
		$limit = max(1, min(Constants::MAIL_FORWARD_BODY_PROBE_CAP, $limit));
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$emptyTo = $expr->orX(
			$expr->isNull('m.to_addrs'),
			$expr->eq('m.to_addrs', $qb->createNamedParameter('')),
			$expr->eq('m.to_addrs', $qb->createNamedParameter('[]')),
		);
		$emptyHints = $expr->orX(
			$expr->isNull('m.forward_from_hints'),
			$expr->eq('m.forward_from_hints', $qb->createNamedParameter('')),
			$expr->eq('m.forward_from_hints', $qb->createNamedParameter('[]')),
		);
		$emptyPreview = $expr->orX(
			$expr->isNull('m.preview'),
			$expr->eq('m.preview', $qb->createNamedParameter('')),
		);
		$qb->select(
			'm.id',
			'm.mailbox_id',
			'm.folder_id',
			'm.message_uid',
			'm.from_addr',
			'm.subject',
			'm.to_addrs',
			'm.preview',
			'm.forward_from_hints',
			'f.folder_path',
		)
			->from('crm_mail_msg', 'm')
			->leftJoin('m', 'crm_mail_fld', 'f', $expr->eq('m.folder_id', 'f.id'))
			->where($expr->eq('m.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('m.triage_status', $qb->createNamedParameter('open')))
			->andWhere($expr->gt('m.id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->orX($emptyTo, $emptyHints))
			->orderBy('m.id', 'ASC')
			->setMaxResults($limit);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'mailboxId' => (int)$row['mailbox_id'],
				'folderId' => (int)$row['folder_id'],
				'folderPath' => trim((string)($row['folder_path'] ?? '')) !== '' ? (string)$row['folder_path'] : 'INBOX',
				'messageUid' => (string)($row['message_uid'] ?? ''),
				'from' => (string)($row['from_addr'] ?? ''),
				'subject' => (string)($row['subject'] ?? ''),
				'toAddrs' => (string)($row['to_addrs'] ?? ''),
				'preview' => (string)($row['preview'] ?? ''),
				'forwardFromHints' => (string)($row['forward_from_hints'] ?? ''),
			];
		}
		$r->closeCursor();

		return $out;
	}

	/**
	 * Patch empty match fields on an open indexed message (never overwrites non-empty).
	 *
	 * @param list<string>|null $recipients
	 * @param list<string>|null $forwardHints
	 */
	public function patchOpenMailForRematch(
		int $workspaceId,
		int $mailId,
		?array $recipients = null,
		?string $fromAddr = null,
		?array $forwardHints = null,
		?string $preview = null,
	): bool {
		if ($workspaceId <= 0 || $mailId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('to_addrs', 'from_addr', 'forward_from_hints', 'preview')
			->from('crm_mail_msg')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('triage_status', $qb->createNamedParameter('open')))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return false;
		}

		$recipients = $recipients !== null ? MailMatchLogic::normalizeAddressList($recipients) : null;
		$fromAddr = $fromAddr !== null ? MailMatchLogic::normalizeFromAddress($fromAddr) : null;
		$forwardHints = $forwardHints !== null ? MailMatchLogic::normalizeAddressList($forwardHints) : null;
		$previewStored = $preview !== null ? MailPreviewText::toPlainPreview($preview, Constants::MAIL_PREVIEW_MAX_BYTES) : '';

		$existingTo = trim((string)($row['to_addrs'] ?? ''));
		$needsTo = $recipients !== null && $recipients !== []
			&& ($existingTo === '' || $existingTo === '[]' || $existingTo === 'null');
		$existingFrom = trim((string)($row['from_addr'] ?? ''));
		$needsFrom = $fromAddr !== null && $fromAddr !== '' && str_contains($fromAddr, '@')
			&& ($existingFrom === '' || $existingFrom === '@');
		$existingHints = trim((string)($row['forward_from_hints'] ?? ''));
		$needsHints = $forwardHints !== null && $forwardHints !== []
			&& ($existingHints === '' || $existingHints === '[]' || $existingHints === 'null');
		$existingPreview = trim((string)($row['preview'] ?? ''));
		$needsPreview = $previewStored !== '' && $existingPreview === '';

		if (!$needsTo && !$needsFrom && !$needsHints && !$needsPreview) {
			return false;
		}

		$upd = $this->db->getQueryBuilder();
		$upd->update('crm_mail_msg');
		if ($needsTo) {
			$upd->set('to_addrs', $upd->createNamedParameter(json_encode(array_values($recipients))));
		}
		if ($needsFrom) {
			$upd->set('from_addr', $upd->createNamedParameter($fromAddr));
		}
		if ($needsHints) {
			$upd->set(
				'forward_from_hints',
				$upd->createNamedParameter(json_encode(array_values($forwardHints), JSON_THROW_ON_ERROR)),
			);
		}
		if ($needsPreview) {
			$upd->set('preview', $upd->createNamedParameter($previewStored));
		}
		$upd->where($upd->expr()->eq('id', $upd->createNamedParameter($mailId, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('workspace_id', $upd->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($upd->expr()->eq('triage_status', $upd->createNamedParameter('open')))
			->executeStatement();

		return true;
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
			$body = trim((string)($row['body_text'] ?? ''));
			$out[] = [
				'id' => (int)$row['id'],
				'subject' => MimeHeader::decode((string)($row['subject'] ?? '')),
				'snippet' => MimeHeader::decode((string)($row['snippet'] ?? '')),
				'from' => (string)($row['from_addr'] ?? ''),
				'hasPreview' => $preview !== '' || $body !== '',
				'direction' => (string)($row['direction'] ?? 'inbound'),
				'sentAt' => $row['sent_at'] ?? null,
				'mailboxLabel' => (string)($row['mailbox_label'] ?? 'shared'),
				'messageId' => $row['message_id'] ?? null,
				'shared' => MailAclLogic::normalizeVisibility((string)($row['visibility'] ?? 'shared')) === MailAclLogic::VISIBILITY_SHARED,
				'visibility' => MailAclLogic::normalizeVisibility((string)($row['visibility'] ?? 'shared')),
				'ownerUserId' => $row['owner_user_id'] ?? null,
				'companyId' => isset($row['company_id']) ? (int)$row['company_id'] : null,
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
