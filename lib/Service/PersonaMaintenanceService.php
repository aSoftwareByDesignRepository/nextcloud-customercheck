<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * OOO activation + commitment auto-verify + escalation auto-resolve.
 * Product §10.3 / §13.3 / §13.4 / §17.1 background jobs.
 */
class PersonaMaintenanceService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly WorkspaceService $workspaces,
		private readonly EscalationService $escalations,
		private readonly ChaseService $chase,
		private readonly PromiseService $promises,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{oooActivated: int, oooEnded: int, escalationsResolved: int, commitsAutoDone: int}
	 */
	public function run(): array
	{
		$today = $this->timeFactory->getDateTime();
		$ooo = $this->syncOooStatuses($today);
		$esc = 0;
		$autoDone = 0;
		foreach ($this->workspaces->listAll() as $ws) {
			if ($ws->isArchived()) {
				continue;
			}
			$wsId = (int)$ws->getId();
			try {
				$stuckByUser = $this->stuckMap($wsId, $today);
				$esc += $this->escalations->autoResolve($wsId, $stuckByUser);
				$autoDone += $this->autoVerifyCommitments($wsId, $today);
			} catch (\Throwable $e) {
				$this->logger->warning('CustomerCheck persona maintenance failed for workspace', [
					'workspaceId' => $wsId,
					'exception' => $e,
				]);
			}
		}
		return [
			'oooActivated' => $ooo['activated'],
			'oooEnded' => $ooo['ended'],
			'escalationsResolved' => $esc,
			'commitsAutoDone' => $autoDone,
		];
	}

	/**
	 * @return array{activated: int, ended: int}
	 */
	private function syncOooStatuses(\DateTimeInterface $today): array
	{
		if (!$this->db->tableExists('crm_ooo')) {
			return ['activated' => 0, 'ended' => 0];
		}
		$t = $today->format('Y-m-d');
		$now = $today->format('Y-m-d H:i:s');
		$activated = 0;
		$ended = 0;

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'starts_on', 'ends_on', 'status')->from('crm_ooo');
		$r = $qb->executeQuery();
		while ($row = $r->fetch()) {
			$id = (int)$row['id'];
			$starts = (string)$row['starts_on'];
			$ends = (string)$row['ends_on'];
			$status = (string)$row['status'];
			$active = $t >= $starts && $t <= $ends;
			if ($active && $status !== 'active') {
				$u = $this->db->getQueryBuilder();
				$u->update('crm_ooo')
					->set('status', $u->createNamedParameter('active'))
					->set('updated_at', $u->createNamedParameter($now))
					->where($u->expr()->eq('id', $u->createNamedParameter($id)))
					->executeStatement();
				$activated++;
			} elseif (!$active && $status === 'active') {
				$u = $this->db->getQueryBuilder();
				$u->update('crm_ooo')
					->set('status', $u->createNamedParameter('ended'))
					->set('updated_at', $u->createNamedParameter($now))
					->where($u->expr()->eq('id', $u->createNamedParameter($id)))
					->executeStatement();
				$ended++;
			} elseif (!$active && $status === 'scheduled' && $t > $ends) {
				$u = $this->db->getQueryBuilder();
				$u->update('crm_ooo')
					->set('status', $u->createNamedParameter('ended'))
					->set('updated_at', $u->createNamedParameter($now))
					->where($u->expr()->eq('id', $u->createNamedParameter($id)))
					->executeStatement();
				$ended++;
			}
		}
		$r->closeCursor();
		return ['activated' => $activated, 'ended' => $ended];
	}

	/**
	 * @return array<string, bool>
	 */
	private function stuckMap(int $workspaceId, \DateTimeInterface $today): array
	{
		$map = [];
		foreach ($this->workspaces->listMembers($workspaceId) as $m) {
			$uid = (string)$m->getUserId();
			$chase = $this->chase->myChase($uid, $workspaceId, $today);
			$promiseOverdue = 0;
			foreach ($this->promises->dueSoon($workspaceId, $today, 0) as $p) {
				if (($p['ownerUserId'] ?? '') === $uid) {
					$promiseOverdue++;
				}
			}
			$map[$uid] = StuckLogic::isStuck([
				'chaseCount' => count($chase),
				'promiseOverdue' => $promiseOverdue,
				'planStalled' => false,
				'commitmentMissed' => 0,
			]);
		}
		return $map;
	}

	/**
	 * Auto-mark committed items done when evidence is present (activity done /
	 * manager already verified). Conservative: only kinds with clear CRM evidence.
	 */
	private function autoVerifyCommitments(int $workspaceId, \DateTimeInterface $today): int
	{
		if (!$this->db->tableExists('crm_commit')) {
			return 0;
		}
		$weekKey = BriefLogic::isoWeekKey($today);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_commit')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('week_key', $qb->createNamedParameter($weekKey)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('committed')));
		$r = $qb->executeQuery();
		$n = 0;
		$now = $today->format('Y-m-d H:i:s');
		while ($row = $r->fetch()) {
			$kind = (string)$row['kind'];
			$companyId = isset($row['company_id']) ? (int)$row['company_id'] : 0;
			$evidence = null;
			if ($kind === 'link_debt' && $companyId > 0) {
				// Evidence: company now has PC+Files linked — checked lightly via columns.
				$cq = $this->db->getQueryBuilder();
				$cq->select('pc_customer_id', 'files_folder_id')->from('crm_company')
					->where($cq->expr()->eq('id', $cq->createNamedParameter($companyId)))
					->setMaxResults(1);
				$cr = $cq->executeQuery();
				$co = $cr->fetch();
				$cr->closeCursor();
				if ($co && (int)($co['pc_customer_id'] ?? 0) > 0 && (int)($co['files_folder_id'] ?? 0) > 0) {
					$evidence = 'links_fixed';
				}
			}
			if ($evidence === null) {
				continue;
			}
			$u = $this->db->getQueryBuilder();
			$u->update('crm_commit')
				->set('status', $u->createNamedParameter('done'))
				->set('evidence', $u->createNamedParameter($evidence))
				->set('updated_at', $u->createNamedParameter($now))
				->where($u->expr()->eq('id', $u->createNamedParameter((int)$row['id'])))
				->executeStatement();
			$n++;
		}
		$r->closeCursor();
		return $n;
	}
}
