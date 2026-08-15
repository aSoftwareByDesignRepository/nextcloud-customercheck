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
	 * Active (non-archived) company display names in a workspace — used to flag
	 * case-insensitive name twins on the companies list (slug remains the only
	 * hard-unique key).
	 *
	 * @return list<string>
	 */
	public function listActiveNames(int $workspaceId, int $limit = 5000): array
	{
		if ($workspaceId <= 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('name')
			->from($this->getTableName())
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('archived_at'))
			->setMaxResults($this->clampLimit($limit));
		$result = $qb->executeQuery();
		$names = [];
		try {
			while (($row = $result->fetch()) !== false) {
				$names[] = (string)($row['name'] ?? '');
			}
		} finally {
			$result->closeCursor();
		}
		return $names;
	}

	/**
	 * @return array<string, true>
	 */
	public function activeDuplicateNameKeys(int $workspaceId): array
	{
		return \OCA\CustomerCheck\Support\CompanyDuplicateLogic::duplicateKeysFromNames(
			$this->listActiveNames($workspaceId)
		);
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
	 * Distinct linked ProjectCheck ids on non-archived companies (Identity Health).
	 *
	 * @return list<int>
	 */
	public function listLinkedPcCustomerIds(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('pc_customer_id')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('pc_customer_id'))
			->andWhere($qb->expr()->isNull('archived_at'));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$id = (int)($row['pc_customer_id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * @return list<int>
	 */
	public function listLinkedTcCustomerIds(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('tc_customer_id')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('tc_customer_id'))
			->andWhere($qb->expr()->isNull('archived_at'));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$id = (int)($row['tc_customer_id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		$result->closeCursor();

		return $ids;
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
	 * @param array{status?: string, search?: string, archived?: bool, workspaceId?: int, workspaceIds?: list<int>, statusSearchCodes?: list<string>, ownerUserIds?: list<string>, matchOpenCallbacks?: bool} $filters
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void
	{
		if (($filters['includeArchived'] ?? false) === true) {
			// Both active and archived — Assign re-link / historical books (GNQ §5.5).
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
			if (mb_strlen($search) > \OCA\CustomerCheck\Support\CompanyListSearch::MAX_LEN) {
				$search = mb_substr($search, 0, \OCA\CustomerCheck\Support\CompanyListSearch::MAX_LEN);
			}
			$like = '%' . $this->db->escapeLikeParameter($search) . '%';
			$ors = [
				$qb->expr()->iLike('name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('slug', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('notes', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('account_owner_user_id', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('status', $qb->createNamedParameter($like)),
			];

			$statusCodes = $filters['statusSearchCodes'] ?? [];
			if (is_array($statusCodes) && $statusCodes !== []) {
				$codes = [];
				foreach ($statusCodes as $code) {
					$code = trim((string)$code);
					if ($code !== '') {
						$codes[$code] = $code;
					}
				}
				$codes = array_values($codes);
				if ($codes !== []) {
					$ors[] = $qb->expr()->in(
						'status',
						$qb->createNamedParameter($codes, IQueryBuilder::PARAM_STR_ARRAY)
					);
				}
			}

			$ownerIds = $filters['ownerUserIds'] ?? [];
			if (is_array($ownerIds) && $ownerIds !== []) {
				$uids = [];
				foreach ($ownerIds as $uid) {
					$uid = trim((string)$uid);
					if ($uid !== '') {
						$uids[$uid] = $uid;
					}
				}
				$uids = array_values($uids);
				if ($uids !== []) {
					$ors[] = $qb->expr()->in(
						'account_owner_user_id',
						$qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)
					);
				}
			}

			// Contacts (name / email / phone / job title) — EXISTS keeps the company row distinct.
			$pName = $qb->createNamedParameter($like);
			$pEmail = $qb->createNamedParameter($like);
			$pPhone = $qb->createNamedParameter($like);
			$pRole = $qb->createNamedParameter($like);
			$ors[] = $qb->createFunction(
				'EXISTS (SELECT 1 FROM `*PREFIX*crm_person` p'
				. ' WHERE p.company_id = id AND ('
				. ' LOWER(p.name) LIKE LOWER(' . $pName . ')'
				. ' OR LOWER(COALESCE(p.email, \'\')) LIKE LOWER(' . $pEmail . ')'
				. ' OR LOWER(COALESCE(p.phone, \'\')) LIKE LOWER(' . $pPhone . ')'
				. ' OR LOWER(COALESCE(p.role, \'\')) LIKE LOWER(' . $pRole . ')'
				. '))'
			);

			// Open callbacks / calls (title or details), plus keyword “callbacks”.
			$aTitle = $qb->createNamedParameter($like);
			$aDetails = $qb->createNamedParameter($like);
			$ors[] = $qb->createFunction(
				'EXISTS (SELECT 1 FROM `*PREFIX*crm_activity` a'
				. ' WHERE a.company_id = id'
				. ' AND a.done_at IS NULL'
				. ' AND (a.is_callback = 1 OR a.type = \'call\')'
				. ' AND ('
				. ' LOWER(a.title) LIKE LOWER(' . $aTitle . ')'
				. ' OR LOWER(COALESCE(a.details, \'\')) LIKE LOWER(' . $aDetails . ')'
				. '))'
			);
			if (($filters['matchOpenCallbacks'] ?? false) === true) {
				$ors[] = $qb->createFunction(
					'EXISTS (SELECT 1 FROM `*PREFIX*crm_activity` a'
					. ' WHERE a.company_id = id'
					. ' AND a.done_at IS NULL'
					. ' AND (a.is_callback = 1 OR a.type = \'call\'))'
				);
			}

			// Last contact / talk log (subject, snippet, or date fragment).
			$eSubject = $qb->createNamedParameter($like);
			$eSnippet = $qb->createNamedParameter($like);
			$emailSql = 'EXISTS (SELECT 1 FROM `*PREFIX*crm_email_touch` e'
				. ' WHERE e.company_id = id AND ('
				. ' LOWER(e.subject) LIKE LOWER(' . $eSubject . ')'
				. ' OR LOWER(COALESCE(e.snippet, \'\')) LIKE LOWER(' . $eSnippet . ')';
			if (preg_match('/^\d{4}(-\d{2})?(-\d{2})?$/', $search) === 1) {
				$eDate = $qb->createNamedParameter($like);
				$emailSql .= ' OR CAST(e.occurred_at AS CHAR) LIKE ' . $eDate;
			}
			$emailSql .= '))';
			$ors[] = $qb->createFunction($emailSql);

			$qb->andWhere($qb->expr()->orX(...$ors));
		}
	}

	private function clampLimit(int $limit): int
	{
		return max(1, min(5000, $limit));
	}
}
