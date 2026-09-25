<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Manager 1:1 prep notes — product §13.4 / Team Coaching.
 *
 * Ownership of deals/accounts never changes here; notes are coaching ledger only.
 */
class OneOnOneService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ChaseService $chase,
		private readonly PromiseService $promises,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly ?WorkspaceService $workspaces = null,
	) {
	}

	/**
	 * @return array{
	 *   reportUserId: string,
	 *   chase: list<array<string, mixed>>,
	 *   promisesDue: list<array<string, mixed>>,
	 *   notes: ?array{id: int, notes: string, meetingOn: ?string, updatedAt: ?string},
	 *   history: list<array<string, mixed>>
	 * }
	 */
	public function prep(int $workspaceId, string $managerUserId, string $reportUserId): array
	{
		$reportUserId = trim($reportUserId);
		if ($reportUserId === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'reportUserId', $this->l10n->t('Pick a teammate for 1:1 prep.'));
		}
		// The report is a teammate — must resolve to a real member of this
		// workspace (§13.5 mass-assignment gate; also kills the member-existence
		// oracle the response diff would otherwise expose).
		$this->workspaces?->assertOwnerCandidate($workspaceId, $reportUserId, 'reportUserId');
		$today = $this->timeFactory->getDateTime();
		$chase = $this->chase->myChase($reportUserId, $workspaceId, $today);
		$promises = [];
		foreach ($this->promises->dueSoon($workspaceId, $today, 14) as $p) {
			if (($p['ownerUserId'] ?? '') === $reportUserId) {
				$promises[] = $p;
			}
		}
		$latest = $this->latest($workspaceId, $managerUserId, $reportUserId);
		return [
			'reportUserId' => $reportUserId,
			'chase' => array_slice($chase, 0, 25),
			'promisesDue' => array_slice($promises, 0, 25),
			'notes' => $latest,
			'history' => $this->history($workspaceId, $managerUserId, $reportUserId, 10),
		];
	}

	/**
	 * @param array{notes?: string, meetingOn?: ?string} $data
	 * @return array{id: int, notes: string, meetingOn: ?string}
	 */
	public function save(int $workspaceId, string $managerUserId, string $reportUserId, array $data): array
	{
		$reportUserId = trim($reportUserId);
		if ($reportUserId === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'reportUserId', $this->l10n->t('Pick a teammate for 1:1 prep.'));
		}
		$this->workspaces?->assertOwnerCandidate($workspaceId, $reportUserId, 'reportUserId');
		$notes = trim((string)($data['notes'] ?? ''));
		if (mb_strlen($notes) > 8000) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'notes', $this->l10n->t('Notes are too long (max 8000 characters).'));
		}
		$meetingOn = isset($data['meetingOn']) && is_string($data['meetingOn']) && $data['meetingOn'] !== ''
			? $data['meetingOn']
			: $this->timeFactory->getDateTime()->format('Y-m-d');
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_1on1')->values([
			'workspace_id' => $ins->createNamedParameter($workspaceId),
			'manager_user_id' => $ins->createNamedParameter($managerUserId),
			'report_user_id' => $ins->createNamedParameter($reportUserId),
			'notes' => $ins->createNamedParameter($notes !== '' ? $notes : null),
			'meeting_on' => $ins->createNamedParameter($meetingOn),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();
		return [
			'id' => (int)$this->db->lastInsertId('crm_1on1'),
			'notes' => $notes,
			'meetingOn' => $meetingOn,
		];
	}

	/** @return ?array{id: int, notes: string, meetingOn: ?string, updatedAt: ?string} */
	private function latest(int $workspaceId, string $managerUserId, string $reportUserId): ?array
	{
		if (!$this->db->tableExists('crm_1on1')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_1on1')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('manager_user_id', $qb->createNamedParameter($managerUserId)))
			->andWhere($qb->expr()->eq('report_user_id', $qb->createNamedParameter($reportUserId)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if (!is_array($row)) {
			return null;
		}
		return [
			'id' => (int)$row['id'],
			'notes' => (string)($row['notes'] ?? ''),
			'meetingOn' => $row['meeting_on'] !== null ? (string)$row['meeting_on'] : null,
			'updatedAt' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
		];
	}

	/** @return list<array<string, mixed>> */
	private function history(int $workspaceId, string $managerUserId, string $reportUserId, int $limit): array
	{
		if (!$this->db->tableExists('crm_1on1')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_1on1')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('manager_user_id', $qb->createNamedParameter($managerUserId)))
			->andWhere($qb->expr()->eq('report_user_id', $qb->createNamedParameter($reportUserId)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'notes' => (string)($row['notes'] ?? ''),
				'meetingOn' => $row['meeting_on'] !== null ? (string)$row['meeting_on'] : null,
				'updatedAt' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
			];
		}
		$r->closeCursor();
		return $out;
	}
}
