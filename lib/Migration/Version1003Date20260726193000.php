<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * SHARED-IDENTITY I5 / AC-ID — enforce at most one CRM company per sibling id.
 *
 * Nullable UNIQUE: multiple unlinked (NULL) companies remain allowed. Pre-step
 * clears duplicate non-null links (keeps lowest company id) so upgrades never
 * fail on the unique index add.
 */
class Version1003Date20260726193000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$this->dedupeSiblingLinks($output, 'pc_customer_id');
		$this->dedupeSiblingLinks($output, 'tc_customer_id');
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('crm_company')) {
			$t = $schema->getTable('crm_company');
			if (!$t->hasIndex('crm_co_pc_uq')) {
				$t->addUniqueIndex(['pc_customer_id'], 'crm_co_pc_uq');
			}
			if (!$t->hasIndex('crm_co_tc_uq')) {
				$t->addUniqueIndex(['tc_customer_id'], 'crm_co_tc_uq');
			}
		}

		return $schema;
	}

	private function dedupeSiblingLinks(IOutput $output, string $column): void
	{
		if (!$this->db->tableExists('crm_company')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', $column)
			->from('crm_company')
			->where($qb->expr()->isNotNull($column))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		/** @var array<int, int> $firstCompanyBySibling siblingId => keep company id */
		$firstCompanyBySibling = [];
		/** @var list<int> $clearIds */
		$clearIds = [];
		while ($row = $result->fetch()) {
			$companyId = (int)($row['id'] ?? 0);
			$siblingId = (int)($row[$column] ?? 0);
			if ($companyId <= 0 || $siblingId <= 0) {
				continue;
			}
			if (!isset($firstCompanyBySibling[$siblingId])) {
				$firstCompanyBySibling[$siblingId] = $companyId;
				continue;
			}
			$clearIds[] = $companyId;
		}
		$result->closeCursor();

		$cleared = 0;
		foreach ($clearIds as $companyId) {
			$upd = $this->db->getQueryBuilder();
			$upd->update('crm_company')
				->set($column, $upd->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
			$cleared += $upd->executeStatement();
		}

		if ($cleared > 0) {
			$output->info(sprintf(
				'CustomerCheck: cleared %d duplicate %s link(s) before unique index.',
				$cleared,
				$column,
			));
		}
	}
}
