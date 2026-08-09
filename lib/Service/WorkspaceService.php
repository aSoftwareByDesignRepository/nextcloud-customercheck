<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Db\WorkspaceMapper;
use OCA\CustomerCheck\Db\WorkspaceMember;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Support\DbTransaction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;

/**
 * Workspace CRUD + membership — product §6.
 */
class WorkspaceService
{
	private const CREATE_LOCK_KEY = 'customercheck/workspace_create';

	public function __construct(
		private readonly WorkspaceMapper $workspaces,
		private readonly WorkspaceMemberMapper $members,
		private readonly AccessControlService $access,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly CirclesDirectoryService $circles,
		private readonly ILockingProvider $locking,
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

	public function findDefault(): ?Workspace
	{
		return $this->workspaces->findDefault();
	}

	public function findById(int $id): ?Workspace
	{
		if ($id <= 0) {
			return null;
		}
		try {
			return $this->workspaces->findById($id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function create(string $name, string $slug, string $actor): Workspace
	{
		if (!$this->access->isAppAdmin($actor)) {
			throw ValidationException::field(Constants::ERR_WS_ROLE, 'role', $this->l10n->t('Only app administrators can create workspaces.'));
		}
		$slug = strtolower(trim($slug));
		if (!preg_match('/^[a-z0-9-]{3,64}$/', $slug)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'slug', $this->l10n->t('Workspace slug must be 3–64 characters: a–z, 0–9, hyphen.'));
		}
		$name = trim($name);
		if ($name === '') {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'name', $this->l10n->t('Enter a workspace name.'));
		}

		$this->locking->acquireLock(self::CREATE_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
		$ownsTxn = false;
		try {
			$ownsTxn = DbTransaction::begin($this->db);
			$existing = $this->workspaces->countAll();
			if (!Constants::MULTI_WORKSPACE_ENABLED && $existing >= 1) {
				throw ValidationException::field(
					Constants::ERR_WS_LIMIT,
					'workspace',
					$this->l10n->t('CustomerCheck uses a single organisation. Additional workspaces cannot be created.'),
				);
			}
			if ($existing >= Constants::WS_MAX_PER_INSTANCE) {
				throw ValidationException::field(Constants::ERR_WS_LIMIT, 'workspace', $this->l10n->t('Workspace limit reached.'));
			}

			$now = $this->timeFactory->getDateTime();
			$ws = new Workspace();
			$ws->setSlug($slug);
			$ws->setName($name);
			// First book becomes the Default organisation when multi-workspace is off.
			$ws->setIsDefault((!Constants::MULTI_WORKSPACE_ENABLED && $existing === 0) ? 1 : 0);
			$ws->setCreatedBy($actor);
			$ws->setCreatedAt($now);
			$ws->setUpdatedAt($now);
			$ws = $this->workspaces->insert($ws);

			$this->addMember((int)$ws->getId(), $actor, 'manager', true, $actor);
			$this->ensureSettingsRow((int)$ws->getId());
			DbTransaction::commit($this->db, $ownsTxn);
			$ownsTxn = false;

			return $ws;
		} catch (\Throwable $e) {
			DbTransaction::rollBack($this->db, $ownsTxn);
			throw $e;
		} finally {
			$this->locking->releaseLock(self::CREATE_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function addMember(int $workspaceId, string $userId, string $role, bool $canMailSend, string $actor): WorkspaceMember
	{
		$userId = trim($userId);
		if ($userId === '' || !$this->userManager->userExists($userId)) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'userId',
				$this->l10n->t('Unknown Nextcloud user. Search the directory and pick a colleague.'),
			);
		}
		if ($workspaceId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'workspaceId',
				$this->l10n->t('Pick a workspace first.'),
			);
		}
		$role = WorkspaceCapability::normalizeRole($role);
		$now = $this->timeFactory->getDateTime();

		$this->db->beginTransaction();
		try {
			if (!$this->workspaces->lockRow($workspaceId)) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'workspaceId',
					$this->l10n->t('That workspace was not found.'),
				);
			}
			$existing = $this->members->findMembershipOrNull($workspaceId, $userId);
			if ($existing !== null) {
				$existing->setRole($role);
				$existing->setCanMailSend($canMailSend ? 1 : 0);
				$existing->setUpdatedAt($now);
				$updated = $this->members->update($existing);
				$this->db->commit();

				return $updated;
			}
			if ($this->members->countByWorkspace($workspaceId) >= Constants::WS_MAX_MEMBERS) {
				throw ValidationException::field(Constants::ERR_WS_MEMBER_LIMIT, 'members', $this->l10n->t('Member limit reached for this workspace.'));
			}
			$m = new WorkspaceMember();
			$m->setWorkspaceId($workspaceId);
			$m->setUserId($userId);
			$m->setRole($role);
			$m->setCanMailSend($canMailSend ? 1 : 0);
			$m->setCreatedAt($now);
			$m->setUpdatedAt($now);
			$inserted = $this->members->insert($m);
			$this->db->commit();

			return $inserted;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function removeMember(int $workspaceId, string $userId): void
	{
		$this->members->deleteByWorkspaceAndUser($workspaceId, $userId);
	}

	/**
	 * One-shot import of Nextcloud group users into this workspace (product §6 optional).
	 * Not live sync — managers re-run when the group changes.
	 *
	 * @return array{added: int, updated: int, skipped: int, limitReached: bool, groupId: string, groupName: string}
	 */
	public function importMembersFromGroup(
		int $workspaceId,
		string $groupId,
		string $role,
		bool $canMailSend,
		string $actor,
	): array {
		$groupId = trim($groupId);
		if ($groupId === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'groupId',
				$this->l10n->t('Pick a Nextcloud group to import.'),
			);
		}
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'groupId',
				$this->l10n->t('That Nextcloud group was not found.'),
			);
		}
		$groupName = trim((string)$group->getDisplayName());
		if ($groupName === '') {
			$groupName = $groupId;
		}
		$userIds = [];
		$preSkipped = 0;
		foreach ($group->getUsers() as $user) {
			if ($user === null) {
				$preSkipped++;
				continue;
			}
			if (method_exists($user, 'isEnabled') && !$user->isEnabled()) {
				$preSkipped++;
				continue;
			}
			$uid = trim((string)$user->getUID());
			if ($uid === '') {
				$preSkipped++;
				continue;
			}
			$userIds[] = $uid;
		}
		$stats = $this->importUserIds($workspaceId, $userIds, $role, $canMailSend, $actor);

		return [
			'added' => $stats['added'],
			'updated' => $stats['updated'],
			'skipped' => $stats['skipped'] + $preSkipped,
			'limitReached' => $stats['limitReached'],
			'groupId' => $groupId,
			'groupName' => $groupName,
		];
	}

