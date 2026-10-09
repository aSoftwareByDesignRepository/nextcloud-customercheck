<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Support;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Hard-delete cascade helpers for integration tests. The schema has no FK
 * constraints, so teardown must walk the graph explicitly — leaf tables key
 * off entity ids (not workspace_id) and uid-keyed ledgers off uid.
 *
 * Canonical table list mirrors lib/Repair/UninstallDropTables.php.
 */
final class DbCascade
{
	/** Workspace-scoped tables carrying a workspace_id column. */
	private const WORKSPACE_TABLES = [
		'crm_1on1', 'crm_acct_plan', 'crm_cl_tmpl', 'crm_commit', 'crm_cust_prm',
		'crm_deal_cl_item', 'crm_digest_log', 'crm_edit_conflict', 'crm_entity_rev',
		'crm_escal', 'crm_mail_dft', 'crm_mail_msg', 'crm_mailbox', 'crm_ooo',
		'crm_review_item', 'crm_value_map', 'crm_ws_member', 'crm_ws_set',
	];

	/** Company-scoped entity tables (company_id column, no workspace_id). */
	private const COMPANY_TABLES = [
		'crm_person', 'crm_deal', 'crm_agreement', 'crm_activity',
		'crm_email_touch', 'crm_event', 'crm_link_idem',
	];

	/** uid-keyed ledgers not reachable via workspace or company ids. */
	private const UID_TABLES = ['crm_mob_idem', 'crm_push_tok', 'crm_link_idem'];

	/**
	 * Delete a workspace and every row that belongs to it.
	 */
	public static function workspace(int $workspaceId): void
	{
		if ($workspaceId <= 0) {
			return;
		}
		$db = \OC::$server->get(IDBConnection::class);

		// Descendant ids — leaf tables reference these instead of workspace_id.
		$companyIds = self::collectIds($db, 'crm_company', 'workspace_id', $workspaceId);
		$dealIds = self::collectIdsIn($db, 'crm_deal', 'company_id', $companyIds);
		$agreementIds = self::collectIdsIn($db, 'crm_agreement', 'company_id', $companyIds);
		$activityIds = self::collectIdsIn($db, 'crm_activity', 'company_id', $companyIds);
		$personIds = self::collectIdsIn($db, 'crm_person', 'company_id', $companyIds);
		$planIds = self::collectIds($db, 'crm_acct_plan', 'workspace_id', $workspaceId);
		$templateIds = self::collectIds($db, 'crm_cl_tmpl', 'workspace_id', $workspaceId);
		$checklistItemIds = self::collectIds($db, 'crm_deal_cl_item', 'workspace_id', $workspaceId);

		self::deleteLeaves($db, $activityIds, $dealIds, $agreementIds, $planIds, $templateIds, $checklistItemIds);
		self::deleteRevisions($db, $companyIds, $dealIds, $agreementIds, $personIds);

		foreach (self::COMPANY_TABLES as $table) {
			self::deleteIn($db, $table, 'company_id', $companyIds);
		}
		foreach (self::WORKSPACE_TABLES as $table) {
			self::deleteEq($db, $table, 'workspace_id', $workspaceId);
		}

		self::deleteEq($db, 'crm_company', 'workspace_id', $workspaceId);
		self::deleteEq($db, 'crm_workspace', 'id', $workspaceId);
	}

	/**
	 * Delete one company and every row that belongs to it.
	 */
	public static function company(int $companyId): void
	{
		if ($companyId <= 0) {
			return;
		}
		$db = \OC::$server->get(IDBConnection::class);

		$dealIds = self::collectIds($db, 'crm_deal', 'company_id', $companyId);
		$agreementIds = self::collectIds($db, 'crm_agreement', 'company_id', $companyId);
		$activityIds = self::collectIds($db, 'crm_activity', 'company_id', $companyId);
		$personIds = self::collectIds($db, 'crm_person', 'company_id', $companyId);
		$planIds = self::collectIds($db, 'crm_acct_plan', 'company_id', $companyId);
		$checklistItemIds = self::collectIds($db, 'crm_deal_cl_item', 'company_id', $companyId);

		self::deleteLeaves($db, $activityIds, $dealIds, $agreementIds, $planIds, $templateIds = [], $checklistItemIds);
		self::deleteRevisions($db, [$companyId], $dealIds, $agreementIds, $personIds);

		foreach (self::COMPANY_TABLES as $table) {
			self::deleteIn($db, $table, 'company_id', [$companyId]);
		}
		// Rows that also carry workspace_id but are keyed to this company.
		foreach (['crm_acct_plan', 'crm_commit', 'crm_cust_prm', 'crm_deal_cl_item',
			'crm_escal', 'crm_mail_dft', 'crm_mail_msg', 'crm_review_item'] as $table) {
			self::deleteIn($db, $table, 'company_id', [$companyId]);
		}
		self::deleteEq($db, 'crm_company', 'id', $companyId);
	}

