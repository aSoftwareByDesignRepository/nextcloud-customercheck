<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ActivityDueLog>
 */
class ActivityDueLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_actdue', ActivityDueLog::class);
	}

	public function exists(int $activityId, string $dueDateYmd): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('activity_id', $qb->createNamedParameter($activityId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('due_date_snap', $qb->createNamedParameter($dueDateYmd)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	public function deleteByActivity(int $activityId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('activity_id', $qb->createNamedParameter($activityId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Release one (activity, due) claim only — never wipe older due snaps.
	 */
	public function deleteByActivityAndDue(int $activityId, string $dueDateYmd): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('activity_id', $qb->createNamedParameter($activityId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('due_date_snap', $qb->createNamedParameter($dueDateYmd)));

		return $qb->executeStatement();
	}
}
