<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCA\CustomerCheck\Constants;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Deal>
 */
class DealMapper extends QBMapper
{
	use ExcludesArchivedCompaniesTrait;

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'crm_deal', Deal::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): Deal
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** @return Deal[] */
	public function findByCompany(int $companyId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->orderBy('stage_changed_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(500);

		return $this->findEntities($qb);
	}

	/**
	 * @param array{companyId?: int, stage?: string, openOnly?: bool, search?: string, title?: string} $filters
	 * @return Deal[]
	 */
	public function search(array $filters = [], int $limit = 500, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		$this->applyFilters($qb, $filters);
		$qb->orderBy('stage_changed_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min(5000, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	/**
	 * @param array{companyId?: int, stage?: string, openOnly?: bool, search?: string, title?: string} $filters
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
	 * Open (non-terminal) deals for one company — the lead-webhook dedup target (§14.4).
	 *
	 * @return Deal[]
	 */
	public function findOpenByCompany(int $companyId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->notIn(
				'stage',
				$qb->createNamedParameter(Constants::DEAL_TERMINAL_STAGES, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->orderBy('id', 'DESC')
			->setMaxResults(200);

		return $this->findEntities($qb);
	}

	/**
	 * Find the currently open deal created for a website order id.
	 *
	 * @return Deal[]
	 */
	public function findOpenByTitle(string $title): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('title', $qb->createNamedParameter($title)))
			->andWhere($qb->expr()->notIn(
				'stage',
				$qb->createNamedParameter(Constants::DEAL_TERMINAL_STAGES, IQueryBuilder::PARAM_STR_ARRAY),
			))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		return $this->findEntities($qb);
	}

	/**
	 * Open pipeline value per stage, used by the Today summary strip.
	 *
	 * @return array<string, array{count: int, valueCents: int}>
	 */
	public function sumOpenValueByStage(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('stage')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->selectAlias($qb->func()->sum('value_cents'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->notIn(
				'stage',
				$qb->createNamedParameter(Constants::DEAL_TERMINAL_STAGES, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->groupBy('stage');

		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[(string)$row['stage']] = [
				'count' => (int)$row['cnt'],
				'valueCents' => (int)$row['total'],
			];
		}
		$result->closeCursor();

		return $out;
	}

	public function deleteByCompany(int $companyId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * @param array{companyId?: int, stage?: string, openOnly?: bool, search?: string, title?: string, workspaceId?: int} $filters
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void
	{
		$companyId = (int)($filters['companyId'] ?? 0);
		$workspaceId = (int)($filters['workspaceId'] ?? 0);
		if ($companyId > 0) {
			$qb->andWhere($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		} else {
			// Default pipeline never shows deals of archived companies (PSD §4.1);
			// explicit company context (companyId) keeps history reachable.
			$this->excludeArchivedCompanies($qb);
		}
		if ($workspaceId > 0) {
			$this->restrictToWorkspace($qb, $workspaceId);
		}

		$stage = (string)($filters['stage'] ?? '');
		if ($stage !== '') {
			$qb->andWhere($qb->expr()->eq('stage', $qb->createNamedParameter($stage)));
		}

		if (($filters['openOnly'] ?? false) === true) {
			$qb->andWhere($qb->expr()->notIn(
				'stage',
				$qb->createNamedParameter(Constants::DEAL_TERMINAL_STAGES, IQueryBuilder::PARAM_STR_ARRAY)
			));
		}

		$title = trim((string)($filters['title'] ?? $filters['search'] ?? ''));
		if ($title !== '') {
			$like = '%' . $this->db->escapeLikeParameter($title) . '%';
			$qb->andWhere($qb->expr()->iLike('title', $qb->createNamedParameter($like)));
		}
	}

	/**
	 * Open deals whose stage has not changed since before $changedBefore (Today stale queue).
	 *
	 * @return Deal[]
	 */
	public function findOpenStaleBefore(\DateTimeInterface $changedBefore, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->notIn(
				'stage',
				$qb->createNamedParameter(Constants::DEAL_TERMINAL_STAGES, IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($qb->expr()->isNotNull('stage_changed_at'))
			->andWhere($qb->expr()->lt(
				'stage_changed_at',
				$qb->createNamedParameter(
					$changedBefore instanceof \DateTimeImmutable
						? $changedBefore
						: \DateTimeImmutable::createFromInterface($changedBefore),
					IQueryBuilder::PARAM_DATE
				)
			))
			->orderBy('stage_changed_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min(500, $limit)));
		// Archived companies stop generating stale-deal nudges (PSD §4.1).
		$this->excludeArchivedCompanies($qb);

		return $this->findEntities($qb);
	}

	/** Move deals during company merge. */
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
