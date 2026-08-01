<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
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
 * Personal IMAP is never ingested — only workspace-owned rows in crm_mailbox.
 */
class MailboxSyncService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ICrypto $crypto,
		private readonly MailboxService $mailboxes,
		private readonly WorkspaceService $workspaces,
		private readonly NotificationService $notifications,
		private readonly ILockingProvider $locks,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
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
		if ($this->db->tableExists('crm_mailbox')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'workspace_id')->from('crm_mailbox')
				->where($qb->expr()->neq('status', $qb->createNamedParameter('error')));
			$r = $qb->executeQuery();
			while ($row = $r->fetch()) {
				$mailboxes++;
				try {
					$result = $this->syncOne((int)$row['id'], (int)$row['workspace_id']);
					$messages += (int)$result['ingested'];
				} catch (\Throwable $e) {
					$errors++;
					$this->logger->warning('CustomerCheck mailbox sync failed', [
						'mailboxId' => (int)$row['id'],
						'exception' => $e,
					]);
				}
			}
			$r->closeCursor();
		}
		// Retention purge runs after sync so a concurrent ingest cannot race a
		// mid-flight message into a half-deleted state for the same batch.
		$purge = $this->purgeExpiredAcrossWorkspaces();
		return [
			'mailboxes' => $mailboxes,
			'messages' => $messages,
			'errors' => $errors,
			'purged' => $purge['purged'],
			'purgeRemaining' => $purge['remaining'],
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
			return $this->doSync($mailboxId, $workspaceId);
		} finally {
			try {
				$this->locks->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (\Throwable) {
			}
		}
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
		$n = 0;
		foreach (array_slice($messages, 0, Constants::MAILBOX_SYNC_CAP) as $msg) {
			$this->mailboxes->ingestMessage($workspaceId, $mailboxId, $folderId, $msg, $persons);
			$n++;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_mailbox')
			->set('last_sync_at', $qb->createNamedParameter($now))
			->set('status', $qb->createNamedParameter('ok'))
			->set('auth_fail_count', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->executeStatement();
		return ['ingested' => $n];
	}

	/**
	 * Test IMAP credentials without indexing mail.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection(array $cfg): array
	{
		if (!function_exists('imap_open')) {
			return [
				'ok' => false,
				'message' => $this->l10n->t('PHP IMAP extension is not available on this server.'),
			];
		}
		$host = (string)($cfg['imapHost'] ?? '');
		$port = (int)($cfg['imapPort'] ?? 993);
		$user = (string)($cfg['username'] ?? '');
		$pass = (string)($cfg['password'] ?? '');
		$enc = (string)($cfg['imapEnc'] ?? 'ssl');
		if ($host === '' || $user === '' || $pass === '') {
			return ['ok' => false, 'message' => $this->l10n->t('Host, username and password are required.')];
		}
		$mailbox = sprintf('{%s:%d/imap/%s}INBOX', $host, $port, $enc === 'tls' ? 'tls' : 'ssl');
		$stream = @imap_open($mailbox, $user, $pass, OP_HALFOPEN, 1);
		if ($stream === false) {
			return ['ok' => false, 'message' => $this->l10n->t('IMAP connection failed.')];
		}
		imap_close($stream);
		return ['ok' => true, 'message' => $this->l10n->t('IMAP connection OK.')];
	}

	/**
	 * @return array{ingested: int, status: string}
	 */
	private function doSync(int $mailboxId, int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_mailbox')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($mailboxId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return ['ingested' => 0, 'status' => 'missing'];
		}

		if (!function_exists('imap_open')) {
			// Soft degrade: mark last_error but do not trip auth_fail.
			$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
			$u = $this->db->getQueryBuilder();
			$u->update('crm_mailbox')
				->set('last_error', $u->createNamedParameter('PHP IMAP extension missing'))
				->set('last_sync_at', $u->createNamedParameter($now))
				->where($u->expr()->eq('id', $u->createNamedParameter($mailboxId)))
				->executeStatement();
			return ['ingested' => 0, 'status' => 'no_imap'];
		}

		try {
			$secret = $this->crypto->decrypt((string)$row['secret_enc']);
		} catch (\Throwable) {
			$this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
			return ['ingested' => 0, 'status' => 'auth'];
		}

		$host = (string)$row['imap_host'];
		$port = (int)$row['imap_port'];
		$enc = (string)$row['imap_enc'];
		$user = (string)$row['username'];
		$mailboxPath = sprintf('{%s:%d/imap/%s}', $host, $port, $enc === 'tls' ? 'tls' : 'ssl');
		$stream = @imap_open($mailboxPath . 'INBOX', $user, $secret, 0, 1);
		if ($stream === false) {
			$status = $this->mailboxes->recordAuthFailure($mailboxId, (int)$row['auth_fail_count']);
			if ($status === 'error') {
				$wsId = (int)($row['workspace_id'] ?? 0);
				if ($wsId > 0) {
					$this->notifications->notifyWorkspaceUsers(
						$wsId,
						Constants::NOTIFY_DIGEST,
						'mailbox_error',
						(string)$mailboxId,
						['label' => (string)$row['label']],
					);
				}
			}
			return ['ingested' => 0, 'status' => $status];
		}

		$persons = $this->personIndex($workspaceId);
		$folders = $this->enabledFolders($mailboxId);
		$ingested = 0;
		foreach ($folders as $folder) {
			$folderPath = (string)$folder['folder_path'];
			$full = $mailboxPath . $folderPath;
			@imap_reopen($stream, $full);
			$n = @imap_num_msg($stream);
			if ($n === false || $n < 1) {
				continue;
			}
			$start = max(1, $n - Constants::MAILBOX_SYNC_CAP + 1);
			for ($i = $n; $i >= $start && $ingested < Constants::MAILBOX_SYNC_CAP; $i--) {
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
				$subject = isset($hdr->subject) ? (string)@imap_utf8($hdr->subject) : '';
				$sentAt = isset($hdr->date) ? date('Y-m-d H:i:s', strtotime((string)$hdr->date) ?: time()) : null;
				$this->mailboxes->ingestMessage($workspaceId, $mailboxId, (int)$folder['id'], [
					'uid' => $uid,
					'from' => $from,
					'subject' => $subject,
					'snippet' => mb_substr($subject, 0, 200),
					'sentAt' => $sentAt,
					'direction' => 'inbound',
				], $persons);
				$ingested++;
			}
		}
		imap_close($stream);

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$u = $this->db->getQueryBuilder();
		$u->update('crm_mailbox')
			->set('last_sync_at', $u->createNamedParameter($now))
			->set('status', $u->createNamedParameter('ok'))
			->set('auth_fail_count', $u->createNamedParameter(0))
			->set('last_error', $u->createNamedParameter(null))
			->where($u->expr()->eq('id', $u->createNamedParameter($mailboxId)))
			->executeStatement();

		return ['ingested' => $ingested, 'status' => 'ok'];
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
			->andWhere($qb->expr()->isNotNull('p.email'));
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
