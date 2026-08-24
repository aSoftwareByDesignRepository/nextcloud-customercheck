<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Deal rotting nudge ledger — exactly-once owner reminders per stage wave.
 */
class Version1022Date20260824214500 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('crm_dealrot')) {
			$t = $schema->createTable('crm_dealrot');
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$t->addColumn('deal_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$t->addColumn('snap_key', Types::STRING, [
				'notnull' => true,
				'length' => 48,
			]);
			$t->addColumn('sent_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$t->setPrimaryKey(['id'], 'crm_dr_pk');
			$t->addUniqueIndex(['deal_id', 'snap_key'], 'crm_dr_uniq');
			$t->addIndex(['deal_id'], 'crm_dr_deal');
		}

		return $schema;
	}
}
