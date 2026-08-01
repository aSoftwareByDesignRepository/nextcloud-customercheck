<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Archive contract (PSD §4.1): archived companies drop out of every DEFAULT
 * list — Today queue, pipeline, agreements, activities, money, reminders —
 * while their history stays reachable through explicit company context
 * (companyId / dealId filters, the company 360 page, GDPR export).
 *
 * The predicate is a portable `company_id NOT IN (SELECT …)` subquery so the
 * outer query keeps its plain (alias-free) FROM and entity hydration.
 */
trait ExcludesArchivedCompaniesTrait
{
	private function excludeArchivedCompanies(IQueryBuilder $qb, string $companyIdColumn = 'company_id'): void
	{
		$sub = $this->db->getQueryBuilder();
		$sub->select('id')
			->from('crm_company')
			->where($sub->expr()->isNotNull('archived_at'));

		$qb->andWhere(
			$qb->createFunction($companyIdColumn . ' NOT IN (' . $sub->getSQL() . ')'),
		);
	}

	/**
	 * Restrict child rows to companies in one workspace (product §3.3 / §6).
	 * Parameter is bound on the outer query builder (portable across MySQL/PG).
	 */
	private function restrictToWorkspace(IQueryBuilder $qb, int $workspaceId, string $companyIdColumn = 'company_id'): void
	{
		if ($workspaceId <= 0) {
			return;
		}
		$param = $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT);
		$qb->andWhere($qb->createFunction(
			$companyIdColumn . ' IN (SELECT id FROM `*PREFIX*crm_company` WHERE workspace_id = ' . $param . ')',
		));
	}

	/**
	 * Restrict child rows to companies in any of the given workspaces.
	 * Empty list → no rows (fail closed — never unscoped).
	 *
	 * @param list<int> $workspaceIds
	 */
	private function restrictToWorkspaces(IQueryBuilder $qb, array $workspaceIds, string $companyIdColumn = 'company_id'): void
	{
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
			return;
		}
		if (count($ids) === 1) {
			$this->restrictToWorkspace($qb, $ids[0], $companyIdColumn);
			return;
		}
		$param = $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY);
		$qb->andWhere($qb->createFunction(
			$companyIdColumn . ' IN (SELECT id FROM `*PREFIX*crm_company` WHERE workspace_id IN (' . $param . '))',
		));
	}
}
