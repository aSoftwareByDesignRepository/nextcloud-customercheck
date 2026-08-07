<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Entity revision snapshots for conflict three-way — GNQ §6.3.
 */
class EntityRevisionService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	public function append(int $workspaceId, string $entityType, int $entityId, \DateTimeInterface $updatedAt, string $actor, array $snapshot): void
	{
		if (!$this->db->tableExists('crm_entity_rev') || $entityId <= 0) {
			return;
		}
		$now = $this->timeFactory->getDateTime()->format('Y-m-d H:i:s');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('crm_entity_rev')->values([
			'workspace_id' => $ins->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT),
			'entity_type' => $ins->createNamedParameter($entityType),
			'entity_id' => $ins->createNamedParameter($entityId, IQueryBuilder::PARAM_INT),
			'updated_at' => $ins->createNamedParameter($updatedAt->format('Y-m-d H:i:s')),
			'actor' => $ins->createNamedParameter($actor),
			'snapshot_json' => $ins->createNamedParameter(json_encode($snapshot, JSON_THROW_ON_ERROR)),
			'created_at' => $ins->createNamedParameter($now),
		])->executeStatement();
	}

	/**
	 * @return ?array<string, mixed>
	 */
	public function snapshotAt(string $entityType, int $entityId, string $updatedAtAtomOrSql): ?array
	{
		if (!$this->db->tableExists('crm_entity_rev')) {
			return null;
		}
		$stamp = $this->normalizeStamp($updatedAtAtomOrSql);
		$qb = $this->db->getQueryBuilder();
		$qb->select('snapshot_json')->from('crm_entity_rev')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('updated_at', $qb->createNamedParameter($stamp)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$r = $qb->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row === false) {
			return null;
		}
		$decoded = json_decode((string)$row['snapshot_json'], true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Drop revision snapshots older than retention (GNQ §6.3 — default 90 days).
	 *
	 * @return int rows deleted
	 */
	public function purgeOlderThanDays(int $days = 90, int $batch = 500): int
	{
		if (!$this->db->tableExists('crm_entity_rev')) {
			return 0;
		}
		$days = max(1, min(3650, $days));
		$batch = max(1, min(2000, $batch));
		$cutoff = $this->timeFactory->getDateTime()
			->modify('-' . $days . ' days')
			->format('Y-m-d H:i:s');
		$select = $this->db->getQueryBuilder();
		$select->select('id')->from('crm_entity_rev')
			->where($select->expr()->lt('created_at', $select->createNamedParameter($cutoff)))
			->orderBy('id', 'ASC')
			->setMaxResults($batch);
		$result = $select->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		if ($ids === []) {
			return 0;
		}
		$del = $this->db->getQueryBuilder();
		$params = [];
		foreach ($ids as $i => $id) {
			$params[] = $del->createNamedParameter($id, IQueryBuilder::PARAM_INT, 'rid' . $i);
		}
		$del->delete('crm_entity_rev')
			->where($del->expr()->in('id', $params));

		return $del->executeStatement();
	}

	private function normalizeStamp(string $value): string
	{
		$value = trim($value);
		try {
			$dt = new \DateTimeImmutable($value);

			return $dt->format('Y-m-d H:i:s');
		} catch (\Throwable) {
			return $value;
		}
	}
}
