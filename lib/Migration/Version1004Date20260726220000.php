<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Companion P2 — exactly-once ledger for activity-due notifications / push.
 *
 * Unique (activity_id, due_date_snap) mirrors crm_remlog’s agreement cadence
 * contract so job reruns never double-notify.
 */
class Version1004Date20260726220000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('crm_actdue')) {
			$t = $schema->createTable('crm_actdue');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('activity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('due_date_snap', Types::DATE, ['notnull' => true]);
			$t->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_actdue_pk');
			$t->addUniqueIndex(['activity_id', 'due_date_snap'], 'crm_ad_uq');
			$t->addIndex(['due_date_snap'], 'crm_ad_due_idx');
		}

		return $schema;
	}
}
