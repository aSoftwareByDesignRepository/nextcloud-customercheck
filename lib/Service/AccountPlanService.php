<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
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
		$existing = $this->forCompany($companyId);
		if ($existing !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('crm_acct_plan')
				->set('goal', $qb->createNamedParameter($goal !== '' ? $goal : null))
				->set('priority', $qb->createNamedParameter($priority))
				->set('owner_user_id', $qb->createNamedParameter($owner !== '' ? $owner : null))
				->set('horizon', $qb->createNamedParameter($horizon !== '' ? $horizon : null))
				->set('next_review_on', $qb->createNamedParameter($nextReview !== '' ? $nextReview : null))
				->set('status', $qb->createNamedParameter((string)($data['status'] ?? 'active')))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'])))
				->executeStatement();
			$plan = $this->forCompany($companyId);
			assert($plan !== null);
			return $plan;
		}
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
		$plan = $this->forCompany($companyId);
		assert($plan !== null);
		return $plan;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function addMapStep(int $planId, int $workspaceId, array $data): array
	{
		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a MAP step title.'));
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
			'openCallbacks' => 0,
			'renewalsHot' => false,
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
			'health' => $health,
			'mapSteps' => $this->mapSteps($planId),
			'milestones' => $this->milestones($planId),
		];
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
