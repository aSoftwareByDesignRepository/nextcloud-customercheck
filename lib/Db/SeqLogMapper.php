<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SeqLog>
 */
class SeqLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_seq_log', SeqLog::class);
	}

	public function find(int $dealId, string $sequenceId): ?SeqLog
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('sequence_id', $qb->createNamedParameter($sequenceId)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function hasFired(int $dealId, string $sequenceId): bool
	{
		return $this->find($dealId, $sequenceId) !== null;
	}

	public function deleteByDeal(int $dealId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
