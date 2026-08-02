<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Edit conflict queue — GNQ §6.
 */
class EditConflictService
{
	/** @var list<string> */
	public const COMPANY_FIELDS = [
		'name', 'status', 'accountOwnerUserId', 'kamTier', 'touchIntervalDays',
		'nextTouchOn', 'filesFolderId',
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly ?NotificationService $notifications = null,
	) {
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $incoming patch fields only
	 * @return array{merged: list<string>}
	 */
	public function detectOrMerge(
		int $workspaceId,
		string $entityType,
		int $entityId,
		array $base,
		array $current,
		array $incoming,
		string $actorA,
		string $actorB,
	): array {
		$classified = ConflictFieldLogic::classifyPatch($base, $current, $incoming);
		if ($classified['conflicts'] !== []) {
			$id = $this->recordOpenConflict(
				$workspaceId,
				$entityType,
				$entityId,
				$base,
				$current,
				$incoming,
				$classified['conflicts'],
				$actorA,
				$actorB,
			);
			throw new ConflictException(
				$this->l10n->t('Parallel edits need a resolution with a reason.'),
				Constants::ERR_EDIT_CONFLICT,
				['conflictId' => $id, 'fields' => $classified['conflicts']],
			);
		}

		return ['merged' => $classified['takeIncoming']];
	}

	/**
	 * Persist an open conflict outside a failed entity-update transaction (GNQ §6.3).
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $incoming
	 * @param list<string> $conflictFields
	 */
	public function recordOpenConflict(
		int $workspaceId,
		string $entityType,
		int $entityId,
		array $base,
		array $current,
		array $incoming,
		array $conflictFields,
		string $actorA,
		string $actorB,
	): int {
		return $this->insertConflict(
			$workspaceId,
			$entityType,
			$entityId,
			$base,
			$current,
			$incoming,
			$conflictFields,
			$actorA,
			$actorB,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listOpen(int $workspaceId, ?string $actorFilter = null): array
	{
		if (!$this->db->tableExists('crm_edit_conflict')) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_edit_conflict')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->orderBy('updated_at', 'DESC')
			->setMaxResults(100);
		if ($actorFilter !== null && $actorFilter !== '') {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->eq('actor_a', $qb->createNamedParameter($actorFilter)),
				$qb->expr()->eq('actor_b', $qb->createNamedParameter($actorFilter)),
			));
		}
		$r = $qb->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = $this->serialize($row);
		}
		$r->closeCursor();

		return $out;
	}

