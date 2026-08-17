<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\ImapConnection;
use OCA\CustomerCheck\Support\ImapUtf7;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Security\ICrypto;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Personal mailbox connect / folders / share / disconnect — CUC-PERSONAL-MAIL-1.
 */
class PersonalMailService
{
	public const ERR_PERSONAL_MAIL_DISABLED = 'PERSONAL_MAIL_DISABLED';
	public const ERR_MAIL_SHARE_ACK = 'MAIL_SHARE_ACK_REQUIRED';
	public const ERR_MAIL_SHARE_EXISTING = 'MAIL_SHARE_EXISTING_ACK_REQUIRED';
	public const ERR_MAIL_ACCOUNT_CONFLICT = 'MAIL_ACCOUNT_CONFLICT';
	public const ERR_MAIL_NOT_FOUND = 'MAIL_NOT_FOUND';
	public const ERR_MAIL_SIGNATURE_TOO_LARGE = 'MAIL_SIGNATURE_TOO_LARGE';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ICrypto $crypto,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly WorkspaceService $workspaces,
		private readonly MailboxSyncService $mailboxSync,
		private readonly HtmlSanitizeService $htmlSanitize,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locks,
		private readonly RateLimitService $rateLimit,
	) {
	}

	public function assertPersonalMailEnabled(int $workspaceId): void
	{
		$settings = $this->workspaces->getSettings($workspaceId);
		if (empty($settings['personalMailEnabled'])) {
			throw ValidationException::field(
				self::ERR_PERSONAL_MAIL_DISABLED,
				'personalMail',
				$this->l10n->t('Personal email sync is not enabled for this workspace. Ask a manager to turn it on under Team & mailbox settings.'),
			);
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listMine(int $workspaceId, string $userId): array
	{
		if ($workspaceId <= 0 || trim($userId) === '' || !$this->db->tableExists('crm_mailbox')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('crm_mailbox')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(MailAclLogic::KIND_PERSONAL)))
			->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = $this->serializeAccount($row);
		}
		$r->closeCursor();

		return $out;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function connectImap(int $workspaceId, string $userId, array $data): array
	{
		$this->assertPersonalMailEnabled($workspaceId);
		if (!$this->rateLimit->allow(
			'pm_imap_connect:' . $userId,
			Constants::PERSONAL_MAIL_IMAP_CONNECT_USER_LIMIT,
			Constants::PERSONAL_MAIL_IMAP_CONNECT_USER_WINDOW,
			true,
		)) {
			throw ValidationException::field(
				Constants::ERR_RATE_LIMITED,
				'rate',
				$this->l10n->t('Too many connect attempts. Try again later.'),
			);
		}
		if (empty($data['dsgvoAck'])) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'dsgvoAck',
				$this->l10n->t('Confirm that CustomerCheck may sync mail from your personal mailbox into this workspace.'),
			);
		}

		$emailAddress = strtolower(trim((string)($data['emailAddress'] ?? $data['email'] ?? '')));
		$secret = (string)($data['password'] ?? '');
		$id = isset($data['id']) ? (int)$data['id'] : 0;
		$existing = $this->listMine($workspaceId, $userId);
		// US-002 ok⇄error: same-email reconnect heals in place (mirror OAuth heal) — no Disconnect wipe.
		if ($id <= 0 && $emailAddress !== '') {
			foreach ($existing as $box) {
				$boxEmail = strtolower(trim((string)($box['emailAddress'] ?? '')));
				if ($boxEmail === $emailAddress) {
					$id = (int)($box['id'] ?? 0);
					break;
				}
			}
		}
		if (count($existing) >= Constants::PERSONAL_MAIL_MAX_PER_USER && $id <= 0) {
			throw ValidationException::field(
				Constants::ERR_MAILBOX_LIMIT,
				'mailbox',
				$this->l10n->t('You already connected the maximum number of personal mailboxes for this workspace.'),
			);
		}
		if ($secret === '' && $id <= 0) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'password', $this->l10n->t('Enter the mailbox password.'));
		}

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
		$imapEnc = ImapConnection::resolveImapEnc(isset($data['imapEnc']) ? (string)$data['imapEnc'] : null, $imapPort);
		$smtpEnc = ImapConnection::resolveSmtpEnc(isset($data['smtpEnc']) ? (string)$data['smtpEnc'] : null, $smtpPort);
		$label = trim((string)($data['label'] ?? ''));
		if ($label === '') {
			$label = $emailAddress !== '' ? $emailAddress : $this->l10n->t('My email');
		}
		if ($imapHost === '' || $smtpHost === '' || $username === '' || $emailAddress === '' || !str_contains($emailAddress, '@')) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'emailAddress',
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

		// Heal may only refresh credentials for the same address — never rebind id→new email
		// (that would keep the old message corpus and skip Disconnect erase).
		if ($id > 0) {
			$this->assertOwnedPersonalEmailUnchanged($workspaceId, $id, $userId, $emailAddress);
		}
		$this->assertEmailAvailable($workspaceId, $emailAddress, $id > 0 ? $id : null);

		$test = $this->mailboxSync->testConnection([
			'imapHost' => $imapHost,
			'imapPort' => $imapPort,
			'imapEnc' => $imapEnc,
			'username' => $username,
			'password' => $secret !== '' ? $secret : $this->loadSecretForOwner($workspaceId, $id, $userId),
			'workspaceId' => $workspaceId,
		]);
		if (empty($test['ok'])) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'password',
				(string)($test['message'] ?? $this->l10n->t('Could not connect to the mailbox. Check host and password.')),
			);
		}

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$enc = $secret !== '' ? $this->crypto->encrypt($secret) : null;

		return $this->withEmailConnectLock($workspaceId, $emailAddress, function () use (
			$workspaceId,
			$userId,
			$emailAddress,
			$id,
			$label,
			$imapHost,
			$imapPort,
			$imapEnc,
			$smtpHost,
			$smtpPort,
			$smtpEnc,
			$username,
			$enc,
			$now,
			$test,
		) {
			// Re-check under lock — peer connect may have won the race during IMAP test.
			$this->assertEmailAvailable($workspaceId, $emailAddress, $id > 0 ? $id : null);

			$connectEvent = 'personal_mail.connected';
			if ($id > 0) {
				$this->assertOwnedPersonalEmailUnchanged($workspaceId, $id, $userId, $emailAddress);
				$qb = $this->db->getQueryBuilder();
				$qb->update('crm_mailbox')
					->set('label', $qb->createNamedParameter(mb_substr($label, 0, 128)))
					// email_address intentionally unchanged (heal = same address only)
					->set('imap_host', $qb->createNamedParameter($imapHost))
					->set('imap_port', $qb->createNamedParameter($imapPort))
					->set('imap_enc', $qb->createNamedParameter($imapEnc))
					->set('smtp_host', $qb->createNamedParameter($smtpHost))
					->set('smtp_port', $qb->createNamedParameter($smtpPort))
					->set('smtp_enc', $qb->createNamedParameter($smtpEnc))
					->set('username', $qb->createNamedParameter($username))
					->set('auth_kind', $qb->createNamedParameter('imap'))
					->set('kind', $qb->createNamedParameter(MailAclLogic::KIND_PERSONAL))
					->set('owner_user_id', $qb->createNamedParameter($userId))
					->set('token_enc', $qb->createNamedParameter(null))
					->set('refresh_enc', $qb->createNamedParameter(null))
					->set('token_expires_at', $qb->createNamedParameter(null))
					->set('dsgvo_ack_at', $qb->createNamedParameter($now))
					->set('dsgvo_ack_by', $qb->createNamedParameter($userId))
					->set('status', $qb->createNamedParameter('ok'))
					->set('auth_fail_count', $qb->createNamedParameter(0))
					->set('last_error', $qb->createNamedParameter(null))
					->set('updated_at', $qb->createNamedParameter($now));
				if ($enc !== null) {
					$qb->set('secret_enc', $qb->createNamedParameter($enc));
				}
				$qb->set('sync_fail_streak', $qb->createNamedParameter(0))
					->set('sync_cooldown_until', $qb->createNamedParameter(null));
				try {
					$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
						->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
						->executeStatement();
				} catch (\Throwable) {
					$qb2 = $this->db->getQueryBuilder();
					$qb2->update('crm_mailbox')
						->set('label', $qb2->createNamedParameter(mb_substr($label, 0, 128)))
						->set('imap_host', $qb2->createNamedParameter($imapHost))
						->set('imap_port', $qb2->createNamedParameter($imapPort))
						->set('imap_enc', $qb2->createNamedParameter($imapEnc))
						->set('smtp_host', $qb2->createNamedParameter($smtpHost))
						->set('smtp_port', $qb2->createNamedParameter($smtpPort))
						->set('smtp_enc', $qb2->createNamedParameter($smtpEnc))
						->set('username', $qb2->createNamedParameter($username))
						->set('auth_kind', $qb2->createNamedParameter('imap'))
						->set('kind', $qb2->createNamedParameter(MailAclLogic::KIND_PERSONAL))
						->set('owner_user_id', $qb2->createNamedParameter($userId))
						->set('token_enc', $qb2->createNamedParameter(null))
						->set('refresh_enc', $qb2->createNamedParameter(null))
						->set('token_expires_at', $qb2->createNamedParameter(null))
						->set('dsgvo_ack_at', $qb2->createNamedParameter($now))
						->set('dsgvo_ack_by', $qb2->createNamedParameter($userId))
						->set('status', $qb2->createNamedParameter('ok'))
						->set('auth_fail_count', $qb2->createNamedParameter(0))
						->set('last_error', $qb2->createNamedParameter(null))
						->set('updated_at', $qb2->createNamedParameter($now));
					if ($enc !== null) {
						$qb2->set('secret_enc', $qb2->createNamedParameter($enc));
					}
					$qb2->where($qb2->expr()->eq('id', $qb2->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
						->andWhere($qb2->expr()->eq('workspace_id', $qb2->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
						->executeStatement();
				}
				$connectEvent = 'personal_mail.reconnected';
			} else {
				try {
					$ins = $this->db->getQueryBuilder();
					$ins->insert('crm_mailbox')->values([
						'workspace_id' => $ins->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
						'kind' => $ins->createNamedParameter(MailAclLogic::KIND_PERSONAL),
						'owner_user_id' => $ins->createNamedParameter($userId),
						'auth_kind' => $ins->createNamedParameter('imap'),
						'label' => $ins->createNamedParameter(mb_substr($label, 0, 128)),
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
						'dsgvo_ack_by' => $ins->createNamedParameter($userId),
						'status' => $ins->createNamedParameter('ok'),
						'auth_fail_count' => $ins->createNamedParameter(0),
						'created_by' => $ins->createNamedParameter($userId),
						'created_at' => $ins->createNamedParameter($now),
						'updated_at' => $ins->createNamedParameter($now),
					])->executeStatement();
					$id = (int)$this->db->lastInsertId('crm_mailbox');
				} catch (\OCP\DB\Exception $e) {
					if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
						throw ValidationException::field(
							self::ERR_MAIL_ACCOUNT_CONFLICT,
							'emailAddress',
							$this->l10n->t('This email address is already connected in this workspace as a personal or team mailbox.'),
						);
					}
					throw $e;
				}
				$remoteFolders = isset($test['folders']) && is_array($test['folders']) ? $test['folders'] : ['INBOX'];
				$this->replacePersonalFolders($id, $this->defaultFolderSpecs($remoteFolders));
			}

			$mine = $this->listMine($workspaceId, $userId);
			foreach ($mine as $box) {
				if ((int)$box['id'] === $id) {
					$this->logger->info($connectEvent, [
						'event' => $connectEvent,
						'workspaceId' => $workspaceId,
						'mailboxId' => $id,
						'authKind' => 'imap',
						'actor' => $userId,
					]);
					return $box;
				}
			}

			throw new NotFoundException($this->l10n->t('Personal mailbox not found.'));
		});
	}

	/**
	 * Re-LIST remote IMAP folders and merge newly discovered paths into crm_mail_fld.
	 * Existing sync/share flags are preserved; new folders default to sync off / private.
	 * Also rewrites Modified UTF-7 paths to UTF-8 so umlauts display and match.
	 *
	 * @return array<string, mixed>
	 */
	public function refreshFolders(int $workspaceId, int $mailboxId, string $userId): array
	{
		$this->assertPersonalMailEnabled($workspaceId);
		$this->requireOwnedPersonal($workspaceId, $mailboxId, $userId);
		$lockKey = 'crm_mbox_' . $mailboxId;
		try {
			$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'mailboxId',
				$this->l10n->t('This mailbox is syncing right now. Try refreshing folders again in a moment.'),
			);
		}
		try {
			$remote = $this->mailboxSync->listRemoteFoldersForMailbox($workspaceId, $mailboxId);
			$existing = $this->listFolderDetails($mailboxId);
			$byKey = [];
			foreach ($existing as $f) {
				$path = ImapConnection::normalizeFolderPath((string)($f['path'] ?? ''));
				$key = ImapUtf7::folderKey($path);
				if ($key === '') {
					continue;
				}
				$byKey[$key] = [
					'path' => $path,
					'syncEnabled' => !empty($f['syncEnabled']),
					'shareWithWorkspace' => !empty($f['shareWithWorkspace']),
					'shareAck' => !empty($f['shareWithWorkspace']),
				];
			}
			$added = 0;
			foreach ($remote as $raw) {
				$path = ImapConnection::normalizeFolderPath((string)$raw);
				if ($path === '' || !ImapConnection::isSafeImapFolder($path)) {
					continue;
				}
				$key = ImapUtf7::folderKey($path);
				if ($key === '') {
					continue;
				}
				if (isset($byKey[$key])) {
					// Prefer remote UTF-8 spelling (fixes legacy Modified UTF-7 rows).
					$byKey[$key]['path'] = $path;
					continue;
				}
				if (count($byKey) >= Constants::MAILBOX_MAX_FOLDERS) {
					continue;
				}
				$byKey[$key] = [
					'path' => $path,
					'syncEnabled' => false,
					'shareWithWorkspace' => false,
					'shareAck' => false,
				];
				$added++;
			}
			if ($byKey === []) {
				$byKey['inbox'] = [
					'path' => 'INBOX',
					'syncEnabled' => true,
					'shareWithWorkspace' => false,
					'shareAck' => false,
				];
			}
			$specs = array_values($byKey);
			usort($specs, static function (array $a, array $b): int {
				return strcasecmp((string)$a['path'], (string)$b['path']);
			});
			$this->replacePersonalFolders($mailboxId, $specs, $userId);
			$this->logger->info('personal_mail.refresh_folders', [
				'event' => 'personal_mail.refresh_folders',
				'workspaceId' => $workspaceId,
				'mailboxId' => $mailboxId,
				'remoteCount' => count($remote),
				'added' => $added,
				'stored' => count($specs),
				'actor' => $userId,
			]);
			$mine = $this->listMine($workspaceId, $userId);
			foreach ($mine as $box) {
				if ((int)$box['id'] === $mailboxId) {
					$box['foldersAdded'] = $added;
					$box['remoteFolderCount'] = count($remote);
					$box['folderCap'] = Constants::MAILBOX_MAX_FOLDERS;
					$box['atFolderCap'] = count($specs) >= Constants::MAILBOX_MAX_FOLDERS
						&& $added === 0
						&& count($remote) > count($specs);

					return $box;
				}
			}
			throw new NotFoundException($this->l10n->t('Personal mailbox not found.'));
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
				// Lock release must not mask the original result/error.
			}
		}
	}

	/**
	 * @param list<array{path: string, syncEnabled?: bool, shareWithWorkspace?: bool, shareAck?: bool}> $folders
	 * @return array<string, mixed>
	 */
	public function saveFolders(int $workspaceId, int $mailboxId, string $userId, array $folders): array
	{
		$this->assertPersonalMailEnabled($workspaceId);
		$this->requireOwnedPersonal($workspaceId, $mailboxId, $userId);
		$lockKey = 'crm_mbox_' . $mailboxId;
		try {
			$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'mailboxId',
				$this->l10n->t('This mailbox is syncing right now. Try saving folders again in a moment.'),
			);
		}
		try {
			return $this->saveFoldersLocked($workspaceId, $mailboxId, $userId, $folders);
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
				// Lock release must not mask the original result/error.
			}
		}
	}

	/**
	 * @param list<array{path: string, syncEnabled?: bool, shareWithWorkspace?: bool, shareAck?: bool}> $folders
	 * @return array<string, mixed>
	 */
	private function saveFoldersLocked(int $workspaceId, int $mailboxId, string $userId, array $folders): array
	{
		$specs = [];
		foreach ($folders as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$path = ImapConnection::normalizeFolderPath((string)($raw['path'] ?? $raw['folderPath'] ?? ''));
			if ($path === '' || !ImapConnection::isSafeImapFolder($path)) {
				continue;
			}
			$share = !empty($raw['shareWithWorkspace']);
			if ($share && empty($raw['shareAck'])) {
				throw ValidationException::field(
					self::ERR_MAIL_SHARE_ACK,
					'shareAck',
					$this->l10n->t('Confirm that teammates who can open a linked customer can read mail from this shared folder.'),
				);
			}
			$folderKey = strtoupper($path);
			if ($share && ($folderKey === 'INBOX' || $folderKey === 'SENT' || str_starts_with($folderKey, 'SENT/'))) {
				$confirm = strtoupper(trim((string)($raw['shareConfirm'] ?? '')));
				if ($confirm !== 'SHARE') {
					throw ValidationException::field(
						self::ERR_MAIL_SHARE_ACK,
						'shareConfirm',
						$this->l10n->t('Type SHARE to share Inbox or Sent with your workspace.'),
					);
				}
			}
			$specs[] = [
				'path' => $path,
				'syncEnabled' => array_key_exists('syncEnabled', $raw) ? !empty($raw['syncEnabled']) : true,
				'shareWithWorkspace' => $share,
				'shareAck' => !empty($raw['shareAck']),
			];
			if (count($specs) >= Constants::MAILBOX_MAX_FOLDERS) {
				break;
			}
		}
		if ($specs === []) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'folders',
				$this->l10n->t('Pick at least one folder to sync.'),
			);
		}
		$shareExistingAck = false;
		foreach ($folders as $raw) {
			if (is_array($raw) && !empty($raw['shareExistingAck'])) {
				$shareExistingAck = true;
				break;
			}
		}
		$pendingShareCount = $this->countPrivateMessagesForNewlySharedFolders($workspaceId, $mailboxId, $specs);
		if ($pendingShareCount > 0 && !$shareExistingAck) {
			throw ValidationException::field(
				self::ERR_MAIL_SHARE_EXISTING,
				'shareExistingAck',
				$this->l10n->n(
					'Share %n existing private message with teammates who can open linked customers? Confirm to continue.',
					'Share %n existing private messages with teammates who can open linked customers? Confirm to continue.',
					$pendingShareCount,
				),
			);
		}
		$this->replacePersonalFolders($mailboxId, $specs, $userId);
		$this->recomputeVisibilityForMailbox($workspaceId, $mailboxId);

		$mine = $this->listMine($workspaceId, $userId);
		foreach ($mine as $box) {
			if ((int)$box['id'] === $mailboxId) {
				$shared = 0;
				foreach (($box['folders'] ?? []) as $f) {
					if (!empty($f['shareWithWorkspace'])) {
						$shared++;
					}
				}
				if ($shared > 0) {
					$this->logger->info('personal_mail.share_folder', [
						'event' => 'personal_mail.share_folder',
						'workspaceId' => $workspaceId,
						'mailboxId' => $mailboxId,
						'sharedFolderCount' => $shared,
						'shareExistingAck' => $shareExistingAck,
						'actor' => $userId,
					]);
				}
				return $box;
			}
		}

		throw new NotFoundException($this->l10n->t('Personal mailbox not found.'));
	}

	/**
	 * Count private CRM rows that would flip to shared for folders newly marked share.
	 * Must run before replacePersonalFolders so existing folder ids still match messages.
	 *
	 * @param list<array<string, mixed>> $specs
	 */
	private function countPrivateMessagesForNewlySharedFolders(int $workspaceId, int $mailboxId, array $specs): int
	{
		if (!$this->db->tableExists('crm_mail_msg') || !$this->db->tableExists('crm_mail_fld')) {
			return 0;
		}
		$wantShare = [];
		foreach ($specs as $spec) {
			if (!empty($spec['shareWithWorkspace'])) {
				$path = ImapUtf7::folderKey((string)($spec['path'] ?? ''));
				if ($path !== '') {
					$wantShare[$path] = true;
				}
			}
		}
		if ($wantShare === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'folder_path', 'share_with_workspace')
			->from('crm_mail_fld')
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery();
		$folderIds = [];
		while ($row = $r->fetch()) {
			$path = ImapUtf7::folderKey((string)($row['folder_path'] ?? ''));
			$alreadyShared = (int)($row['share_with_workspace'] ?? 0) === 1;
			if ($path !== '' && isset($wantShare[$path]) && !$alreadyShared) {
				$folderIds[] = (int)$row['id'];
			}
		}
		$r->closeCursor();
		if ($folderIds === []) {
			return 0;
		}
		$count = 0;
		foreach ($folderIds as $folderId) {
			$cq = $this->db->getQueryBuilder();
			$cq->select($cq->func()->count('*', 'c'))
				->from('crm_mail_msg')
				->where($cq->expr()->eq('workspace_id', $cq->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($cq->expr()->eq('mailbox_id', $cq->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
				->andWhere($cq->expr()->eq('folder_id', $cq->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
				->andWhere($cq->expr()->eq('visibility', $cq->createNamedParameter(MailAclLogic::VISIBILITY_PRIVATE)));
			$cr = $cq->executeQuery();
			$row = $cr->fetch();
			$cr->closeCursor();
			$count += (int)($row['c'] ?? 0);
		}

		return $count;
	}

	/**
	 * Disconnect personal account and erase CRM-stored bodies/index for it.
	 */
	public function disconnect(int $workspaceId, int $mailboxId, string $userId): void
	{
		$this->requireOwnedPersonal($workspaceId, $mailboxId, $userId);
		$this->mailboxSync->deleteMailbox($workspaceId, $mailboxId, true);
		$this->logger->info('personal_mail.disconnected', [
			'event' => 'personal_mail.disconnected',
			'workspaceId' => $workspaceId,
			'mailboxId' => $mailboxId,
			'actor' => $userId,
		]);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function saveSignature(int $workspaceId, int $mailboxId, string $userId, array $data): array
	{
		$this->requireOwnedPersonal($workspaceId, $mailboxId, $userId);
		$html = $this->htmlSanitize->sanitizeSignature((string)($data['signatureHtml'] ?? $data['html'] ?? ''));
		$plain = trim((string)($data['signaturePlain'] ?? $data['plain'] ?? ''));
		if ($plain === '' && $html !== '') {
			$plain = $this->htmlSanitize->signatureHtmlToPlain($html);
		}
		if (strlen($html) > 65536) {
			throw ValidationException::field(
				self::ERR_MAIL_SIGNATURE_TOO_LARGE,
				'signatureHtml',
				$this->l10n->t('Signature is too large (max 64 KiB after cleanup).'),
			);
		}
		if (strlen($plain) > 32768) {
			throw ValidationException::field(
				self::ERR_MAIL_SIGNATURE_TOO_LARGE,
				'signaturePlain',
				$this->l10n->t('Plain signature is too large (max 32 KiB).'),
			);
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('signature_html', $qb->createNamedParameter($html !== '' ? $html : null))
			->set('signature_plain', $qb->createNamedParameter($plain !== '' ? $plain : null))
			->set('signature_updated_at', $qb->createNamedParameter($now))
			->set('signature_updated_by', $qb->createNamedParameter($userId))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$mine = $this->listMine($workspaceId, $userId);
		foreach ($mine as $box) {
			if ((int)$box['id'] === $mailboxId) {
				return $box;
			}
		}

		throw new NotFoundException($this->l10n->t('Personal mailbox not found.'));
	}

	/**
	 * Sales Inbox page for the actor (private + shared personal + team if mail.read).
	 *
	 * @return array{
	 *   items: list<array<string, mixed>>,
	 *   nextCursor: ?string,
	 *   hasMore: bool,
	 *   personalMailEnabled: bool,
	 *   sharedFolders: list<string>,
	 *   accounts: list<array<string, mixed>>,
	 *   authAlerts: list<array<string, mixed>>
	 * }
	 */
	public function listInbox(
		int $workspaceId,
		string $userId,
		int $limit = 50,
		string $tab = 'mine',
		int $mailboxIdFilter = 0,
		bool $canTriage = false,
		?string $cursor = null,
	): array {
		$settings = $this->workspaces->getSettings($workspaceId);
		$enabled = !empty($settings['personalMailEnabled']);
		$emptyMeta = [
			'items' => [],
			'nextCursor' => null,
			'hasMore' => false,
			'personalMailEnabled' => $enabled,
			'sharedFolders' => [],
			'accounts' => [],
			'authAlerts' => [],
		];
		if (!$this->db->tableExists('crm_mail_msg')) {
			return $emptyMeta;
		}
		$limit = MailInboxPageLogic::clampLimit($limit);
		$tab = MailInboxPageLogic::normalizeTab($tab);
		$accounts = $this->inboxAccountSummaries($workspaceId, $userId);
		$sharedFolders = $this->sharedFolderLabelsForUser($workspaceId, $userId);
		$authAlerts = [];
		foreach ($accounts as $acc) {
			$status = strtolower((string)($acc['status'] ?? ''));
			if (in_array($status, ['error', 'auth', 'auth_error'], true) || (int)($acc['authFailCount'] ?? 0) >= 3) {
				$authAlerts[] = [
					'mailboxId' => (int)$acc['id'],
					'label' => (string)($acc['label'] ?? $acc['email'] ?? ''),
					'email' => (string)($acc['email'] ?? ''),
					'status' => $status !== '' ? $status : 'error',
					'lastError' => (string)($acc['lastError'] ?? ''),
				];
			}
		}

		$accepted = [];
		$want = $limit + 1;
		$passCursor = $cursor;
		$fetchWindow = MailInboxPageLogic::fetchWindow($limit);
		for ($pass = 0; $pass < MailInboxPageLogic::MAX_FETCH_PASSES && count($accepted) < $want; $pass++) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id', 'm.subject', 'm.from_addr', 'm.snippet', 'm.sent_at', 'm.visibility',
				'm.owner_user_id', 'm.company_id', 'm.triage_status', 'm.mailbox_id', 'b.kind', 'b.label', 'b.email_address')
				->from('crm_mail_msg', 'm')
				->leftJoin('m', 'crm_mailbox', 'b', $qb->expr()->eq('m.mailbox_id', 'b.id'))
				->where($qb->expr()->eq('m.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->orderBy('m.sent_at', 'DESC')
				->addOrderBy('m.id', 'DESC')
				->setMaxResults($fetchWindow);

			$parsedCursor = MailInboxPageLogic::decodeCursor($passCursor);
			if ($parsedCursor !== null) {
				$cAt = $parsedCursor['sentAt'];
				$cId = $parsedCursor['id'];
				// Strictly older than cursor in (sent_at DESC, id DESC) order.
				if ($cAt === '') {
					$qb->andWhere($qb->expr()->andX(
						$qb->expr()->orX(
							$qb->expr()->isNull('m.sent_at'),
							$qb->expr()->eq('m.sent_at', $qb->createNamedParameter('')),
						),
						$qb->expr()->lt('m.id', $qb->createNamedParameter($cId, IQueryBuilder::PARAM_INT)),
					));
				} else {
					$qb->andWhere($qb->expr()->orX(
						$qb->expr()->lt('m.sent_at', $qb->createNamedParameter($cAt)),
						$qb->expr()->andX(
							$qb->expr()->eq('m.sent_at', $qb->createNamedParameter($cAt)),
							$qb->expr()->lt('m.id', $qb->createNamedParameter($cId, IQueryBuilder::PARAM_INT)),
						),
					));
				}
			}

			if ($mailboxIdFilter > 0) {
				$qb->andWhere($qb->expr()->eq('m.mailbox_id', $qb->createNamedParameter($mailboxIdFilter, IQueryBuilder::PARAM_INT)));
			}

			if ($tab === 'mine') {
				$qb->andWhere($qb->expr()->orX(
					$qb->expr()->eq('m.owner_user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->andX(
						$qb->expr()->eq('b.kind', $qb->createNamedParameter(MailAclLogic::KIND_PERSONAL)),
						$qb->expr()->eq('b.owner_user_id', $qb->createNamedParameter($userId)),
					),
				));
			} elseif ($tab === 'team') {
				$qb->andWhere($qb->expr()->eq('b.kind', $qb->createNamedParameter(MailAclLogic::KIND_TEAM)));
			} else {
				// unmatched only (normalizeTab collapses unknowns to mine)
				$qb->andWhere($qb->expr()->eq('m.triage_status', $qb->createNamedParameter('open')));
				$qb->andWhere($qb->expr()->eq('m.visibility', $qb->createNamedParameter(MailAclLogic::VISIBILITY_SHARED)));
			}

			$r = $qb->executeQuery();
			$rawCount = 0;
			$lastScannedId = 0;
			$lastScannedSentAt = '';
			while ($row = $r->fetch()) {
				$rawCount++;
				$lastScannedId = (int)($row['id'] ?? 0);
				$lastScannedSentAt = MailInboxPageLogic::normalizeSentAtForCursor($row['sent_at'] ?? null);
				$visibility = MailAclLogic::normalizeVisibility((string)($row['visibility'] ?? 'shared'));
				$owner = (string)($row['owner_user_id'] ?? '');
				$companyId = isset($row['company_id']) && $row['company_id'] !== null ? (int)$row['company_id'] : 0;
				// Inbox list: owner sees own; team/unmatched require shared visibility (already filtered).
				if ($tab === 'mine') {
					if ($owner !== '' && !hash_equals($owner, $userId) && (string)($row['kind'] ?? '') === MailAclLogic::KIND_PERSONAL) {
						continue;
					}
				}
				$item = [
					'id' => (int)$row['id'],
					'subject' => (string)($row['subject'] ?? ''),
					'from' => (string)($row['from_addr'] ?? ''),
					'snippet' => (string)($row['snippet'] ?? ''),
					'sentAt' => $lastScannedSentAt !== '' ? $lastScannedSentAt : null,
					'visibility' => $visibility,
					'companyId' => $companyId > 0 ? $companyId : null,
					'triageStatus' => (string)($row['triage_status'] ?? ''),
					'mailboxId' => (int)($row['mailbox_id'] ?? 0),
					'mailboxKind' => (string)($row['kind'] ?? MailAclLogic::KIND_TEAM),
					'mailboxLabel' => (string)($row['label'] ?? ''),
					'mailboxEmail' => (string)($row['email_address'] ?? ''),
					'private' => $visibility === MailAclLogic::VISIBILITY_PRIVATE,
				];
				if ($tab === 'unmatched' && !$canTriage) {
					$item = MailAclLogic::redactPersonalUnmatchedListItem(
						$item,
						$this->l10n->t('Needs triage'),
					);
				}
				$accepted[] = $item;
				if (count($accepted) >= $want) {
					break;
				}
			}
			$r->closeCursor();

			if ($rawCount === 0 || $lastScannedId <= 0) {
				break;
			}
			// Advance past the last scanned row (including skips) so the next pass does not re-read.
			$passCursor = MailInboxPageLogic::encodeCursor($lastScannedSentAt, $lastScannedId);
			if ($passCursor === null || $rawCount < $fetchWindow) {
				break;
			}
		}

		$page = MailInboxPageLogic::pageResult($accepted, $limit);

		return [
			'items' => $page['items'],
			'nextCursor' => $page['nextCursor'],
			'hasMore' => $page['hasMore'],
			'personalMailEnabled' => $enabled,
			'sharedFolders' => $sharedFolders,
			'accounts' => $accounts,
			'authAlerts' => $authAlerts,
		];
	}

	/**
	 * @return list<string>
	 */
	private function sharedFolderLabelsForUser(int $workspaceId, string $userId): array
	{
		if ($workspaceId <= 0 || $userId === '' || !$this->db->tableExists('crm_mail_fld')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.folder_path')
			->from('crm_mail_fld', 'f')
			->innerJoin('f', 'crm_mailbox', 'b', $qb->expr()->eq('f.mailbox_id', 'b.id'))
			->where($qb->expr()->eq('b.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('b.kind', $qb->createNamedParameter(MailAclLogic::KIND_PERSONAL)))
			->andWhere($qb->expr()->eq('b.owner_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('f.share_with_workspace', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->orderBy('f.folder_path', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$path = trim((string)($row['folder_path'] ?? ''));
			if ($path !== '') {
				$out[$path] = true;
			}
		}
		$r->closeCursor();

		return array_keys($out);
	}

	/**
	 * @return list<array{id: int, label: string, email: string, kind: string, status: string, authFailCount: int, lastError: string}>
	 */
	private function inboxAccountSummaries(int $workspaceId, string $userId): array
	{
		if ($workspaceId <= 0 || !$this->db->tableExists('crm_mailbox')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'label', 'email_address', 'kind', 'status', 'auth_fail_count', 'last_error', 'owner_user_id')
			->from('crm_mailbox')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('kind', 'ASC')
			->addOrderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$kind = (string)($row['kind'] ?? MailAclLogic::KIND_TEAM);
			$owner = (string)($row['owner_user_id'] ?? '');
			if ($kind === MailAclLogic::KIND_PERSONAL && ($owner === '' || !hash_equals($owner, $userId))) {
				continue;
			}
			$out[] = [
				'id' => (int)$row['id'],
				'label' => (string)($row['label'] ?? ''),
				'email' => (string)($row['email_address'] ?? ''),
				'kind' => $kind !== '' ? $kind : MailAclLogic::KIND_TEAM,
				'status' => (string)($row['status'] ?? ''),
				'authFailCount' => (int)($row['auth_fail_count'] ?? 0),
				'lastError' => (string)($row['last_error'] ?? ''),
			];
		}
		$r->closeCursor();

		return $out;
	}

	public function assertCanReadMessage(int $workspaceId, int $messageId, string $actorUserId, bool $canReadCompany, bool $canReadOrTriageMail): void
	{
		$row = $this->loadMessageAclRow($workspaceId, $messageId);
		if ($row === null) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
		$ok = MailAclLogic::canReadMessage(
			$actorUserId,
			[
				'visibility' => (string)($row['visibility'] ?? MailAclLogic::VISIBILITY_SHARED),
				'ownerUserId' => $row['owner_user_id'] ?? null,
				'companyId' => isset($row['company_id']) ? (int)$row['company_id'] : null,
				'mailboxKind' => (string)($row['mailbox_kind'] ?? MailAclLogic::KIND_TEAM),
			],
			$canReadCompany,
			$canReadOrTriageMail,
		);
		if (!$ok) {
			throw new NotFoundException($this->l10n->t('Message not found'));
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadMessageAclRow(int $workspaceId, int $messageId): ?array
	{
		if ($workspaceId <= 0 || $messageId <= 0 || !$this->db->tableExists('crm_mail_msg')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id', 'm.visibility', 'm.owner_user_id', 'm.company_id', 'b.kind AS mailbox_kind')
			->from('crm_mail_msg', 'm')
			->leftJoin('m', 'crm_mailbox', 'b', $qb->expr()->eq('m.mailbox_id', 'b.id'))
			->where($qb->expr()->eq('m.id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();

		return $row === false ? null : $row;
	}

	/**
	 * NN-05 / FAC-19 — public so OAuth complete can reuse the same typed conflict.
	 */
	public function assertEmailAddressAvailable(int $workspaceId, string $email, ?int $exceptId = null): void
	{
		$this->assertEmailAvailable($workspaceId, $email, $exceptId);
	}

	/**
	 * Exclusive lock per workspace+email so IMAP/OAuth connect cannot race (NN-05).
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function withEmailConnectLock(int $workspaceId, string $emailAddress, callable $fn): mixed
	{
		$email = strtolower(trim($emailAddress));
		$lockKey = 'crm_pm_email_' . $workspaceId . '_' . hash('sha256', $email !== '' ? $email : '_');
		try {
			$this->locks->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'emailAddress',
				$this->l10n->t('Another connect is already in progress for this address. Try again in a moment.'),
			);
		}
		try {
			return $fn();
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
				// Never mask the connect result.
			}
		}
	}

	private function assertEmailAvailable(int $workspaceId, string $email, ?int $exceptId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'kind', 'owner_user_id')
			->from('crm_mailbox')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('email_address', $qb->createNamedParameter($email)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return;
		}
		if ($exceptId !== null && (int)$row['id'] === $exceptId) {
			return;
		}
		throw ValidationException::field(
			self::ERR_MAIL_ACCOUNT_CONFLICT,
			'emailAddress',
			$this->l10n->t('This email address is already connected in this workspace as a personal or team mailbox.'),
		);
	}

	private function requireOwnedPersonal(int $workspaceId, int $mailboxId, string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(MailAclLogic::KIND_PERSONAL)))
			->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('Personal mailbox not found.'));
		}
	}

	/**
	 * Reconnect heal must keep the stored email. Changing address requires Disconnect first
	 * so synced corpus / folders are erased intentionally (US-002 erase path).
	 */
	private function assertOwnedPersonalEmailUnchanged(
		int $workspaceId,
		int $mailboxId,
		string $userId,
		string $emailAddress,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('email_address')
			->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(MailAclLogic::KIND_PERSONAL)))
			->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('Personal mailbox not found.'));
		}
		$stored = strtolower(trim((string)($row['email_address'] ?? '')));
		if ($stored !== $emailAddress) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'emailAddress',
				$this->l10n->t('To connect a different email address, disconnect this mailbox first. That removes synced messages from the workspace.'),
			);
		}
	}

	private function loadSecretForOwner(int $workspaceId, int $mailboxId, string $userId): string
	{
		if ($mailboxId <= 0) {
			return '';
		}
		$this->requireOwnedPersonal($workspaceId, $mailboxId, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('secret_enc')->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		$enc = trim((string)($row['secret_enc'] ?? ''));
		if ($enc === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($enc);
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * @param list<string> $remoteFolders
	 * @return list<array{path: string, syncEnabled: bool, shareWithWorkspace: bool, shareAck: bool}>
	 */
	private function defaultFolderSpecs(array $remoteFolders): array
	{
		$paths = [];
		$seen = [];
		foreach ($remoteFolders as $p) {
			$p = ImapConnection::normalizeFolderPath((string)$p);
			if ($p === '' || !ImapConnection::isSafeImapFolder($p)) {
				continue;
			}
			$key = ImapUtf7::folderKey($p);
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$paths[] = $p;
			if (count($paths) >= Constants::MAILBOX_MAX_FOLDERS) {
				break;
			}
		}
		if ($paths === []) {
			$paths = ['INBOX'];
		}
		$out = [];
		foreach ($paths as $path) {
			$out[] = [
				'path' => $path,
				'syncEnabled' => strtoupper($path) === 'INBOX',
				'shareWithWorkspace' => false,
				'shareAck' => false,
			];
		}

		return $out;
	}

	/**
	 * Upsert personal folders by path so message folder_id rows stay valid (AS-16).
	 *
	 * @param list<array{path: string, syncEnabled?: bool, shareWithWorkspace?: bool, shareAck?: bool}> $specs
	 */
	private function replacePersonalFolders(int $mailboxId, array $specs, string $actor = ''): void
	{
		$existing = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'folder_path')->from('crm_mail_fld')
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery();
		while ($row = $r->fetch()) {
			$key = ImapUtf7::folderKey((string)($row['folder_path'] ?? ''));
			if ($key !== '') {
				$existing[$key] = (int)$row['id'];
			}
		}
		$r->closeCursor();

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$keepIds = [];
		foreach ($specs as $spec) {
			$path = ImapConnection::normalizeFolderPath((string)($spec['path'] ?? ''));
			if ($path === '' || !ImapConnection::isSafeImapFolder($path)) {
				continue;
			}
			$key = ImapUtf7::folderKey($path);
			$share = !empty($spec['shareWithWorkspace']);
			$sync = !empty($spec['syncEnabled']) ? 1 : 0;
			if (isset($existing[$key])) {
				$id = $existing[$key];
				$keepIds[$id] = true;
				$upd = $this->db->getQueryBuilder();
				$upd->update('crm_mail_fld')
					->set('folder_path', $upd->createNamedParameter($path))
					->set('sync_enabled', $upd->createNamedParameter($sync, IQueryBuilder::PARAM_INT))
					->set('share_with_workspace', $upd->createNamedParameter($share ? 1 : 0, IQueryBuilder::PARAM_INT));
				if ($share) {
					$upd->set('share_ack_at', $upd->createNamedParameter($now))
						->set('share_ack_by', $upd->createNamedParameter($actor !== '' ? $actor : null));
				} else {
					$upd->set('share_ack_at', $upd->createNamedParameter(null))
						->set('share_ack_by', $upd->createNamedParameter(null));
				}
				$upd->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
					->executeStatement();
				continue;
			}
			$ins = $this->db->getQueryBuilder();
			$values = [
				'mailbox_id' => $ins->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT),
				'folder_path' => $ins->createNamedParameter($path),
				'sync_enabled' => $ins->createNamedParameter($sync, IQueryBuilder::PARAM_INT),
				'share_with_workspace' => $ins->createNamedParameter($share ? 1 : 0, IQueryBuilder::PARAM_INT),
				'created_at' => $ins->createNamedParameter($now),
			];
			if ($share) {
				$values['share_ack_at'] = $ins->createNamedParameter($now);
				$values['share_ack_by'] = $ins->createNamedParameter($actor !== '' ? $actor : null);
			}
			$ins->insert('crm_mail_fld')->values($values)->executeStatement();
			$newId = (int)$this->db->lastInsertId('crm_mail_fld');
			if ($newId > 0) {
				$keepIds[$newId] = true;
			}
		}

		foreach ($existing as $id) {
			if (isset($keepIds[$id])) {
				continue;
			}
			// Dropping a folder from sync: keep messages private and detach folder FK.
			$msg = $this->db->getQueryBuilder();
			$msg->update('crm_mail_msg')
				->set('visibility', $msg->createNamedParameter(MailAclLogic::VISIBILITY_PRIVATE))
				->where($msg->expr()->eq('mailbox_id', $msg->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
				->andWhere($msg->expr()->eq('folder_id', $msg->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$del = $this->db->getQueryBuilder();
			$del->delete('crm_mail_fld')
				->where($del->expr()->eq('id', $del->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	private function recomputeVisibilityForMailbox(int $workspaceId, int $mailboxId): void
	{
		if (!$this->db->tableExists('crm_mail_msg') || !$this->db->tableExists('crm_mail_fld')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'share_with_workspace')->from('crm_mail_fld')
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery();
		$shareByFolder = [];
		while ($row = $r->fetch()) {
			$shareByFolder[(int)$row['id']] = (int)($row['share_with_workspace'] ?? 0) === 1;
		}
		$r->closeCursor();
		foreach ($shareByFolder as $folderId => $share) {
			$visibility = $share ? MailAclLogic::VISIBILITY_SHARED : MailAclLogic::VISIBILITY_PRIVATE;
			$upd = $this->db->getQueryBuilder();
			$upd->update('crm_mail_msg')
				->set('visibility', $upd->createNamedParameter($visibility))
				->where($upd->expr()->eq('workspace_id', $upd->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('mailbox_id', $upd->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('folder_id', $upd->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function serializeAccount(array $row): array
	{
		$id = (int)$row['id'];
		$folders = $this->listFolderDetails($id);

		return [
			'id' => $id,
			'kind' => MailAclLogic::KIND_PERSONAL,
			'ownerUserId' => (string)($row['owner_user_id'] ?? ''),
			'authKind' => (string)($row['auth_kind'] ?? 'imap'),
			'label' => (string)($row['label'] ?? ''),
			'emailAddress' => (string)($row['email_address'] ?? ''),
			'imapHost' => (string)($row['imap_host'] ?? ''),
			'imapPort' => (int)($row['imap_port'] ?? 993),
			'imapEnc' => (string)($row['imap_enc'] ?? 'ssl'),
			'smtpHost' => (string)($row['smtp_host'] ?? ''),
			'smtpPort' => (int)($row['smtp_port'] ?? 465),
			'smtpEnc' => (string)($row['smtp_enc'] ?? 'ssl'),
			'username' => (string)($row['username'] ?? ''),
			'status' => (string)($row['status'] ?? 'ok'),
			'authFailCount' => (int)($row['auth_fail_count'] ?? 0),
			'lastSyncAt' => $row['last_sync_at'] ?? null,
			'lastError' => $row['last_error'] ?? null,
			'dsgvoAckAt' => $row['dsgvo_ack_at'] ?? null,
			'hasSecret' => trim((string)($row['secret_enc'] ?? '')) !== '',
			'signatureHtml' => (string)($row['signature_html'] ?? ''),
			'signaturePlain' => (string)($row['signature_plain'] ?? ''),
			'hasSignature' => trim((string)($row['signature_html'] ?? '')) !== '' || trim((string)($row['signature_plain'] ?? '')) !== '',
			'folders' => $folders,
			'sharedFolderCount' => count(array_filter($folders, static fn (array $f): bool => !empty($f['shareWithWorkspace']))),
		];
	}

	/**
	 * @return list<array{path: string, syncEnabled: bool, shareWithWorkspace: bool}>
	 */
	private function listFolderDetails(int $mailboxId): array
	{
		if ($mailboxId <= 0 || !$this->db->tableExists('crm_mail_fld')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('folder_path', 'sync_enabled', 'share_with_workspace')
			->from('crm_mail_fld')
			->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$path = ImapConnection::normalizeFolderPath((string)($row['folder_path'] ?? ''));
			if ($path === '') {
				continue;
			}
			$out[] = [
				'path' => $path,
				'syncEnabled' => (int)($row['sync_enabled'] ?? 0) === 1,
				'shareWithWorkspace' => (int)($row['share_with_workspace'] ?? 0) === 1,
			];
		}
		$r->closeCursor();

		return $out;
	}
}
