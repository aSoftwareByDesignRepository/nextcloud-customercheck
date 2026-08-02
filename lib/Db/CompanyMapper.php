<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Company>
 */
class CompanyMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_company', Company::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): Company
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Write-lock the company row (PSD §4.2 / §4.8 lock order: company first).
	 *
	 * Callers MUST already be inside a DB transaction. Serialises concurrent
	 * primary-contact promotions so two writers cannot both observe "no other
	 * primary" and leave two primaries after commit.
	 */
	public function lockRow(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->forUpdate();
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
	}

	public function findBySlug(string $slug): ?Company
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function slugExists(string $slug): bool
	{
		return $this->findBySlug($slug) !== null;
	}

	/**
	 * @param array{status?: string, search?: string, archived?: bool} $filters
	 * @return Company[]
	 */
	public function search(array $filters = [], int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyFilters($qb, $filters);
		$qb->orderBy('name', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($this->clampLimit($limit))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	/**
	 * @param array{status?: string, search?: string, archived?: bool} $filters
	 */
	public function countSearch(array $filters = []): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());
		$this->applyFilters($qb, $filters);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Active companies in one workspace. Fail closed without a workspace —
	 * never return every book’s customers.
	 *
	 * @return Company[]
	 */
	public function findAllActive(int $workspaceId, int $limit = 5000): array
	{
		if ($workspaceId <= 0) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('archived_at'))
			->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($this->clampLimit($limit));

		return $this->findEntities($qb);
	}

	/**
	 * Resolve several companies in one round trip (list rendering, Today queue).
	 *
	 * @param list<int> $ids
	 * @return array<int, Company> keyed by id
	 */
	public function findMapByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$map = [];
		foreach ($this->findEntities($qb) as $company) {
			$map[(int)$company->getId()] = $company;
		}

		return $map;
	}

	public function findByPcCustomerId(int $pcCustomerId): ?Company
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pc_customer_id', $qb->createNamedParameter($pcCustomerId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByTcCustomerId(int $tcCustomerId): ?Company
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('tc_customer_id', $qb->createNamedParameter($tcCustomerId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Case-insensitive exact name match, used by the import matcher (§8.6 rule b).
	 *
	 * @return Company[]
	 */
	public function findByNameCaseInsensitive(string $name): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq(
				$qb->func()->lower('name'),
				$qb->createNamedParameter(mb_strtolower(trim($name)))
			))
			->setMaxResults(50);

		return $this->findEntities($qb);
	}

	/**
	 * @param array{status?: string, search?: string, archived?: bool, workspaceId?: int, workspaceIds?: list<int>} $filters
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void
	{
		if (($filters['includeArchived'] ?? false) === true) {
			// Both active and archived — Review re-link / historical books (GNQ §5.5).
		} elseif (($filters['archived'] ?? false) === true) {
			$qb->andWhere($qb->expr()->isNotNull('archived_at'));
		} else {
			$qb->andWhere($qb->expr()->isNull('archived_at'));
		}

		$status = (string)($filters['status'] ?? '');
		if ($status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		$workspaceId = (int)($filters['workspaceId'] ?? 0);
		$workspaceIds = $filters['workspaceIds'] ?? null;
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
				// Fail closed: empty allow-list must not mean “all books”.
				$qb->andWhere($qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)));
			} elseif (count($ids) === 1) {
				$qb->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($ids[0], IQueryBuilder::PARAM_INT)));
			} else {
				$qb->andWhere($qb->expr()->in('workspace_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
			}
		} elseif ($workspaceId > 0) {
			$qb->andWhere($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		}

		$search = trim((string)($filters['search'] ?? ''));
		if ($search !== '') {
			$like = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('slug', $qb->createNamedParameter($like)),
			));
		}
	}

	private function clampLimit(int $limit): int
	{
		return max(1, min(5000, $limit));
	}
}