	/**
	 * One-shot import of Circles members into this workspace (product §6 optional).
	 * Soft-dep: Circles must be enabled; actor must belong to the circle. Not live sync.
	 *
	 * @return array{added: int, updated: int, skipped: int, limitReached: bool, circleId: string, circleName: string}
	 */
	public function importMembersFromCircle(
		int $workspaceId,
		string $circleId,
		string $role,
		bool $canMailSend,
		string $actor,
	): array {
		$circleId = trim($circleId);
		if ($circleId === '') {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'circleId',
				$this->l10n->t('Pick a Circle to import.'),
			);
		}
		if (!$this->circles->isAvailable()) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'circleId',
				$this->l10n->t('Circles is not available on this server.'),
			);
		}
		try {
			$resolved = $this->circles->localUserIdsInCircle($circleId, $actor);
		} catch (\Throwable) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'circleId',
				$this->l10n->t('That Circle was not found or you cannot see its members.'),
			);
		}
		if ($resolved['circleName'] === '' && $resolved['userIds'] === []) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'circleId',
				$this->l10n->t('That Circle was not found or you cannot see its members.'),
			);
		}
		$stats = $this->importUserIds($workspaceId, $resolved['userIds'], $role, $canMailSend, $actor);

		return [
			'added' => $stats['added'],
			'updated' => $stats['updated'],
			'skipped' => $stats['skipped'],
			'limitReached' => $stats['limitReached'],
			'circleId' => $resolved['circleId'] !== '' ? $resolved['circleId'] : $circleId,
			'circleName' => $resolved['circleName'] !== '' ? $resolved['circleName'] : $circleId,
		];
	}

	/**
	 * @param list<string> $userIds
	 * @return array{added: int, updated: int, skipped: int, limitReached: bool}
	 */
	private function importUserIds(
		int $workspaceId,
		array $userIds,
		string $role,
		bool $canMailSend,
		string $actor,
	): array {
		$role = WorkspaceCapability::normalizeRole($role);
		$added = 0;
		$updated = 0;
		$skipped = 0;
		$limitReached = false;
		$seen = [];

		foreach ($userIds as $userId) {
			$userId = trim((string)$userId);
			if ($userId === '' || isset($seen[$userId])) {
				if ($userId === '') {
					$skipped++;
				}
				continue;
			}
			$seen[$userId] = true;
			$user = $this->userManager->get($userId);
			if ($user === null) {
				$skipped++;
				continue;
			}
			if (method_exists($user, 'isEnabled') && !$user->isEnabled()) {
				$skipped++;
				continue;
			}
			$existing = $this->members->findMembershipOrNull($workspaceId, $userId);
			try {
				$this->addMember($workspaceId, $userId, $role, $canMailSend, $actor);
				if ($existing === null) {
					$added++;
				} else {
					$updated++;
				}
			} catch (ValidationException $e) {
				if ($e->getErrorCode() === Constants::ERR_WS_MEMBER_LIMIT) {
					$limitReached = true;
				}
				$skipped++;
			}
		}

		return [
			'added' => $added,
			'updated' => $updated,
			'skipped' => $skipped,
			'limitReached' => $limitReached,
		];
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
	 * @return array{wipLimit: int, leadSlaHours: int, stuckChaseDays: int, mailRetentionDays: int, digestEnabled: bool, reviewSources: array<string, array{enabled: bool, dsgvoAckAt: ?string}>}
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
			'mailRetentionDays' => max(Constants::MAIL_RETENTION_DAYS_MIN, (int)($row['mail_retention_days'] ?? 365)),
			'digestEnabled' => (int)($row['digest_enabled'] ?? 1) === 1,
			'reviewSources' => $this->decodeReviewSources($row['review_sources_json'] ?? null),
		];
	}

	/**
	 * @return array<string, array{enabled: bool, dsgvoAckAt: ?string}>
	 */
	public function getReviewSources(int $workspaceId): array
	{
		return $this->getSettings($workspaceId)['reviewSources'];
	}

	/**
	 * @param array<string, mixed> $sources
	 * @return array<string, array{enabled: bool, dsgvoAckAt: ?string}>
	 */
	public function updateReviewSources(int $workspaceId, array $sources, string $actor): array
	{
		$this->ensureSettingsRow($workspaceId);
		$current = $this->getReviewSources($workspaceId);
		$now = $this->timeFactory->getDateTime()->format(DATE_ATOM);
		$allowed = ['shared_mail', 'contact_suggest', 'calendar_event', 'file', 'task', 'import_anomaly'];
		foreach ($allowed as $kind) {
			if (!array_key_exists($kind, $sources)) {
				continue;
			}
			$incoming = $sources[$kind];
			$enabled = is_array($incoming) ? !empty($incoming['enabled']) : !empty($incoming);
			$ack = is_array($incoming) && !empty($incoming['dsgvoAck']);
			if ($enabled && $kind !== 'shared_mail' && !$ack && empty($current[$kind]['dsgvoAckAt'])) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					$kind,
					$this->l10n->t('Confirm the DSGVO acknowledgement before enabling this review source.'),
				);
			}
			$current[$kind]['enabled'] = $enabled;
			if ($enabled && $kind !== 'shared_mail' && ($ack || !empty($current[$kind]['dsgvoAckAt']))) {
				$current[$kind]['dsgvoAckAt'] = $current[$kind]['dsgvoAckAt'] ?? $now;
				$current[$kind]['dsgvoAckBy'] = $actor;
			}
			if (is_array($incoming)) {
				if ($kind === 'file') {
					if (array_key_exists('rootFolderId', $incoming)) {
						$folderId = max(0, (int)$incoming['rootFolderId']);
						$current[$kind]['rootFolderId'] = $folderId;
						if ($folderId === 0 && !array_key_exists('rootFolderPath', $incoming)) {
							$current[$kind]['rootFolderPath'] = null;
						}
					}
					if (array_key_exists('rootFolderPath', $incoming)) {
						$path = trim((string)$incoming['rootFolderPath']);
						$path = preg_replace('/[\x00-\x1F\x7F]/u', '', $path) ?? '';
						$current[$kind]['rootFolderPath'] = $path !== ''
							? mb_substr($path, 0, 512)
							: null;
					}
					if (array_key_exists('scanActor', $incoming)) {
						$actorName = trim((string)$incoming['scanActor']);
						if ($actorName !== '') {
							if ($this->members->findMembershipOrNull($workspaceId, $actorName) === null) {
								throw ValidationException::field(
									Constants::ERR_VALIDATION,
									'scanActor',
									$this->l10n->t('The scan actor must be a member of this workspace.'),
								);
							}
							$current[$kind]['scanActor'] = mb_substr($actorName, 0, 64);
						} else {
							$current[$kind]['scanActor'] = null;
						}
					}
				}
				if ($kind === 'calendar_event' && array_key_exists('calendarUris', $incoming) && is_array($incoming['calendarUris'])) {
					$uris = [];
					foreach ($incoming['calendarUris'] as $uri) {
						$u = trim((string)$uri);
						if ($u !== '') {
							$uris[] = mb_substr($u, 0, 255);
						}
						if (count($uris) >= 20) {
							break;
						}
					}
					$current[$kind]['calendarUris'] = $uris;
				}
				if ($kind === 'task' && array_key_exists('listIds', $incoming) && is_array($incoming['listIds'])) {
					$ids = [];
					foreach ($incoming['listIds'] as $lid) {
						$id = is_numeric($lid) ? (string)(int)$lid : trim((string)$lid);
						if ($id !== '' && $id !== '0') {
							$ids[] = mb_substr($id, 0, 255);
						}
						if (count($ids) >= 20) {
							break;
						}
					}
					$current[$kind]['listIds'] = $ids;
				}
				if (in_array($kind, ['calendar_event', 'task', 'contact_suggest'], true) && array_key_exists('scanActor', $incoming)) {
					$actorName = trim((string)$incoming['scanActor']);
					if ($actorName !== '') {
						if ($this->members->findMembershipOrNull($workspaceId, $actorName) === null) {
							throw ValidationException::field(
								Constants::ERR_VALIDATION,
								'scanActor',
								$this->l10n->t('The scan actor must be a member of this workspace.'),
							);
						}
						$current[$kind]['scanActor'] = mb_substr($actorName, 0, 64);
					} else {
						$current[$kind]['scanActor'] = null;
					}
				}
			}
			if (!$enabled && $kind !== 'shared_mail') {
				// Keep prior ack timestamp for audit; only toggle enabled.
			}
		}
		// Shared mail stays enabled by default when a mailbox exists — managers may still disable.
		if (!isset($current['shared_mail'])) {
			$current['shared_mail'] = ['enabled' => true, 'dsgvoAckAt' => null];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('crm_ws_set')
			->set('review_sources_json', $qb->createNamedParameter(json_encode($current, JSON_THROW_ON_ERROR)))
			->set('updated_at', $qb->createNamedParameter($this->timeFactory->getDateTime()->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId)))
			->executeStatement();

		return $this->getReviewSources($workspaceId);
	}

	/**
	 * @return array<string, array{enabled: bool, dsgvoAckAt: ?string}>
	 */
	private function decodeReviewSources(mixed $raw): array
	{
		$defaults = [
			'shared_mail' => ['enabled' => true, 'dsgvoAckAt' => null],
			'contact_suggest' => ['enabled' => false, 'dsgvoAckAt' => null],
			'calendar_event' => ['enabled' => false, 'dsgvoAckAt' => null],
			'file' => ['enabled' => false, 'dsgvoAckAt' => null],
			'task' => ['enabled' => false, 'dsgvoAckAt' => null],
			'import_anomaly' => ['enabled' => false, 'dsgvoAckAt' => null],
		];
		if (!is_string($raw) || $raw === '') {
			return $defaults;
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return $defaults;
		}
		foreach ($defaults as $kind => $def) {
			if (!isset($decoded[$kind]) || !is_array($decoded[$kind])) {
				continue;
			}
			$merged = [
				'enabled' => !empty($decoded[$kind]['enabled']),
				'dsgvoAckAt' => isset($decoded[$kind]['dsgvoAckAt']) ? (string)$decoded[$kind]['dsgvoAckAt'] : null,
			];
			if (isset($decoded[$kind]['dsgvoAckBy'])) {
				$merged['dsgvoAckBy'] = (string)$decoded[$kind]['dsgvoAckBy'];
			}
			if ($kind === 'file') {
				if (isset($decoded[$kind]['rootFolderId'])) {
					$merged['rootFolderId'] = max(0, (int)$decoded[$kind]['rootFolderId']);
				}
				if (isset($decoded[$kind]['rootFolderPath']) && is_string($decoded[$kind]['rootFolderPath'])) {
					$path = trim($decoded[$kind]['rootFolderPath']);
					if ($path !== '') {
						$merged['rootFolderPath'] = mb_substr($path, 0, 512);
					}
				}
				if (isset($decoded[$kind]['scanActor'])) {
					$merged['scanActor'] = (string)$decoded[$kind]['scanActor'];
				}
			}
			if ($kind === 'calendar_event' && isset($decoded[$kind]['calendarUris']) && is_array($decoded[$kind]['calendarUris'])) {
				$merged['calendarUris'] = array_values(array_map('strval', array_slice($decoded[$kind]['calendarUris'], 0, 20)));
			}
			if ($kind === 'task' && isset($decoded[$kind]['listIds']) && is_array($decoded[$kind]['listIds'])) {
				$merged['listIds'] = array_values(array_map('strval', array_slice($decoded[$kind]['listIds'], 0, 20)));
			}
			if (in_array($kind, ['calendar_event', 'task', 'file', 'contact_suggest'], true) && isset($decoded[$kind]['scanActor'])) {
				$merged['scanActor'] = (string)$decoded[$kind]['scanActor'];
			}
			$defaults[$kind] = $merged;
		}

		return $defaults;
	}

	public function updateSettings(int $workspaceId, array $data): array
	{
		$this->ensureSettingsRow($workspaceId);
		$current = $this->getSettings($workspaceId);
		$wip = isset($data['wipLimit']) ? BriefLogic::clampWipLimit((int)$data['wipLimit']) : $current['wipLimit'];
		$sla = isset($data['leadSlaHours']) ? max(1, (int)$data['leadSlaHours']) : $current['leadSlaHours'];
		$stuck = isset($data['stuckChaseDays']) ? max(1, (int)$data['stuckChaseDays']) : $current['stuckChaseDays'];
		$ret = isset($data['mailRetentionDays'])
			? max(Constants::MAIL_RETENTION_DAYS_MIN, (int)$data['mailRetentionDays'])
			: $current['mailRetentionDays'];
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

		if (isset($data['reviewSources']) && is_array($data['reviewSources'])) {
			$this->updateReviewSources($workspaceId, $data['reviewSources'], (string)($data['actor'] ?? 'system'));
		}

		return $this->getSettings($workspaceId);
	}
}
