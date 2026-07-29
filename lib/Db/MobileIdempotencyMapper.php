<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Companion P2 — (uid, idempotency_key) → activity_id ledger.
 */
class MobileIdempotencyMapper
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function findActivityId(string $uid, string $idemKey): ?int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('activity_id')
			->from('crm_mob_idem')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($idemKey)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false || !isset($row['activity_id'])) {
			return null;
		}

		return (int)$row['activity_id'];
	}

	public function insert(string $uid, string $idemKey, int $activityId, int $createdAt): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('crm_mob_idem')
			->values([
				'uid' => $qb->createNamedParameter($uid),
				'idem_key' => $qb->createNamedParameter($idemKey),
				'activity_id' => $qb->createNamedParameter($activityId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
	}
}
