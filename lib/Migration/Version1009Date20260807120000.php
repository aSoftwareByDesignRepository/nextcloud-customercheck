<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Shared-identity W1 — createOrLink Idempotency-Key ledger (≥24h replay).
 *
 * Logical table `crm_link_idem` ≤27 chars; short PK/unique names.
 */
class Version1009Date20260807120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('crm_link_idem')) {
			$t = $schema->createTable('crm_link_idem');
			$t->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('uid', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('idem_key', 'string', [
				'notnull' => true,
				'length' => 128,
			]);
			$t->addColumn('sibling', 'string', [
				'notnull' => true,
				'length' => 8,
			]);
			$t->addColumn('company_id', 'bigint', [
				'notnull' => true,
			]);
			$t->addColumn('sibling_id', 'bigint', [
				'notnull' => true,
			]);
			$t->addColumn('created', 'boolean', [
				'notnull' => false,
				'default' => false,
			]);
			$t->addColumn('created_at', 'bigint', [
				'notnull' => true,
			]);
			$t->setPrimaryKey(['id'], 'crm_lidem_pk');
			$t->addUniqueIndex(['uid', 'idem_key', 'sibling'], 'crm_lidem_uq');
			$t->addIndex(['created_at'], 'crm_lidem_exp_idx');
		}

		return $schema;
	}
}
