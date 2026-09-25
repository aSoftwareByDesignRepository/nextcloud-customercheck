<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DateMath;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
		private readonly DirectoryOptionsService $directory,
		private readonly ?WorkspaceService $workspaces = null,
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
		$this->sortPlansForBoard($out);
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
	public function upsert(int $companyId, array $data, string $actor, bool $canReassignOwners = false): array
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
		if (!$canReassignOwners) {
			// Same strip the deal path applies — without the reassign
			// capability the owner is always the actor (§13.5).
			$owner = $actor;
		} elseif ($owner !== '' && $owner !== $actor) {
			$this->workspaces?->assertOwnerCandidate($ws, $owner, 'ownerUserId');
		}
		$horizon = trim((string)($data['horizon'] ?? ''));
		$nextAssignRaw = trim((string)($data['nextAssignOn'] ?? ''));
		$nextAssign = '';
		if ($nextAssignRaw !== '') {
			$parsedReview = self::calendarDayFromStorage($nextAssignRaw);
			if ($parsedReview === null) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'nextAssignOn',
					$this->l10n->t('Use YYYY-MM-DD.'),
				);
			}
			$nextAssign = $parsedReview;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$status = $this->normalizePlanStatus(
			array_key_exists('status', $data) ? (string)$data['status'] : 'active',
		);
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
					$nextAssign,
					$status,
					$now,
					(string)$existing['updatedAt'],
				);
			} else {
				try {
					$this->insertPlanRow($companyId, $ws, $goal, $priority, $owner, $horizon, $nextAssign, $status, $now);
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
						$nextAssign,
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
		string $nextAssign,
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
			->set('next_review_on', $qb->createNamedParameter($nextAssign !== '' ? $nextAssign : null))
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($planId)));
		if ($expectedUpdatedAt !== null && trim($expectedUpdatedAt) !== '') {
			$sqlStamp = self::stampToWallSql(trim($expectedUpdatedAt));
			if ($sqlStamp === null) {
				$sqlStamp = trim($expectedUpdatedAt);
			}
			$qb->andWhere($qb->expr()->eq(
				'updated_at',
				$qb->createNamedParameter($sqlStamp),
			));
		}
		$affected = $qb->executeStatement();
		if ($affected >= 1) {
			return;
		}
		// MySQL reports 0 when every SET value already matches. A matching stamp
		// means this writer still owns the row — do not fake a 409.
		if ($expectedUpdatedAt !== null && trim($expectedUpdatedAt) !== '') {
			$stored = $this->fetchPlanUpdatedAt($planId);
			$expectedSql = self::stampToWallSql(trim($expectedUpdatedAt));
			$storedSql = $stored !== null ? self::stampToWallSql($stored) : null;
			if ($expectedSql !== null && $expectedSql === $storedSql) {
				return;
			}
		}
		throw new ConflictException(
			$this->l10n->t('Someone else changed this account plan. Reload and try again.'),
		);
	}

	private function fetchPlanUpdatedAt(int $planId): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('updated_at')
			->from('crm_acct_plan')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($planId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if (!is_array($row) || !array_key_exists('updated_at', $row) || $row['updated_at'] === null) {
			return null;
		}

		return trim((string)$row['updated_at']) !== '' ? (string)$row['updated_at'] : null;
	}

	public static function stampToWallSql(string $value): ?string
	{
		try {
			return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
		} catch (\Exception) {
			return null;
		}
	}

	/**
	 * Wire and storage dates are calendar days (`YYYY-MM-DD`). PDO/DATE drivers
	 * sometimes hand back DateTime or `YYYY-MM-DD 00:00:00` — never send that
	 * to a type=date / datepicker, and never persist European `dd.mm.yyyy`.
	 */
	public static function calendarDayFromStorage(mixed $value): ?string
	{
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d');
		}
		if ($value === null) {
			return null;
		}
		$raw = trim((string)$value);
		if ($raw === '') {
			return null;
		}
		if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) === 1) {
			return DateMath::parseStrictDate($m[1]) !== null ? $m[1] : null;
		}

		return DateMath::parseStrictDate($raw)?->format('Y-m-d');
	}

	private function insertPlanRow(
		int $companyId,
		int $ws,
		string $goal,
		string $priority,
		string $owner,
		string $horizon,
		string $nextAssign,
		string $status,
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
			'status' => $ins->createNamedParameter($status),
			'next_review_on' => $ins->createNamedParameter($nextAssign !== '' ? $nextAssign : null),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();
	}

	private function normalizePlanStatus(string $raw): string
	{
		$status = strtolower(trim($raw));
		if ($status === '') {
			$status = 'active';
		}
		if (!in_array($status, ['active', 'paused', 'done'], true)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'status',
				$this->l10n->t('Unknown plan status.'),
			);
		}
		return $status;
	}

	/**
	 * Strategy board order: needs attention first, then priority, then review date, then name.
	 *
	 * @param list<array<string, mixed>> $plans
	 */
	private function sortPlansForBoard(array &$plans): void
	{
		usort($plans, static function (array $a, array $b): int {
			$healthRank = static function (array $p): int {
				return match ((string)($p['health'] ?? '')) {
					'at_risk' => 0,
					'stalled' => 1,
					'unknown' => 2,
					'on_track' => 3,
					default => 4,
				};
			};
			$ha = $healthRank($a);
			$hb = $healthRank($b);
			if ($ha !== $hb) {
				return $ha <=> $hb;
			}
			$pa = (string)($a['priority'] ?? 'p2');
			$pb = (string)($b['priority'] ?? 'p2');
			if ($pa !== $pb) {
				return $pa <=> $pb;
			}
			$ra = (string)($a['nextAssignOn'] ?? '');
			$rb = (string)($b['nextAssignOn'] ?? '');
			if ($ra !== '' && $rb !== '' && $ra !== $rb) {
				return $ra <=> $rb;
			}
			if ($ra !== $rb) {
				// Dated reviews before undated.
				return $ra === '' ? 1 : -1;
			}
			return strcasecmp((string)($a['companyName'] ?? ''), (string)($b['companyName'] ?? ''));
		});
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function addMapStep(int $planId, int $workspaceId, array $data): array
	{
		$title = $this->normalizeMapStepTitle((string)($data['title'] ?? ''));
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
		return [
			'id' => (int)$this->db->lastInsertId('crm_map_step'),
			'title' => $title,
			'status' => 'open',
			'statusLabel' => $this->labels->mapStepStatus('open'),
		];
	}

	/**
	 * Mark a plan move done (or reopen). Status is validated against the product enum.
	 *
	 * @return array<string, mixed>
	 */
	public function setMapStepStatus(int $companyId, int $stepId, int $workspaceId, string $status): array
	{
		$status = strtolower(trim($status));
		if (!in_array($status, ['open', 'done', 'blocked', 'skipped'], true)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'status', $this->l10n->t('Unknown step status.'));
		}
		$plan = $this->forCompany($companyId);
		if ($plan === null) {
			throw ValidationException::field(Constants::ERR_NOT_FOUND, 'planId', $this->l10n->t('Account plan not found.'));
		}
		$this->requirePlan((int)$plan['id'], $workspaceId);
		if (!$this->db->tableExists('crm_map_step')) {
			throw ValidationException::field(Constants::ERR_NOT_FOUND, 'id', $this->l10n->t('Next step not found.'));
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_map_step')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($stepId)))
			->andWhere($qb->expr()->eq('plan_id', $qb->createNamedParameter((int)$plan['id'])))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw ValidationException::field(Constants::ERR_NOT_FOUND, 'id', $this->l10n->t('Next step not found.'));
		}
		$upd = $this->db->getQueryBuilder();
		$upd->update('crm_map_step')
			->set('status', $upd->createNamedParameter($status))
			->where($upd->expr()->eq('id', $upd->createNamedParameter($stepId)))
			->andWhere($upd->expr()->eq('plan_id', $upd->createNamedParameter((int)$plan['id'])))
			->executeStatement();
		return [
			'id' => $stepId,
			'title' => (string)$row['title'],
			'status' => $status,
			'statusLabel' => $this->labels->mapStepStatus($status),
		];
	}

	private function normalizeMapStepTitle(string $raw): string
	{
		$collapsed = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
		if (mb_strlen($collapsed) > 255) {
			$collapsed = mb_substr($collapsed, 0, 255);
		}
		return $collapsed;
	}

	/**
	 * Deterministic plan health — product §5.8.
	 *
	 * @param array{
	 *   nextAssignOn?: ?string,
	 *   touchOverdue?: bool,
	 *   openCallbacks?: int,
	 *   renewalsHot?: bool
	 * } $signals
	 */
	public static function health(array $signals): string
	{
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		$review = $signals['nextAssignOn'] ?? null;
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
			'nextAssignOn' => self::calendarDayFromStorage($row['next_review_on'] ?? null),
			'touchOverdue' => $touchOverdue,
			'openCallbacks' => $this->countOpenCallbacksOverdue($companyId),
			'renewalsHot' => $this->hasRenewalHot($companyId),
		]);
		$priority = (string)$row['priority'];
		$status = (string)$row['status'];
		$mapSteps = $this->mapSteps($planId);
		$openMoves = 0;
		foreach ($mapSteps as $step) {
			if (($step['status'] ?? '') === 'open') {
				$openMoves++;
			}
		}
		$ownerUid = isset($row['owner_user_id']) && $row['owner_user_id'] !== null
			? trim((string)$row['owner_user_id'])
			: '';
		$ownerDisplayName = null;
		if ($ownerUid !== '') {
			foreach ($this->directory->resolveUsers([$ownerUid]) as $resolved) {
				$ownerDisplayName = (string)($resolved['displayName'] ?? $ownerUid);
				break;
			}
			if ($ownerDisplayName === null || $ownerDisplayName === '') {
				$ownerDisplayName = $ownerUid;
			}
		}

		return [
			'id' => $planId,
			'companyId' => $companyId,
			'companyName' => $name,
			'workspaceId' => (int)$row['workspace_id'],
			'horizon' => $row['horizon'] ?? null,
			'goal' => $row['goal'] ?? null,
			'priority' => $priority,
			'priorityLabel' => $this->labels->planPriority($priority),
			'ownerUserId' => $ownerUid !== '' ? $ownerUid : null,
			'ownerDisplayName' => $ownerDisplayName,
			'status' => $status,
			'statusLabel' => $this->labels->planStatus($status),
			'nextAssignOn' => self::calendarDayFromStorage($row['next_review_on'] ?? null),
			'updatedAt' => isset($row['updated_at']) && $row['updated_at'] !== null && (string)$row['updated_at'] !== ''
				? OptimisticLock::format(new \DateTimeImmutable((string)$row['updated_at']))
				: null,
			'health' => $health,
			'healthLabel' => $this->labels->planHealth($health),
			'openMoves' => $openMoves,
			'mapSteps' => $mapSteps,
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
		$qb->select($qb->createFunction('1'))
			->from('crm_agreement')
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId)))
			->andWhere($qb->expr()->isNotNull('valid_until'))
			->andWhere($qb->expr()->gte('valid_until', $qb->createNamedParameter($todayYmd)))
			->andWhere($qb->expr()->lte('valid_until', $qb->createNamedParameter($horizon)))
			->andWhere($qb->expr()->notIn(
				'status',
				$qb->createNamedParameter(Constants::AGREEMENT_TERMINAL_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)
			))
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
