<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
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
	/**
	 * Find active contacts by email inside one workspace book (fail closed).
	 *
	 * @return Person[]
	 */
	public function findByEmail(string $email, int $workspaceId): array
	{
		$email = mb_strtolower(trim($email));
		if ($email === '' || $workspaceId <= 0) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('p.*')
			->from($this->getTableName(), 'p')
			->innerJoin('p', 'crm_company', 'c', $qb->expr()->eq('p.company_id', 'c.id'))
			->where($qb->expr()->eq(
				$qb->func()->lower('p.email'),
				$qb->createNamedParameter($email),
			))
			->andWhere($qb->expr()->eq('c.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('c.archived_at'))
			->setMaxResults(50);

		return $this->findEntities($qb);
	}

	public function insert(Entity $entity): Entity
	{
		if ($entity instanceof Person) {
			$entity->setEmail(self::normalizeStoredEmail($entity->getEmail()));
		}

		return parent::insert($entity);
	}

	public function update(Entity $entity): Entity
	{
		if ($entity instanceof Person) {
			$entity->setEmail(self::normalizeStoredEmail($entity->getEmail()));
		}

		return parent::update($entity);
	}

	private static function normalizeStoredEmail(?string $email): ?string
	{
		if ($email === null) {
			return null;
		}
		$email = mb_strtolower(trim(str_replace("\xc2\xa0", ' ', $email)));

		return $email === '' ? null : $email;
	}

	/**
	 * Unified search over contact name + email.
	 *
	 * @param array{search?: string, companyId?: int, workspaceId?: int, workspaceIds?: list<int>} $filters
	 * @return Person[]
	 */
	public function search(array $filters = [], int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.*')->from($this->getTableName(), 'p');

		$companyId = (int)($filters['companyId'] ?? 0);
		if ($companyId > 0) {
			$qb->andWhere($qb->expr()->eq('p.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}

		$workspaceId = (int)($filters['workspaceId'] ?? 0);
		$workspaceIds = $filters['workspaceIds'] ?? null;
		$needJoin = $workspaceId > 0 || is_array($workspaceIds);
		if ($needJoin) {
			$qb->innerJoin('p', 'crm_company', 'c', $qb->expr()->eq('p.company_id', 'c.id'))
				->andWhere($qb->expr()->isNull('c.archived_at'));
			if (is_array($workspaceIds)) {
				$ids = [];
				foreach ($workspaceIds as $id) {
					$id = (int)$id;
					if ($id > 0) {
						$ids[$id] = $id;
					}
				}
				$ids = array_values($ids);
				if ($ids === []) {
					$qb->andWhere($qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)));
				} elseif (count($ids) === 1) {
					$qb->andWhere($qb->expr()->eq('c.workspace_id', $qb->createNamedParameter($ids[0], IQueryBuilder::PARAM_INT)));
				} else {
					$qb->andWhere($qb->expr()->in('c.workspace_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
				}
			} elseif ($workspaceId > 0) {
				$qb->andWhere($qb->expr()->eq('c.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
			}
		} elseif ($companyId <= 0) {
			// Fail closed: a contact search without a book or company is every person.
			$qb->andWhere($qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)));
		}

		$search = trim((string)($filters['search'] ?? ''));
		if ($search !== '') {
			$like = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('p.name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('p.email', $qb->createNamedParameter($like)),
			));
		}

		$qb->orderBy('p.name', 'ASC')
			->addOrderBy('p.id', 'ASC')
			->setMaxResults(max(1, min(5000, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	/**
	 * Batch-load people for many companies (Team coverage: one pass, not N× findByCompany).
	 *
	 * @param list<int> $companyIds
	 * @return array<int, list<Person>> companyId → people
	 */
	public function findGroupedByCompanyIds(array $companyIds, int $limit = 10000): array
	{
		$ids = [];
		foreach ($companyIds as $id) {
			$id = (int)$id;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		$ids = array_values($ids);
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('company_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('company_id', 'ASC')
			->addOrderBy('is_primary', 'DESC')
			->addOrderBy('name', 'ASC')
			->setMaxResults(max(1, min(20000, $limit)));

		$grouped = [];
		foreach ($this->findEntities($qb) as $person) {
			$cid = (int)$person->getCompanyId();
			$grouped[$cid] ??= [];
			$grouped[$cid][] = $person;
		}

		return $grouped;
	}

	public function deleteByCompany(int $companyId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Move every person from one company onto another (merge).
	 *
	 * Caller must already hold company locks and resolve primary-contact
	 * collisions (at most one primary may remain on the target).
	 */
	public function reassignCompany(int $fromCompanyId, int $toCompanyId): int
	{
		if ($fromCompanyId === $toCompanyId) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('company_id', $qb->createNamedParameter($toCompanyId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($fromCompanyId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

}