	/**
	 * Delete all companies whose slug matches a LIKE pattern (e.g. '%-<run suffix>').
	 */
	public static function companiesBySlugLike(string $like): void
	{
		$db = \OC::$server->get(IDBConnection::class);
		if (!$db->tableExists('crm_company')) {
			return;
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('crm_company')
			->where($qb->expr()->like('slug', $qb->createNamedParameter($like)));
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$r->closeCursor();
		foreach ($ids as $id) {
			self::company($id);
		}
	}

	/** Drop uid-keyed leftovers (idempotency ledger, push tokens) for a test user. */
	public static function uidRows(string $uid): void
	{
		$db = \OC::$server->get(IDBConnection::class);
		foreach (self::UID_TABLES as $table) {
			if (!$db->tableExists($table)) {
				continue;
			}
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
				->executeStatement();
		}
	}

	/**
	 * Leaf tables keyed by collected entity ids.
	 *
	 * @param list<int> $activityIds
	 * @param list<int> $dealIds
	 * @param list<int> $agreementIds
	 * @param list<int> $planIds
	 * @param list<int> $templateIds
	 * @param list<int> $checklistItemIds
	 */
	private static function deleteLeaves(
		IDBConnection $db,
		array $activityIds,
		array $dealIds,
		array $agreementIds,
		array $planIds,
		array $templateIds,
		array $checklistItemIds,
	): void {
		self::deleteIn($db, 'crm_actdue', 'activity_id', $activityIds);
		self::deleteIn($db, 'crm_mob_idem', 'activity_id', $activityIds);
		self::deleteIn($db, 'crm_dealrot', 'deal_id', $dealIds);
		self::deleteIn($db, 'crm_seq_log', 'deal_id', $dealIds);
		self::deleteIn($db, 'crm_hours', 'agreement_id', $agreementIds);
		self::deleteIn($db, 'crm_pay_watch', 'agreement_id', $agreementIds);
		self::deleteIn($db, 'crm_remlog', 'agreement_id', $agreementIds);
		self::deleteIn($db, 'crm_map_step', 'plan_id', $planIds);
		self::deleteIn($db, 'crm_plan_ms', 'plan_id', $planIds);
		self::deleteIn($db, 'crm_cl_tmpl_item', 'template_id', $templateIds);
		self::deleteIn($db, 'crm_deal_cl_file', 'item_id', $checklistItemIds);
	}

	/**
	 * Optimistic-lock revision rows keyed by (entity_type, entity_id).
	 *
	 * @param list<int> $companyIds
	 * @param list<int> $dealIds
	 * @param list<int> $agreementIds
	 * @param list<int> $personIds
	 */
	private static function deleteRevisions(
		IDBConnection $db,
		array $companyIds,
		array $dealIds,
		array $agreementIds,
		array $personIds,
	): void {
		foreach ([
			'company' => $companyIds,
			'deal' => $dealIds,
			'agreement' => $agreementIds,
			'person' => $personIds,
		] as $type => $ids) {
			if ($ids === [] || !$db->tableExists('crm_entity_rev')) {
				continue;
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('crm_entity_rev')
				->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($type)))
				->andWhere($qb->expr()->in(
					'entity_id',
					$qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY),
				))
				->executeStatement();
		}
	}

	/** @return list<int> */
	private static function collectIds(IDBConnection $db, string $table, string $column, int $value): array
	{
		if (!$db->tableExists($table)) {
			return [];
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$r->closeCursor();
		return $ids;
	}

	/**
	 * @param list<int> $values
	 * @return list<int>
	 */
	private static function collectIdsIn(IDBConnection $db, string $table, string $column, array $values): array
	{
		if ($values === [] || !$db->tableExists($table)) {
			return [];
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)
			->where($qb->expr()->in(
				$column,
				$qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY),
			));
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$r->closeCursor();
		return $ids;
	}

	/** @param list<int> $values */
	private static function deleteIn(IDBConnection $db, string $table, string $column, array $values): void
	{
		if ($values === [] || !$db->tableExists($table)) {
			return;
		}
		$qb = $db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->in(
				$column,
				$qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->executeStatement();
	}

	private static function deleteEq(IDBConnection $db, string $table, string $column, int $value): void
	{
		if (!$db->tableExists($table)) {
			return;
		}
		$qb = $db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
