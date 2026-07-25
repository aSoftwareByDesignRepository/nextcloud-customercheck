<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Person>
 */
class PersonMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_person', Person::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): Person
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** @return Person[] */
	public function findByCompany(int $companyId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->orderBy('is_primary', 'DESC')
			->addOrderBy('name', 'ASC')
			->setMaxResults(500);

		return $this->findEntities($qb);
	}

	public function findPrimary(int $companyId): ?Person
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_primary', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Clear the primary flag for a company, optionally sparing the row that is
	 * about to become primary. Runs inside the caller's transaction so at most
	 * one primary can ever be observed (§4.2).
	 *
	 * @return int number of rows demoted
	 */
	public function clearPrimaryFor(int $companyId, ?int $exceptPersonId = null): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_primary', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_primary', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		if ($exceptPersonId !== null && $exceptPersonId > 0) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptPersonId, IQueryBuilder::PARAM_INT)));
		}

		return $qb->executeStatement();
	}

	/** @return Person[] */
	public function findByEmail(string $email): array
	{
		$email = mb_strtolower(trim($email));
		if ($email === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq($qb->func()->lower('email'), $qb->createNamedParameter($email)))
			->setMaxResults(50);

		return $this->findEntities($qb);
	}

	/**
	 * Unified search over contact name + email.
	 *
	 * @param array{search?: string, companyId?: int} $filters
	 * @return Person[]
	 */
	public function search(array $filters = [], int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		$companyId = (int)($filters['companyId'] ?? 0);
		if ($companyId > 0) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}

		$search = trim((string)($filters['search'] ?? ''));
		if ($search !== '') {
			$like = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('email', $qb->createNamedParameter($like)),
			));
		}

		$qb->orderBy('name', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(5000, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	public function deleteByCompany(int $companyId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
