<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ReminderLog>
 */
class ReminderLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_remlog', ReminderLog::class);
	}

	public function find(int $agreementId, int $offsetDays, ?\DateTimeInterface $validUntilSnap): ?ReminderLog
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('agreement_id', $qb->createNamedParameter($agreementId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('offset_days', $qb->createNamedParameter($offsetDays, IQueryBuilder::PARAM_INT)));

		if ($validUntilSnap === null) {
			$qb->andWhere($qb->expr()->isNull('valid_until_snap'));
		} else {
			$qb->andWhere($qb->expr()->eq('valid_until_snap', $qb->createNamedParameter($validUntilSnap->format('Y-m-d'))));
		}
		$qb->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Ledger offsets already recorded for an agreement + snapshot date.
	 *
	 * @return list<int>
	 */
	public function findSentOffsets(int $agreementId, ?\DateTimeInterface $validUntilSnap): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('offset_days')
			->from($this->getTableName())
			->where($qb->expr()->eq('agreement_id', $qb->createNamedParameter($agreementId, IQueryBuilder::PARAM_INT)));

		if ($validUntilSnap === null) {
			$qb->andWhere($qb->expr()->isNull('valid_until_snap'));
		} else {
			$qb->andWhere($qb->expr()->eq('valid_until_snap', $qb->createNamedParameter($validUntilSnap->format('Y-m-d'))));
		}

		$result = $qb->executeQuery();
		$offsets = [];
		while (($row = $result->fetch()) !== false) {
			$offsets[] = (int)$row['offset_days'];
		}
		$result->closeCursor();

		return $offsets;
	}

	public function deleteByAgreement(int $agreementId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('agreement_id', $qb->createNamedParameter($agreementId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
