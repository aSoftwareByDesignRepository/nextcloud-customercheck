<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DealRotLog>
 */
class DealRotLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_dealrot', DealRotLog::class);
	}

	public function exists(int $dealId, string $snapKey): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('snap_key', $qb->createNamedParameter($snapKey)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	public function deleteByDealAndSnap(int $dealId, string $snapKey): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('snap_key', $qb->createNamedParameter($snapKey)));

		return $qb->executeStatement();
	}
}
