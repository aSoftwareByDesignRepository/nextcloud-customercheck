<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<HoursEntry>
 */
class HoursMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_hours', HoursEntry::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): HoursEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** @return HoursEntry[] */
	public function findByAgreement(int $agreementId, int $limit = 1000): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('agreement_id', $qb->createNamedParameter($agreementId, IQueryBuilder::PARAM_INT)))
			->orderBy('entry_date', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min(5000, $limit)));

		return $this->findEntities($qb);
	}

	/**
	 * Net logged minutes; compensating negative entries reduce the sum (A7).
	 */
	public function sumMinutes(int $agreementId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('minutes'))
			->from($this->getTableName())
			->where($qb->expr()->eq('agreement_id', $qb->createNamedParameter($agreementId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$sum = $result->fetchOne();
		$result->closeCursor();

		return $sum === null || $sum === false ? 0 : (int)$sum;
	}

	/**
	 * @param list<int> $agreementIds
	 * @return array<int, int> agreement id => net minutes
	 */
	public function sumMinutesForAgreements(array $agreementIds): array
	{
		$agreementIds = array_values(array_unique(array_filter($agreementIds, static fn (int $id): bool => $id > 0)));
		if ($agreementIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('agreement_id')
			->selectAlias($qb->func()->sum('minutes'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->in('agreement_id', $qb->createNamedParameter($agreementIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('agreement_id');

		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[(int)$row['agreement_id']] = (int)$row['total'];
		}
		$result->closeCursor();

		return $out;
	}

	/**
	 * GDPR erase (§4.8): hours are billing evidence and stay, but the free-text
	 * topic and the logging uid are blanked.
	 *
	 * @param list<int> $agreementIds
	 */
	public function anonymiseByAgreements(array $agreementIds): int
	{
		$agreementIds = array_values(array_unique(array_filter($agreementIds, static fn (int $id): bool => $id > 0)));
		if ($agreementIds === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('topic', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('created_by', $qb->createNamedParameter('system'))
			->where($qb->expr()->in('agreement_id', $qb->createNamedParameter($agreementIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qb->executeStatement();
	}

	public function deleteByAgreement(int $agreementId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('agreement_id', $qb->createNamedParameter($agreementId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
