<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;

/**
 * Weekly Brief + CEO commitments — product §10.
 */
class BriefService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly WorkspaceService $workspaces,
		private readonly PulseService $pulse,
		private readonly MoneyService $money,
		private readonly AgreementRenewalMoney $agreements,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(int $workspaceId, ?\DateTimeInterface $today = null): array
	{
		$today = $today ?? $this->timeFactory->getDateTime();
		$weekKey = BriefLogic::isoWeekKey($today);
		$settings = $this->workspaces->getSettings($workspaceId);
		$wipLimit = $settings['wipLimit'];

		$commits = $this->listCommitments($workspaceId, $weekKey);
		$lastWeekKey = BriefLogic::isoWeekKey((clone \DateTimeImmutable::createFromInterface($today))->modify('-7 days'));
		$lastWeek = $this->listCommitments($workspaceId, $lastWeekKey);

		$committedWip = 0;
		foreach ($commits as $c) {
			if (($c['status'] === 'committed' || $c['status'] === 'done') && !empty($c['countsWip'])) {
				$committedWip++;
			}
		}

		$pulse = $this->pulse->build($workspaceId, $today);
		$honestyBlocked = (($pulse['metrics']['unbilled']['state'] ?? '') === 'honesty_blocked');

		$moneyDto = $this->money->overview(200, null, $workspaceId);
		$overdue = 0;
		foreach ($moneyDto['groups']['overdue'] ?? [] as $row) {
			$overdue += max(0, (int)($row['amountCents'] ?? 0));
		}
		// Open AR not yet overdue (sent / due this month) — never invent pipeline cash (§10.4).
		$arDueThisMonth = 0;
		$monthPrefix = $today->format('Y-m');
		foreach ($moneyDto['groups']['sent'] ?? [] as $row) {
			$cents = max(0, (int)($row['amountCents'] ?? 0));
			$due = isset($row['dueDate']) && is_string($row['dueDate']) ? substr($row['dueDate'], 0, 7) : null;
			if ($due === null || $due === '' || $due === $monthPrefix) {
				$arDueThisMonth += $cents;
			}
		}
		$nextMonth = \DateTimeImmutable::createFromInterface($today)->modify('first day of next month');
		$arDueNextMonth = 0;
		$nextPrefix = $nextMonth->format('Y-m');
		foreach ($moneyDto['groups']['sent'] ?? [] as $row) {
			$cents = max(0, (int)($row['amountCents'] ?? 0));
			$due = isset($row['dueDate']) && is_string($row['dueDate']) ? substr($row['dueDate'], 0, 7) : null;
			if ($due === $nextPrefix) {
				$arDueNextMonth += $cents;
			}
		}
		$cash = BriefLogic::cashCalendar([
			'overdueArCents' => $overdue,
			'arDueThisMonthCents' => $arDueThisMonth,
			'arDueNextMonthCents' => $arDueNextMonth,
			'renewalsThisMonthCents' => $this->agreements->renewalsKnownCentsThisMonth($workspaceId, $today),
			'renewalsNextMonthCents' => $this->agreements->renewalsKnownCentsForMonth($workspaceId, $nextMonth),
			'pipelineCents' => (int)($pulse['metrics']['pipelineCents'] ?? 0),
			'forecastCommitCents' => 0,
		]);

		$suggestedKinds = BriefLogic::filterSuggestions(
			['ar_chase', 'renewal_act', 'link_debt', 'unbilled_review', 'plan_push'],
			$honestyBlocked,
		);

		return [
			'workspaceId' => $workspaceId,
			'weekKey' => $weekKey,
			'wipLimit' => $wipLimit,
			'wipUsed' => $committedWip,
			'commitments' => $commits,
			'lastWeek' => $lastWeek,
			'cash' => $cash,
			'suggestedKinds' => $suggestedKinds,
			'honestyBlocked' => $honestyBlocked,
			'forecastDisclaimer' => $this->l10n->t('Planning labels — not expected cash.'),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listCommitments(int $workspaceId, string $weekKey): array
	{
		if (!$this->db->tableExists('crm_commit')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('crm_commit')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('week_key', $qb->createNamedParameter($weekKey)))
			->orderBy('id', 'ASC');
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = $this->mapCommit($row);
		}
		$r->closeCursor();
		return $out;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function confirm(int $workspaceId, array $data, string $actor): array
	{
		if ($workspaceId <= 0) {
			throw new NotFoundException($this->l10n->t('Workspace not found.'));
		}

		$lockKey = 'crm_brief_wip/' . $workspaceId;
		$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$settings = $this->workspaces->getSettings($workspaceId);
			$weekKey = (string)($data['weekKey'] ?? BriefLogic::isoWeekKey($this->timeFactory->getDateTime()));
			$commits = $this->listCommitments($workspaceId, $weekKey);
			$wip = 0;
			foreach ($commits as $c) {
				if ($c['status'] === 'committed' && !empty($c['countsWip'])) {
					$wip++;
				}
			}
			if (!BriefLogic::canConfirm($wip, $settings['wipLimit'])) {
				throw ValidationException::field(
					Constants::ERR_WIP_FULL,
					'wip',
					$this->l10n->t('Your week is full. Finish or drop a promise first.'),
				);
			}

			$kind = (string)($data['kind'] ?? 'custom');
			if (!in_array($kind, Constants::COMMIT_KINDS, true)) {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'kind', $this->l10n->t('Unknown commitment kind.'));
			}
			$title = trim((string)($data['title'] ?? ''));
			if ($title === '') {
				throw ValidationException::field(Constants::ERR_VALIDATION, 'title', $this->l10n->t('Enter a commitment title.'));
			}
			$companyId = isset($data['companyId']) ? (int)$data['companyId'] : 0;
			if ($companyId > 0) {
				$this->assertCompanyInWorkspace($workspaceId, $companyId);
			} else {
				$companyId = 0;
			}
			$owner = trim((string)($data['ownerUserId'] ?? $actor));
			$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
			$ins = $this->db->getQueryBuilder();
			$ins->insert('crm_commit')->values([
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'week_key' => $ins->createNamedParameter($weekKey),
				'kind' => $ins->createNamedParameter($kind),
				'title' => $ins->createNamedParameter($title),
				'company_id' => $ins->createNamedParameter($companyId > 0 ? $companyId : null),
				'owner_user_id' => $ins->createNamedParameter($owner),
				'status' => $ins->createNamedParameter('committed'),
				'due_on' => $ins->createNamedParameter($data['dueOn'] ?? null),
				'carry_streak' => $ins->createNamedParameter(0),
				'counts_wip' => $ins->createNamedParameter(1),
				'created_by' => $ins->createNamedParameter($actor),
				'created_at' => $ins->createNamedParameter($now),
				'updated_at' => $ins->createNamedParameter($now),
			])->executeStatement();

			$id = (int)$this->db->lastInsertId('crm_commit');
			return ['id' => $id, 'status' => 'committed', 'weekKey' => $weekKey];
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Fail closed when a commitment tries to attach a foreign-book company.
	 */
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

	public function verify(
		int $id,
		int $workspaceId,
		string $action,
		string $actor,
		?string $note = null,
		?string $evidence = null,
		string $actorRole = 'manager',
	): void {
		$row = $this->getCommit($id, $workspaceId);
		$status = (string)$row['status'];
		$carry = (int)$row['carry_streak'];
		$owner = (string)($row['owner_user_id'] ?? '');
		$role = WorkspaceCapability::normalizeRole($actorRole);

		// Terminal rows are closed — re-verify would overwrite the audit trail.
		if (!in_array($status, ['committed', 'carried', 'missed'], true)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'id',
				$this->l10n->t('This commitment is already closed. Reload the Brief.'),
			);
		}

		// Members: limited verify — own commitments only; no manager_override (§3.2).
		if ($role === 'member') {
			if ($owner !== '' && $owner !== $actor) {
				throw ValidationException::field(
					Constants::ERR_ACCESS_DENIED,
					'id',
					$this->l10n->t('You can only verify your own commitments.'),
				);
			}
			if (strtolower(trim((string)$evidence)) === 'manager_override') {
				throw ValidationException::field(
					Constants::ERR_ACCESS_DENIED,
					'evidence',
					$this->l10n->t('Manager override requires a workspace manager.'),
				);
			}
		}

		$evidenceCode = null;
		if ($action === 'done') {
			if (BriefLogic::validateDoneEvidence($evidence, $note) !== null) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'evidence',
					$this->l10n->t('Marking done requires evidence (invoice paid, activity done, links fixed, plan healthy, or manager override with a reason).'),
				);
			}
			$evidenceCode = strtolower(trim((string)$evidence));
		}
		$new = match ($action) {
			'done' => 'done',
			'missed' => 'missed',
			'abandon' => 'abandoned',
			'carry' => BriefLogic::canCarry($carry) ? 'carried' : 'abandoned',
			default => throw ValidationException::field(Constants::ERR_VALIDATION, 'action', $this->l10n->t('Unknown verify action.')),
		};
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_commit')
			->set('status', $qb->createNamedParameter($new))
			->set('verify_note', $qb->createNamedParameter($note))
			->set('updated_at', $qb->createNamedParameter($this->timeFactory->getDateTime()->format('Y-m-d H:i:s')));
		if ($evidenceCode !== null) {
			$qb->set('evidence', $qb->createNamedParameter($evidenceCode));
		}
		if ($action === 'carry' && $new === 'carried') {
			$qb->set('carry_streak', $qb->createNamedParameter($carry + 1));
		}
		// Status CAS: concurrent verifies with different outcomes cannot both win.
		$affected = $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->executeStatement();
		if ($affected !== 1) {
			throw new ConflictException(
				$this->l10n->t('Someone else just updated this commitment. Reload and try again.'),
			);
		}
		unset($actor);
	}

	/** @return array<string, mixed> */
	public function getCommitment(int $id, int $workspaceId): array
	{
		return $this->mapCommit($this->getCommit($id, $workspaceId));
	}

	/** @return array<string, mixed> */
	private function getCommit(int $id, int $workspaceId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_commit')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw ValidationException::field(Constants::ERR_NOT_FOUND, 'id', $this->l10n->t('Commitment not found.'));
		}
		return $row;
	}

	/** @param array<string, mixed> $row */
	private function mapCommit(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'weekKey' => (string)$row['week_key'],
			'kind' => (string)$row['kind'],
			'title' => (string)$row['title'],
			'companyId' => isset($row['company_id']) ? (int)$row['company_id'] : null,
			'ownerUserId' => (string)$row['owner_user_id'],
			'status' => (string)$row['status'],
			'dueOn' => $row['due_on'] ?? null,
			'carryStreak' => (int)$row['carry_streak'],
			'countsWip' => (int)$row['counts_wip'] === 1,
			'evidence' => isset($row['evidence']) && $row['evidence'] !== null && $row['evidence'] !== ''
				? (string)$row['evidence']
				: null,
		];
	}
}
