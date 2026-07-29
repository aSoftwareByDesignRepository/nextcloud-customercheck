<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Activity>
 */
class ActivityMapper extends QBMapper
{
	use ExcludesArchivedCompaniesTrait;

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_activity', Activity::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): Activity
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @param array{companyId?: int, dealId?: int, type?: string, createdBy?: string, openOnly?: bool, doneOnly?: bool, dueBefore?: \DateTimeInterface} $filters
	 * @return Activity[]
	 */
	public function search(array $filters = [], int $limit = 500, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyFilters($qb, $filters);
		$qb->orderBy('due_date', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(5000, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	/**
	 * @param array{companyId?: int, dealId?: int, type?: string, createdBy?: string, openOnly?: bool, doneOnly?: bool, dueBefore?: \DateTimeInterface} $filters
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

	/** @return Activity[] */
	public function findOpenByCompany(int $companyId): array
	{
		return $this->search(['companyId' => $companyId, 'openOnly' => true], 200);
	}

	/**
	 * Open activities due on or before the given date — Today queue + digest counts.
	 *
	 * @return Activity[]
	 */
	public function findOpenDueUpTo(\DateTimeInterface $date, int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('done_at'))
			->andWhere($qb->expr()->isNotNull('due_date'))
			->andWhere($qb->expr()->lte('due_date', $qb->createNamedParameter($date->format('Y-m-d'))))
			->orderBy('due_date', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(5000, $limit)));
		// Archived companies stop generating open work (PSD §4.1).
		$this->excludeArchivedCompanies($qb);

		return $this->findEntities($qb);
	}

	public function countOpenDueBefore(\DateTimeInterface $date): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->isNull('done_at'))
			->andWhere($qb->expr()->isNotNull('due_date'))
			->andWhere($qb->expr()->lt('due_date', $qb->createNamedParameter($date->format('Y-m-d'))));
		$this->excludeArchivedCompanies($qb);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Idempotency probe for system-generated activities (§14.3): is there already
	 * an open activity with this exact title on this agreement?
	 */
	public function hasOpenSystemActivity(int $agreementId, string $title): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('agreement_id', $qb->createNamedParameter($agreementId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('origin', $qb->createNamedParameter('system')))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)))
			->andWhere($qb->expr()->isNull('done_at'));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	public function deleteByCompany(int $companyId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * @param array{companyId?: int, dealId?: int, type?: string, createdBy?: string, openOnly?: bool, doneOnly?: bool, dueBefore?: \DateTimeInterface, workspaceId?: int} $filters
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void
	{
		$companyId = (int)($filters['companyId'] ?? 0);
		$workspaceId = (int)($filters['workspaceId'] ?? 0);
		if ($companyId > 0) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}

		$dealId = (int)($filters['dealId'] ?? 0);
		if ($dealId > 0) {
			$qb->andWhere($qb->expr()->eq('deal_id', $qb->createNamedParameter($dealId, IQueryBuilder::PARAM_INT)));
		}

		if ($companyId <= 0 && $dealId <= 0) {
			// Default lists never show work of archived companies (PSD §4.1);
			// explicit company/deal context keeps history reachable.
			$this->excludeArchivedCompanies($qb);
		}
		if ($workspaceId > 0) {
			$this->restrictToWorkspace($qb, $workspaceId);
		}

		$type = (string)($filters['type'] ?? '');
		if ($type !== '') {
			$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)));
		}

		$createdBy = trim((string)($filters['createdBy'] ?? ''));
		if ($createdBy !== '') {
			$qb->andWhere($qb->expr()->eq('created_by', $qb->createNamedParameter($createdBy)));
		}

		if (($filters['openOnly'] ?? false) === true) {
			$qb->andWhere($qb->expr()->isNull('done_at'));
		} elseif (($filters['doneOnly'] ?? false) === true) {
			$qb->andWhere($qb->expr()->isNotNull('done_at'));
		}

		$dueBefore = $filters['dueBefore'] ?? null;
		if ($dueBefore instanceof \DateTimeInterface) {
			$qb->andWhere($qb->expr()->isNotNull('due_date'));
			$qb->andWhere($qb->expr()->lte('due_date', $qb->createNamedParameter($dueBefore->format('Y-m-d'))));
		}
	}

	/** Move activities during company merge. */
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
