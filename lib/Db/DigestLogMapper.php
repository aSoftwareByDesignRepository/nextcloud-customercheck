<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<DigestLog>
 */
class DigestLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_digest_log', DigestLog::class);
	}

	public function findByWeekKey(string $weekKey): ?DigestLog
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('week_key', $qb->createNamedParameter($weekKey)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function existsForWeek(string $weekKey): bool
	{
		return $this->findByWeekKey($weekKey) !== null;
	}
}
