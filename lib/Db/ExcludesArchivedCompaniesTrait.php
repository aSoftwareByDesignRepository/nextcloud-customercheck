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
}