	public function countOpen(int $workspaceId): int
	{
		if (!$this->db->tableExists('crm_edit_conflict')) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('crm_edit_conflict')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')));
		$r = $qb->executeQuery();
		$n = (int)$r->fetchOne();
		$r->closeCursor();

		return $n;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(int $workspaceId, int $id): array
	{
		return $this->serialize($this->requireRow($workspaceId, $id));
	}

	/**
	 * @param array<string, array{choice: string, value?: mixed}> $choices
	 * @return array<string, mixed>
	 */
	public function resolve(int $workspaceId, int $id, array $choices, string $reason, string $actor, int $expectedVersion): array
	{
		$reason = trim($reason);
		if (mb_strlen($reason) < 10) {
			throw ValidationException::field(
				Constants::ERR_REASON_REQUIRED,
				'reason',
				$this->l10n->t('Explain the resolution in at least 10 characters.'),
			);
		}
		$row = $this->requireRow($workspaceId, $id);
		if ((string)$row['status'] !== 'open') {
			throw ValidationException::field(Constants::ERR_ALREADY_TERMINAL, 'status', $this->l10n->t('This conflict is already resolved.'));
		}
		if ((int)$row['row_version'] !== $expectedVersion) {
			throw new ConflictException($this->l10n->t('Someone else resolved this conflict first.'));
		}

		$applied = $this->computeAppliedPatch($row, $choices);

		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_edit_conflict')
			->set('status', $qb->createNamedParameter('resolved'))
			->set('reason', $qb->createNamedParameter(mb_substr($reason, 0, 500)))
			->set('resolved_by', $qb->createNamedParameter($actor))
			->set('resolved_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->set('row_version', $qb->createNamedParameter($expectedVersion + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('row_version', $qb->createNamedParameter($expectedVersion, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->executeStatement();
		if ($affected < 1) {
			throw new ConflictException($this->l10n->t('Someone else resolved this conflict first.'));
		}

		try {
			$this->notifications?->withdraw('edit_conflict', (string)$id);
		} catch (\Throwable) {
		}

		$resolved = $this->requireRow($workspaceId, $id);
		$payload = $this->serialize($resolved);
		$payload['choices'] = $choices;
		$payload['appliedPatch'] = $applied;

		return $payload;
	}

	/**
	 * Re-open a conflict after apply failed (GNQ §6.4 atomic apply compensator).
	 *
	 * @throws ConflictException when the compensator cannot restore the open queue row
	 */
	public function reopenAfterFailedApply(int $workspaceId, int $id, string $actor): void
	{
		if (!$this->db->tableExists('crm_edit_conflict')) {
			return;
		}
		$row = $this->requireRow($workspaceId, $id);
		$version = (int)($row['row_version'] ?? 1);
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$affected = $qb->update('crm_edit_conflict')
			->set('status', $qb->createNamedParameter('open'))
			->set('reason', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('resolved_by', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('resolved_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('row_version', $qb->createNamedParameter($version + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('resolved')))
			->andWhere($qb->expr()->eq('resolved_by', $qb->createNamedParameter($actor)))
			->andWhere($qb->expr()->eq('row_version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($affected < 1) {
			throw new ConflictException(
				$this->l10n->t('Could not restore the conflict after apply failed. Reload Conflicts and try again.'),
			);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, array{choice: string, value?: mixed}> $choices
	 * @return array<string, mixed>
	 */
	public function computeAppliedPatch(array $row, array $choices): array
	{
		$fields = json_decode((string)$row['fields_json'], true);
		$current = json_decode((string)$row['current_json'], true);
		$incoming = json_decode((string)$row['incoming_json'], true);
		if (!is_array($fields) || !is_array($current) || !is_array($incoming)) {
			throw ValidationException::field(Constants::ERR_VALIDATION, 'conflict', $this->l10n->t('This conflict record is incomplete.'));
		}
		$applied = [];
		foreach ($fields as $field) {
			$field = (string)$field;
			$choice = $choices[$field] ?? null;
			if (!is_array($choice)) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					$field,
					$this->l10n->t('Choose keep current, take incoming, or enter a manual value for every conflicted field.'),
				);
			}
			$kind = (string)($choice['choice'] ?? '');
			$applied[$field] = match ($kind) {
				'keep_current' => $current[$field] ?? null,
				'take_incoming' => $incoming[$field] ?? null,
				'manual' => $choice['value'] ?? null,
				default => throw ValidationException::field(
					Constants::ERR_VALIDATION,
					$field,
					$this->l10n->t('Choose keep current, take incoming, or enter a manual value for every conflicted field.'),
				),
			};
		}

		return $applied;
	}

	public function actorMayResolve(array $conflict, string $actor, bool $isManager): bool
	{
		if ($isManager) {
			return true;
		}
		$actor = trim($actor);

		return $actor !== ''
			&& (
				$actor === (string)($conflict['actorA'] ?? '')
				|| $actor === (string)($conflict['actorB'] ?? '')
			);
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $incoming
	 * @param list<string> $conflictFields
	 */
	private function insertConflict(
		int $workspaceId,
		string $entityType,
		int $entityId,
		array $base,
		array $current,
		array $incoming,
		array $conflictFields,
		string $actorA,
		string $actorB,
	): int {
		if (!$this->db->tableExists('crm_edit_conflict')) {
			throw new ConflictException(
				$this->l10n->t('Parallel edits need a resolution with a reason.'),
				Constants::ERR_EDIT_CONFLICT,
			);
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_edit_conflict')->values([
			'workspace_id' => $ins->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'entity_type' => $ins->createNamedParameter($entityType),
			'entity_id' => $ins->createNamedParameter($entityId, IQueryBuilder::PARAM_INT),
			'status' => $ins->createNamedParameter('open'),
			'base_json' => $ins->createNamedParameter(json_encode($base, JSON_THROW_ON_ERROR)),
			'current_json' => $ins->createNamedParameter(json_encode($current, JSON_THROW_ON_ERROR)),
			'incoming_json' => $ins->createNamedParameter(json_encode($incoming, JSON_THROW_ON_ERROR)),
			'fields_json' => $ins->createNamedParameter(json_encode($conflictFields, JSON_THROW_ON_ERROR)),
			'actor_a' => $ins->createNamedParameter($actorA),
			'actor_b' => $ins->createNamedParameter($actorB),
			'row_version' => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
			'created_at' => $ins->createNamedParameter($now),
			'updated_at' => $ins->createNamedParameter($now),
		])->executeStatement();

		$id = (int)$this->db->lastInsertId('crm_edit_conflict');
		$this->notifyConflictActors($workspaceId, $id, $entityType, $entityId, $actorA, $actorB);

		return $id;
	}

	private function notifyConflictActors(
		int $workspaceId,
		int $conflictId,
		string $entityType,
		int $entityId,
		string $actorA,
		string $actorB,
	): void {
		if ($this->notifications === null || $conflictId <= 0) {
			return;
		}
		$params = [
			'conflictId' => $conflictId,
			'entityType' => $entityType,
			'entityId' => $entityId,
			'workspaceId' => $workspaceId,
		];
		$seen = [];
		foreach ([$actorA, $actorB] as $uid) {
			$uid = trim($uid);
			if ($uid === '' || isset($seen[$uid])) {
				continue;
			}
			$seen[$uid] = true;
			try {
				$this->notifications->notifyUser(
					$uid,
					Constants::NOTIFY_EDIT_CONFLICT,
					'edit_conflict',
					(string)$conflictId,
					$params,
				);
			} catch (\Throwable) {
				// Never fail conflict persistence on notification fan-out.
			}
		}
	}

	/** @return array<string, mixed> */
	private function requireRow(int $workspaceId, int $id): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('crm_edit_conflict')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			throw new NotFoundException($this->l10n->t('This conflict was not found.'));
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function serialize(array $row): array
	{
		$base = json_decode((string)$row['base_json'], true) ?: [];
		$current = json_decode((string)$row['current_json'], true) ?: [];
		$incoming = json_decode((string)$row['incoming_json'], true) ?: [];
		$fields = json_decode((string)$row['fields_json'], true) ?: [];

		return [
			'id' => (int)$row['id'],
			'workspaceId' => (int)$row['workspace_id'],
			'entityType' => (string)$row['entity_type'],
			'entityId' => (int)$row['entity_id'],
			'entityLabel' => $this->entityLabelFromSnapshots((string)$row['entity_type'], $base, $current),
			'status' => (string)$row['status'],
			'base' => $base,
			'current' => $current,
			'incoming' => $incoming,
			'fields' => $fields,
			'actorA' => $row['actor_a'] ?? null,
			'actorB' => $row['actor_b'] ?? null,
			'reason' => $row['reason'] ?? null,
			'resolvedBy' => $row['resolved_by'] ?? null,
			'rowVersion' => (int)$row['row_version'],
			'updatedAt' => $row['updated_at'] ?? null,
		];
	}

	/**
	 * Human title from conflict snapshots — never show only "#12" when a name exists.
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $current
	 */
	private function entityLabelFromSnapshots(string $entityType, array $base, array $current): string
	{
		$pick = static function (array ...$bags): string {
			foreach ($bags as $bag) {
				foreach (['name', 'label', 'title', 'displayName', 'display_name'] as $key) {
					$v = trim((string)($bag[$key] ?? ''));
					if ($v !== '') {
						return $v;
					}
				}
			}

			return '';
		};

		return $pick($current, $base);
	}
}
