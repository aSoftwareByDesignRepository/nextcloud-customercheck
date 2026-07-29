<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Workspace CRUD + membership — product §6.
 */
class WorkspaceService
{
	public function __construct(
		private readonly WorkspaceMapper $workspaces,
		private readonly WorkspaceMemberMapper $members,
		private readonly AccessControlService $access,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
	) {
	}

	/** @return Workspace[] */
	public function listForUser(string $userId): array
	{
		if ($this->access->isAppAdmin($userId)) {
			return $this->workspaces->findAll();
		}
		return $this->workspaces->findForUser($userId);
	}

	/** @return Workspace[] */
	public function listAll(): array
	{
		return $this->workspaces->findAll();
	}

	public function create(string $name, string $slug, string $actor): Workspace
	{
		if (!$this->access->isAppAdmin($actor)) {
			throw ValidationException::field(Constants::ERR_WS_ROLE, 'role', $this->l10n->t('Only app administrators can create workspaces.'));
		}
		if ($this->workspaces->countAll() >= Constants::WS_MAX_PER_INSTANCE) {
			throw ValidationException::field(Constants::ERR_WS_LIMIT, 'workspace', $this->l10n->t('Workspace limit reached.'));
		}
		$slug = strtolower(trim($slug));
		if (!preg_match('/^[a-z0-9-]{3,64}$/', $slug)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'slug', $this->l10n->t('Workspace slug must be 3–64 characters: a–z, 0–9, hyphen.'));
		}
		$name = trim($name);
		if ($name === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'name', $this->l10n->t('Enter a workspace name.'));
		}

		$now = $this->timeFactory->getDateTime();
		$ws = new Workspace();
		$ws->setSlug($slug);
		$ws->setName($name);
		$ws->setIsDefault(0);
		$ws->setCreatedBy($actor);
		$ws->setCreatedAt($now);
		$ws->setUpdatedAt($now);
		$ws = $this->workspaces->insert($ws);

		$this->addMember((int)$ws->getId(), $actor, 'manager', true, $actor);
		$this->ensureSettingsRow((int)$ws->getId());

		return $ws;
	}

	public function addMember(int $workspaceId, string $userId, string $role, bool $canMailSend, string $actor): WorkspaceMember
	{
		$role = WorkspaceCapability::normalizeRole($role);
		if ($this->members->countByWorkspace($workspaceId) >= Constants::WS_MAX_MEMBERS) {
			throw ValidationException::field(Constants::ERR_WS_MEMBER_LIMIT, 'members', $this->l10n->t('Member limit reached for this workspace.'));
		}
		$existing = $this->members->findMembershipOrNull($workspaceId, $userId);
		$now = $this->timeFactory->getDateTime();
		if ($existing !== null) {
			$existing->setRole($role);
			$existing->setCanMailSend($canMailSend ? 1 : 0);
			$existing->setUpdatedAt($now);
			return $this->members->update($existing);
		}
		$m = new WorkspaceMember();
		$m->setWorkspaceId($workspaceId);
		$m->setUserId($userId);
		$m->setRole($role);
		$m->setCanMailSend($canMailSend ? 1 : 0);
		$m->setCreatedAt($now);
		$m->setUpdatedAt($now);
		return $this->members->insert($m);
	}

	public function removeMember(int $workspaceId, string $userId): void
	{
		$this->members->deleteByWorkspaceAndUser($workspaceId, $userId);
	}

	/** @return WorkspaceMember[] */
	public function listMembers(int $workspaceId): array
	{
		return $this->members->findByWorkspace($workspaceId);
	}

	public function ensureSettingsRow(int $workspaceId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('crm_ws_set')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		if ($n > 0) {
			return;
		}
		$now = $this->timeFactory->getDateTime();
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_ws_set')
			->values([
				'workspace_id' => $ins->createNamedParameter($workspaceId),
				'wip_limit' => $ins->createNamedParameter(Constants::WIP_LIMIT_DEFAULT),
				'lead_sla_hours' => $ins->createNamedParameter(Constants::LEAD_SLA_HOURS_DEFAULT),
				'stuck_chase_days' => $ins->createNamedParameter(3),
				'mail_retention_days' => $ins->createNamedParameter(365),
				'digest_enabled' => $ins->createNamedParameter(1),
				'updated_at' => $ins->createNamedParameter($now->format('Y-m-d H:i:s')),
			])
			->executeStatement();
	}

	/**
	 * @return array{wipLimit: int, leadSlaHours: int, stuckChaseDays: int, mailRetentionDays: int, digestEnabled: bool}
	 */
	public function getSettings(int $workspaceId): array
	{
		$this->ensureSettingsRow($workspaceId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('crm_ws_set')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return [
			'wipLimit' => BriefLogic::clampWipLimit((int)($row['wip_limit'] ?? Constants::WIP_LIMIT_DEFAULT)),
			'leadSlaHours' => max(1, (int)($row['lead_sla_hours'] ?? Constants::LEAD_SLA_HOURS_DEFAULT)),
			'stuckChaseDays' => max(1, (int)($row['stuck_chase_days'] ?? 3)),
			'mailRetentionDays' => max(30, (int)($row['mail_retention_days'] ?? 365)),
			'digestEnabled' => (int)($row['digest_enabled'] ?? 1) === 1,
		];
	}

	public function updateSettings(int $workspaceId, array $data): array
	{
		$this->ensureSettingsRow($workspaceId);
		$current = $this->getSettings($workspaceId);
		$wip = isset($data['wipLimit']) ? BriefLogic::clampWipLimit((int)$data['wipLimit']) : $current['wipLimit'];
		$sla = isset($data['leadSlaHours']) ? max(1, (int)$data['leadSlaHours']) : $current['leadSlaHours'];
		$stuck = isset($data['stuckChaseDays']) ? max(1, (int)$data['stuckChaseDays']) : $current['stuckChaseDays'];
		$ret = isset($data['mailRetentionDays']) ? max(30, (int)$data['mailRetentionDays']) : $current['mailRetentionDays'];
		$digest = array_key_exists('digestEnabled', $data) ? (!empty($data['digestEnabled']) ? 1 : 0) : ($current['digestEnabled'] ? 1 : 0);

		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_ws_set')
			->set('wip_limit', $qb->createNamedParameter($wip))
			->set('lead_sla_hours', $qb->createNamedParameter($sla))
			->set('stuck_chase_days', $qb->createNamedParameter($stuck))
			->set('mail_retention_days', $qb->createNamedParameter($ret))
			->set('digest_enabled', $qb->createNamedParameter($digest))
			->set('updated_at', $qb->createNamedParameter($this->timeFactory->getDateTime()->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->executeStatement();

		return $this->getSettings($workspaceId);
	}
}
