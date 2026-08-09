<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Account plans + Strategy board + MAP steps — product §5.8 / §12.
 *
 * Health is deterministic (no AI): stalled / at_risk / on_track / unknown.
 */
class AccountPlanService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly CompanyService $companies,
		private readonly CoverageService $coverage,
		private readonly DisplayLabelService $labels,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function listForWorkspace(int $workspaceId): array
	{
		if (!$this->db->tableExists('crm_acct_plan')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_acct_plan')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->orderBy('priority', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(500);
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = $this->hydrate($row);
		}
		$r->closeCursor();
		return $out;
	}

	/** @return array<string, mixed>|null */
	public function forCompany(int $companyId): ?array
	{
		if (!$this->db->tableExists('crm_acct_plan')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_acct_plan')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		return $row === false ? null : $this->hydrate($row);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function upsert(int $companyId, array $data, string $actor): array
	{
		$company = $this->companies->get($companyId);
		$ws = (int)($company->getWorkspaceId() ?? 0);
		if ($ws <= 0) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'workspaceId', $this->l10n->t('Company has no workspace.'));
		}
		$goal = trim((string)($data['goal'] ?? ''));
		$priority = strtolower(trim((string)($data['priority'] ?? 'p2')));
		if (!in_array($priority, ['p1', 'p2', 'p3'], true)) {
			$priority = 'p2';
		}
		$owner = trim((string)($data['ownerUserId'] ?? $actor));
		$horizon = trim((string)($data['horizon'] ?? ''));
		$nextReview = trim((string)($data['nextReviewOn'] ?? ''));
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$status = (string)($data['status'] ?? 'active');
		$clientStamp = array_key_exists('updatedAt', $data) ? (string)$data['updatedAt'] : null;

		$this->db->beginTransaction();
		try {
			// Serialise concurrent first-creates (company → plan lock order).
			$this->companies->lockRow($companyId);
			$existing = $this->forCompany($companyId);
			if ($existing !== null) {
				$stored = null;
				if (!empty($existing['updatedAt'])) {
					try {
						$stored = new \DateTimeImmutable((string)$existing['updatedAt']);
					} catch (\Exception) {
						$stored = null;
					}
				}
				// Existing plans require If-Match stamp — closes Strategy LWW race (GNQ §6 light).
				OptimisticLock::assertFresh($stored, $clientStamp);
				$this->updatePlanRow(
					(int)$existing['id'],
					$goal,
					$priority,
					$owner,
					$horizon,
					$nextReview,
					$status,
					$now,
					(string)$existing['updatedAt'],
				);
			} else {
				try {
					$this->insertPlanRow($companyId, $ws, $goal, $priority, $owner, $horizon, $nextReview, $now);
				} catch (DbException $e) {
					if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
						throw $e;
					}
					// Peer won the insert — update that row instead of surfacing a raw SQL error.
					$existing = $this->forCompany($companyId);
					if ($existing === null) {
						throw $e;
					}
					OptimisticLock::assertFresh(
						!empty($existing['updatedAt']) ? new \DateTimeImmutable((string)$existing['updatedAt']) : null,
						$clientStamp,
						false,
					);
					$this->updatePlanRow(
						(int)$existing['id'],
						$goal,
						$priority,
						$owner,
						$horizon,
						$nextReview,
						$status,
						$now,
						!empty($existing['updatedAt']) ? (string)$existing['updatedAt'] : null,
					);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$plan = $this->forCompany($companyId);
		assert($plan !== null);
		return $plan;
	}

	private function updatePlanRow(
		int $planId,
		string $goal,
		string $priority,
		string $owner,
		string $horizon,
		string $nextReview,
		string $status,
		string $now,
		?string $expectedUpdatedAt = null,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_acct_plan')
			->set('goal', $qb->createNamedParameter($goal !== '' ? $goal : null))
			->set('priority', $qb->createNamedParameter($priority))
			->set('owner_user_id', $qb->createNamedParameter($owner !== '' ? $owner : null))
			->set('horizon', $qb->createNamedParameter($horizon !== '' ? $horizon : null))
			->set('next_review_on', $qb->createNamedParameter($nextReview !== '' ? $nextReview : null))
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($planId)));
		if ($expectedUpdatedAt !== null && trim($expectedUpdatedAt) !== '') {
			try {
				$sqlStamp = (new \DateTimeImmutable(trim($expectedUpdatedAt)))->format('Y-m-d H:i:s');
			} catch (\Exception) {
				$sqlStamp = trim($expectedUpdatedAt);
			}
			$qb->andWhere($qb->expr()->eq(
				'updated_at',
				$qb->createNamedParameter($sqlStamp),
			));
		}
		$affected = $qb->executeStatement();
		if ($affected < 1) {
			throw new ConflictException(
				$this->l10n->t('Someone else changed this account plan. Reload and try again.'),
			);
		}
	}

	private function insertPlanRow(
		int $companyId,
		int $ws,
		string $goal,
		string $priority,
		string $owner,
		string $horizon,
		string $nextReview,
		string $now,
	): void {
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_acct_plan')->values([
			'company_id' => $ins->createNamedParameter($companyId),
			'workspace_id' => $ins->createNamedParameter($ws),
			'horizon' => $ins->createNamedParameter($horizon !== '' ? $horizon : null),
			'goal' => $ins->createNamedParameter($goal !== '' ? $goal : null),
			'priority' => $ins->createNamedParameter($priority),
			'owner_user_id' => $ins->createNamedParameter($owner !== '' ? $owner : null),
			'status' => $ins->createNamedParameter('active'),
			'next_review_on' => $ins->createNamedParameter($nextReview !== '' ? $nextReview : null),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function addMapStep(int $planId, int $workspaceId, array $data): array
	{
		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a next step title.'));
		}
		$plan = $this->requirePlan($planId, $workspaceId);
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_map_step')->values([
			'plan_id' => $ins->createNamedParameter((int)$plan['id']),
			'title' => $ins->createNamedParameter($title),
			'status' => $ins->createNamedParameter('open'),
			'sort_order' => $ins->createNamedParameter((int)($data['sortOrder'] ?? 0)),
			'created_at' => $ins->createNamedParameter($now),
		])->executeStatement();
		return ['id' => (int)$this->db->lastInsertId('crm_map_step'), 'title' => $title, 'status' => 'open'];
	}

	/**
	 * Deterministic plan health — product §5.8.
	 *
	 * @param array{
	 *   nextReviewOn?: ?string,
	 *   touchOverdue?: bool,
	 *   openCallbacks?: int,
	 *   renewalsHot?: bool
	 * } $signals
	 */
	public static function health(array $signals): string
	{
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		$review = $signals['nextReviewOn'] ?? null;
		$reviewOverdue = is_string($review) && $review !== '' && $review < $today;
		if (!empty($signals['renewalsHot']) || $reviewOverdue || (int)($signals['openCallbacks'] ?? 0) > 0) {
			return 'at_risk';
		}
		if (!empty($signals['touchOverdue'])) {
			return 'stalled';
		}
		if ($review === null || $review === '') {
			return 'unknown';
		}
		return 'on_track';
	}

	/** @param array<string, mixed> $row */
	private function hydrate(array $row): array
	{
		$planId = (int)$row['id'];
		$companyId = (int)$row['company_id'];
		$name = '';
		$touchOverdue = false;
		try {
			$co = $this->companies->get($companyId);
			$name = (string)$co->getName();
			$cov = $this->coverage->forCompany($companyId);
			$touchOverdue = in_array('touch_overdue', $cov['flags'] ?? [], true);
		} catch (\Throwable) {
		}
		$health = self::health([
			'nextReviewOn' => $row['next_review_on'] ?? null,
			'touchOverdue' => $touchOverdue,
			'openCallbacks' => $this->countOpenCallbacksOverdue($companyId),
			'renewalsHot' => $this->hasRenewalHot($companyId),
		]);
		return [
			'id' => $planId,
			'companyId' => $companyId,
			'companyName' => $name,
			'workspaceId' => (int)$row['workspace_id'],
			'horizon' => $row['horizon'] ?? null,
			'goal' => $row['goal'] ?? null,
			'priority' => (string)$row['priority'],
			'ownerUserId' => $row['owner_user_id'] ?? null,
			'status' => (string)$row['status'],
			'nextReviewOn' => $row['next_review_on'] ?? null,
			'updatedAt' => isset($row['updated_at']) && $row['updated_at'] !== null && (string)$row['updated_at'] !== ''
				? OptimisticLock::format(new \DateTimeImmutable((string)$row['updated_at']))
				: null,
			'health' => $health,
			'healthLabel' => $this->labels->planHealth($health),
			'mapSteps' => $this->mapSteps($planId),
			'milestones' => $this->milestones($planId),
		];
	}

	private function countOpenCallbacksOverdue(int $companyId): int
	{
		if ($companyId <= 0 || !$this->db->tableExists('crm_activity')) {
			return 0;
		}
		$todayYmd = $this->timeFactory->getDateTime()->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'cnt')
			->from('crm_activity')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->andWhere($qb->expr()->eq('is_callback', $qb->createNamedParameter(1)))
			->andWhere($qb->expr()->isNull('done_at'))
			->andWhere($qb->expr()->isNotNull('due_date'))
			->andWhere($qb->expr()->lt('due_date', $qb->createNamedParameter($todayYmd)));
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}

	private function hasRenewalHot(int $companyId): bool
	{
		if ($companyId <= 0 || !$this->db->tableExists('crm_agreement')) {
			return false;
		}
		$today = $this->timeFactory->getDateTime();
		$todayYmd = $today->format('Y-m-d');
		$horizon = (clone \DateTimeImmutable::createFromInterface($today))
			->modify('+7 days')
			->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$terminal = [];
		foreach (Constants::AGREEMENT_TERMINAL_STATUSES as $i => $status) {
			$terminal[] = $qb->createNamedParameter($status, null, 'term' . $i);
		}
		$qb->select($qb->createFunction('1'))
			->from('crm_agreement')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->andWhere($qb->expr()->isNotNull('valid_until'))
			->andWhere($qb->expr()->gte('valid_until', $qb->createNamedParameter($todayYmd)))
			->andWhere($qb->expr()->lte('valid_until', $qb->createNamedParameter($horizon)))
			->andWhere($qb->expr()->notIn('status', $terminal))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();

		return $row !== false;
	}

	/** @return list<array<string, mixed>> */
	private function mapSteps(int $planId): array
	{
		if (!$this->db->tableExists('crm_map_step')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_map_step')
			->where($qb->expr()->eq('plan_id', $qb->createNamedParameter($planId)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'title' => (string)$row['title'],
				'status' => (string)$row['status'],
				'statusLabel' => $this->labels->mapStepStatus((string)$row['status']),
				'sortOrder' => (int)$row['sort_order'],
			];
		}
		$r->closeCursor();
		return $out;
	}

	/** @return list<array<string, mixed>> */
	private function milestones(int $planId): array
	{
		if (!$this->db->tableExists('crm_plan_ms')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_plan_ms')
			->where($qb->expr()->eq('plan_id', $qb->createNamedParameter($planId)))
			->orderBy('sort_order', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'title' => (string)$row['title'],
				'dueOn' => $row['due_on'] ?? null,
				'doneAt' => $row['done_at'] ?? null,
			];
		}
		$r->closeCursor();
		return $out;
	}

	/** @return array<string, mixed> */
	private function requirePlan(int $planId, int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_acct_plan')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($planId)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw ValidationException::field(Constants::ERR_NOT_FOUND, 'planId', $this->l10n->t('Account plan not found.'));
		}
		return $this->hydrate($row);
	}
}
