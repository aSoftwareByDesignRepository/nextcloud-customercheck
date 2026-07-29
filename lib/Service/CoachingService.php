<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/** Team Coaching Board — product §13. */
class CoachingService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly WorkspaceService $workspaces,
		private readonly ChaseService $chase,
		private readonly PromiseService $promises,
		private readonly CoverageService $coverage,
		private readonly EscalationService $escalations,
		private readonly AccountPlanService $plans,
		private readonly OooCoverService $oooCover,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly \OCP\IUserManager $userManager,
	) {
	}

	/**
	 * @return array{rows: list<array<string, mixed>>, selfOnly: bool, escalations: list<array<string, mixed>>, summary: array{people: int, stuck: int}}
	 */
	public function board(int $workspaceId, string $userId, string $role, bool $isAppAdmin): array
	{
		if (!WorkspaceCapability::canOpenTeamBoard($role, $isAppAdmin)) {
			throw ValidationException::field(Constants::ERR_WS_ROLE, 'team', $this->l10n->t('Viewers cannot open the Team board.'));
		}
		$seeAll = WorkspaceCapability::teamSeesAllRows($role, $isAppAdmin);
		$members = $this->ensureViewerIsMember($workspaceId, $userId, $isAppAdmin);
		$today = $this->timeFactory->getDateTime();
		$plans = $this->plans->listForWorkspace($workspaceId);
		$rows = [];
		$stuckCount = 0;
		foreach ($members as $m) {
			$uid = (string)$m->getUserId();
			if (!$seeAll && $uid !== $userId) {
				continue;
			}
			// Stuck truth must use direct ownership — never inflate via OOO cover (§13.4).
			$chase = $this->chase->myChase($uid, $workspaceId, $today, false);
			$quoteOld = 0;
			$commitCents = 0;
			foreach ($chase as $d) {
				if (($d['quoteAgeDays'] ?? 0) >= 14) {
					$quoteOld++;
				}
				if (($d['forecastBucket'] ?? '') === 'commit') {
					$commitCents += max(0, (int)($d['valueCents'] ?? 0));
				}
			}
			$promises = $this->promises->dueSoon($workspaceId, $today, 0);
			$promiseOverdue = 0;
			foreach ($promises as $p) {
				if (($p['ownerUserId'] ?? '') === $uid) {
					$promiseOverdue++;
				}
			}
			$coverageGaps = 0;
			$planStalled = false;
			foreach ($this->coverage->myAccounts($uid, $workspaceId, false) as $acct) {
				if (!empty($acct['gap'])) {
					$coverageGaps++;
				}
			}
			foreach ($plans as $plan) {
				if (($plan['ownerUserId'] ?? '') === $uid && ($plan['health'] ?? '') === 'stalled') {
					$planStalled = true;
				}
			}
			$commitmentMissed = $this->countMissedCommitments($workspaceId, $uid, $today);
			$signals = [
				'chaseCount' => count($chase),
				'promiseOverdue' => $promiseOverdue,
				'planStalled' => $planStalled,
				'commitmentMissed' => $commitmentMissed,
			];
			$ooo = $this->activeOoo($workspaceId, $uid, $today);
			$stuck = StuckLogic::isStuck($signals);
			if ($stuck) {
				$stuckCount++;
			}
			$memberRole = (string)$m->getRole();
			$rows[] = [
				'userId' => $uid,
				'displayName' => $this->displayNameFor($uid),
				'role' => $memberRole,
				'roleLabel' => $this->roleLabel($memberRole),
				'stuck' => $stuck,
				'stuckScore' => StuckLogic::stuckScore($signals),
				'chase' => count($chase),
				'quote14' => $quoteOld,
				'coverageGaps' => $coverageGaps,
				'overdueWork' => $promiseOverdue,
				'commitCents' => $commitCents,
				'forecastDisclaimer' => $this->l10n->t('Planning labels — not expected cash.'),
				'ooo' => $ooo,
			];
		}
		return [
			'rows' => $rows,
			'selfOnly' => !$seeAll,
			'escalations' => $seeAll ? $this->escalations->listOpen($workspaceId) : [],
			'summary' => [
				'people' => count($rows),
				'stuck' => $stuckCount,
			],
		];
	}

	/**
	 * App admins can open Team without a membership row — then the board is empty forever.
	 * Heal only for app admins (idempotent addMember as manager). Never re-add a non-admin
	 * who was intentionally removed mid-request.
	 *
	 * @return list<\OCA\CustomerCheck\Db\WorkspaceMember>
	 */
	private function ensureViewerIsMember(int $workspaceId, string $userId, bool $isAppAdmin): array
	{
		$members = $this->workspaces->listMembers($workspaceId);
		foreach ($members as $m) {
			if ((string)$m->getUserId() === $userId) {
				return $members;
			}
		}
		if (!$isAppAdmin) {
			return $members;
		}
		$this->workspaces->addMember($workspaceId, $userId, 'manager', true, $userId);

		return $this->workspaces->listMembers($workspaceId);
	}

	private function displayNameFor(string $userId): string
	{
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return $userId;
		}
		$name = trim((string)$user->getDisplayName());

		return $name !== '' ? $name : $userId;
	}

	private function roleLabel(string $role): string
	{
		return match ($role) {
			'manager' => $this->l10n->t('Manager'),
			'member' => $this->l10n->t('Member'),
			'viewer' => $this->l10n->t('Viewer'),
			default => $role !== '' ? $role : $this->l10n->t('Member'),
		};
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function setOoo(int $workspaceId, array $data, string $actor): array
	{
		$primary = trim((string)($data['primaryUserId'] ?? ''));
		$backup = trim((string)($data['backupUserId'] ?? ''));
		$starts = new \DateTimeImmutable((string)($data['startsOn'] ?? ''));
		$ends = new \DateTimeImmutable((string)($data['endsOn'] ?? ''));
		$existing = $this->oooCover->listAll($workspaceId);
		$err = OooLogic::validate($primary, $backup, $starts, $ends, $existing);
		if ($err !== null) {
			throw ValidationException::field($err, 'ooo', $this->l10n->t('OOO cover could not be saved.'));
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_ooo')->values([
			'workspace_id' => $ins->createNamedParameter($workspaceId),
			'primary_user_id' => $ins->createNamedParameter($primary),
			'backup_user_id' => $ins->createNamedParameter($backup),
			'starts_on' => $ins->createNamedParameter($starts->format('Y-m-d')),
			'ends_on' => $ins->createNamedParameter($ends->format('Y-m-d')),
			'status' => $ins->createNamedParameter('scheduled'),
			'created_by' => $ins->createNamedParameter($actor),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();
		return ['id' => (int)$this->db->lastInsertId('crm_ooo'), 'primaryUserId' => $primary, 'backupUserId' => $backup];
	}

	/**
	 * Bulk reassign — atomic, ≤200, confirm by typing from-user name.
	 *
	 * Accepts either explicit id lists **or** `entityTypes` (deals/accounts/promises/commitments)
	 * which resolves owned open work for `$from` inside the workspace.
	 *
	 * @param array{
	 *   fromUserId?: string, toUserId?: string, confirmName?: string,
	 *   fromUser?: string, toUser?: string, confirmFromUserName?: string,
	 *   dealIds?: list<int>, companyIds?: list<int>, promiseIds?: list<int>, commitmentIds?: list<int>,
	 *   entityTypes?: list<string>
	 * } $data
	 */
	public function bulkReassign(int $workspaceId, array $data, string $actor): array
	{
		$from = trim((string)($data['fromUserId'] ?? $data['fromUser'] ?? ''));
		$to = trim((string)($data['toUserId'] ?? $data['toUser'] ?? ''));
		$confirm = trim((string)($data['confirmName'] ?? $data['confirmFromUserName'] ?? ''));
		if ($from === '' || $to === '' || !hash_equals($from, $confirm)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'confirmName', $this->l10n->t('Type the from-user id exactly to confirm.'));
		}
		$dealIds = array_values(array_map('intval', $data['dealIds'] ?? []));
		$companyIds = array_values(array_map('intval', $data['companyIds'] ?? []));
		$promiseIds = array_values(array_map('intval', $data['promiseIds'] ?? []));
		$commitmentIds = array_values(array_map('intval', $data['commitmentIds'] ?? []));
		$types = array_values(array_filter(array_map('strval', $data['entityTypes'] ?? [])));

		if ($types !== []) {
			if (in_array('deals', $types, true) || in_array('deal', $types, true)) {
				$dealIds = array_values(array_unique(array_merge($dealIds, $this->idsOwnedBy($workspaceId, 'crm_deal', 'owner_user_id', $from, true))));
			}
			if (in_array('accounts', $types, true) || in_array('account', $types, true) || in_array('companies', $types, true)) {
				$companyIds = array_values(array_unique(array_merge($companyIds, $this->idsOwnedBy($workspaceId, 'crm_company', 'account_owner_user_id', $from, false))));
			}
			if (in_array('promises', $types, true) || in_array('promise', $types, true)) {
				$promiseIds = array_values(array_unique(array_merge($promiseIds, $this->idsOwnedBy($workspaceId, 'crm_cust_prm', 'owner_user_id', $from, false, 'open'))));
			}
			if (in_array('commitments', $types, true) || in_array('commitment', $types, true)) {
				$commitmentIds = array_values(array_unique(array_merge($commitmentIds, $this->idsOwnedBy($workspaceId, 'crm_commit', 'owner_user_id', $from, false, null, ['suggested', 'committed', 'missed']))));
			}
		}

		$scopedDealIds = $this->filterDealIdsInWorkspace($workspaceId, $dealIds, $from);
		// Recompute total after ACL filter so cross-WS junk cannot pad the count.
		$total = count($scopedDealIds) + count($companyIds) + count($promiseIds) + count($commitmentIds);
		if ($total === 0 || $total > Constants::BULK_REASSIGN_MAX) {
			throw ValidationException::field(Constants::ERR_BULK_TOO_LARGE, 'bulk', $this->l10n->t('Select 1–200 items to reassign.'));
		}

		$this->db->beginTransaction();
		try {
			foreach ($scopedDealIds as $id) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('crm_deal')
					->set('owner_user_id', $qb->createNamedParameter($to))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
					->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($from)))
					->executeStatement();
			}
			foreach ($companyIds as $id) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('crm_company')
					->set('account_owner_user_id', $qb->createNamedParameter($to))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
					->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
					->andWhere($qb->expr()->eq('account_owner_user_id', $qb->createNamedParameter($from)))
					->executeStatement();
			}
			foreach ($promiseIds as $id) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('crm_cust_prm')
					->set('owner_user_id', $qb->createNamedParameter($to))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
					->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
					->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($from)))
					->executeStatement();
			}
			foreach ($commitmentIds as $id) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('crm_commit')
					->set('owner_user_id', $qb->createNamedParameter($to))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
					->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
					->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($from)))
					->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return [
			'reassigned' => $total,
			'fromUserId' => $from,
			'toUserId' => $to,
			'actor' => $actor,
			'counts' => [
				'deals' => count($scopedDealIds),
				'accounts' => count($companyIds),
				'promises' => count($promiseIds),
				'commitments' => count($commitmentIds),
			],
		];
	}

	/**
	 * Restrict explicit deal ids to this workspace + from-owner (ACL — no cross-WS injection).
	 *
	 * @param list<int> $dealIds
	 * @return list<int>
	 */
	private function filterDealIdsInWorkspace(int $workspaceId, array $dealIds, string $from): array
	{
		$dealIds = array_values(array_unique(array_filter(array_map('intval', $dealIds), static fn (int $id): bool => $id > 0)));
		if ($dealIds === [] || !$this->db->tableExists('crm_deal')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$params = [];
		foreach ($dealIds as $i => $id) {
			$params[] = $qb->createNamedParameter($id, null, 'did' . $i);
		}
		$qb->select('d.id')
			->from('crm_deal', 'd')
			->innerJoin('d', 'crm_company', 'c', $qb->expr()->eq('d.company_id', 'c.id'))
			->where($qb->expr()->eq('c.workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('d.owner_user_id', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->in('d.id', $params));
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$r->closeCursor();
		return $ids;
	}

	/** @return list<int>
	 */
	private function idsOwnedBy(
		int $workspaceId,
		string $table,
		string $ownerCol,
		string $from,
		bool $joinCompanyWorkspace,
		?string $statusEq = null,
		?array $statusIn = null,
	): array {
		if (!$this->db->tableExists($table)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		if ($joinCompanyWorkspace) {
			$qb->select('d.id')
				->from($table, 'd')
				->innerJoin('d', 'crm_company', 'c', $qb->expr()->eq('d.company_id', 'c.id'))
				->where($qb->expr()->eq('c.workspace_id', $qb->createNamedParameter($workspaceId)))
				->andWhere($qb->expr()->eq('d.' . $ownerCol, $qb->createNamedParameter($from)));
			if ($table === 'crm_deal') {
				$qb->andWhere($qb->expr()->neq('d.stage', $qb->createNamedParameter('won')))
					->andWhere($qb->expr()->neq('d.stage', $qb->createNamedParameter('lost')));
			}
		} else {
			$qb->select('id')->from($table)
				->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
				->andWhere($qb->expr()->eq($ownerCol, $qb->createNamedParameter($from)));
			if ($statusEq !== null) {
				$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($statusEq)));
			}
			if ($statusIn !== null && $statusIn !== []) {
				$params = [];
				foreach ($statusIn as $i => $st) {
					$params[] = $qb->createNamedParameter($st, null, 'st' . $i);
				}
				$qb->andWhere($qb->expr()->in('status', $params));
			}
		}
		$qb->setMaxResults(Constants::BULK_REASSIGN_MAX);
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$r->closeCursor();
		return $ids;
	}

	private function activeOoo(int $workspaceId, string $userId, \DateTimeInterface $today): ?array
	{
		foreach ($this->oooCover->listActive($workspaceId, $today) as $row) {
			if ($row['primaryUserId'] === $userId) {
				return $row + ['covering' => false];
			}
			if ($row['backupUserId'] === $userId) {
				return $row + ['covering' => true, 'coveringPrimary' => $row['primaryUserId']];
			}
		}
		return null;
	}

	private function countMissedCommitments(int $workspaceId, string $userId, \DateTimeInterface $today): int
	{
		if (!$this->db->tableExists('crm_commit')) {
			return 0;
		}
		$weekKey = BriefLogic::isoWeekKey((clone \DateTimeImmutable::createFromInterface($today))->modify('-7 days'));
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from('crm_commit')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('week_key', $qb->createNamedParameter($weekKey)))
			->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('missed')));
		$r = $qb->executeQuery();
		$n = (int)$r->fetchOne();
		$r->closeCursor();
		return $n;
	}
}
