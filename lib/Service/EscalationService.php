<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;

/**
 * Manager escalations — product §13.3.
 *
 * Max 50 open/WS. Auto-resolve when subject is no longer stuck.
 */
class EscalationService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly ILockingProvider $locking,
		private readonly ?WorkspaceService $workspaces = null,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function listOpen(int $workspaceId): array
	{
		if (!$this->db->tableExists('crm_escal')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_escal')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->orderBy('id', 'ASC')
			->setMaxResults(Constants::ESCALATION_MAX_OPEN);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = $this->map($row);
		}
		$r->closeCursor();
		return $out;
	}

	/**
	 * @param array{subjectUserId: string, reason: string, companyId?: int, dealId?: int} $data
	 * @return array<string, mixed>
	 */
	public function create(int $workspaceId, array $data, string $actor): array
	{
		if ($workspaceId <= 0) {
			throw new NotFoundException($this->l10n->t('Workspace not found.'));
		}

		$lockKey = 'crm_escal_open/' . $workspaceId;
		$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$open = $this->listOpen($workspaceId);
			if (count($open) >= Constants::ESCALATION_MAX_OPEN) {
				throw ValidationException::field(
					Constants::ERR_ESCALATION_LIMIT,
					'escalation',
					$this->l10n->t('Maximum of 50 open escalations per workspace.'),
				);
			}
			$subject = trim((string)($data['subjectUserId'] ?? ''));
			$reason = trim((string)($data['reason'] ?? ''));
			if ($subject === '' || $reason === '') {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'reason', $this->l10n->t('Subject and reason are required.'));
			}
			// The subject is a teammate — must resolve to a real member of
			// this workspace (§13.5 mass-assignment gate).
			$this->workspaces?->assertOwnerCandidate($workspaceId, $subject, 'subjectUserId');
			$companyId = isset($data['companyId']) ? (int)$data['companyId'] : 0;
			$dealId = isset($data['dealId']) ? (int)$data['dealId'] : 0;
			if ($companyId > 0) {
				$this->assertCompanyInWorkspace($workspaceId, $companyId);
			}
			if ($dealId > 0) {
				$this->assertDealInWorkspace($workspaceId, $dealId);
			}
			$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
			$ins = $this->db->getQueryBuilder();
			$ins->insert('crm_escal')->values([
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'subject_user_id' => $ins->createNamedParameter($subject),
				'owner_user_id' => $ins->createNamedParameter($actor),
				'reason' => $ins->createNamedParameter($reason),
				'status' => $ins->createNamedParameter('open'),
				'company_id' => $ins->createNamedParameter($companyId > 0 ? $companyId : null),
				'deal_id' => $ins->createNamedParameter($dealId > 0 ? $dealId : null),
				'created_by' => $ins->createNamedParameter($actor),
				'created_at' => $ins->createNamedParameter($now),
				'updated_at' => $ins->createNamedParameter($now),
			])->executeStatement();
			return ['id' => (int)$this->db->lastInsertId('crm_escal'), 'status' => 'open', 'subjectUserId' => $subject];
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Resolve open escalations whose subjects are no longer stuck.
	 *
	 * @param array<string, bool> $stuckByUser userId => stillStuck
	 * @return int resolved count
	 */
	public function autoResolve(int $workspaceId, array $stuckByUser): int
	{
		$n = 0;
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		foreach ($this->listOpen($workspaceId) as $esc) {
			$uid = (string)$esc['subjectUserId'];
			$stillStuck = !empty($stuckByUser[$uid]);
			if (!StuckLogic::shouldAutoResolve($stillStuck)) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('crm_escal')
				->set('status', $qb->createNamedParameter('resolved'))
				->set('resolved_at', $qb->createNamedParameter($now))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$esc['id'])))
				->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
				->executeStatement();
			$n++;
		}
		return $n;
	}

	private function assertCompanyInWorkspace(int $workspaceId, int $companyId): void
	{
		if (!$this->db->tableExists('crm_company')) {
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

	private function assertDealInWorkspace(int $workspaceId, int $dealId): void
	{
		if (!$this->db->tableExists('crm_deal') || !$this->db->tableExists('crm_company')) {
			throw new NotFoundException($this->l10n->t('That company is not in the current workspace.'));
		}
		$check = $this->db->getQueryBuilder();
		$check->select('d.id')
			->from('crm_deal', 'd')
			->innerJoin('d', 'crm_company', 'c', $check->expr()->eq('d.company_id', 'c.id'))
			->where($check->expr()->eq('d.id', $check->createNamedParameter($dealId)))
			->andWhere($check->expr()->eq('c.workspace_id', $check->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $check->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('That company is not in the current workspace.'));
		}
	}

	/** @param array<string, mixed> $row */
	private function map(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'subjectUserId' => (string)$row['subject_user_id'],
			'ownerUserId' => (string)$row['owner_user_id'],
			'reason' => (string)$row['reason'],
			'status' => (string)$row['status'],
			'companyId' => isset($row['company_id']) ? (int)$row['company_id'] : null,
			'dealId' => isset($row['deal_id']) ? (int)$row['deal_id'] : null,
		];
	}
}
