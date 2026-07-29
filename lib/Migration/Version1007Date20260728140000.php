<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Digest v2: exactly-once per (week_key, workspace) — product §10.5.
 */
class Version1007Date20260728140000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('crm_digest_log')) {
			return $schema;
		}
		$t = $schema->getTable('crm_digest_log');
		if (!$t->hasColumn('workspace_id')) {
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		}
		if ($t->hasIndex('crm_dg_week_uq')) {
			$t->dropIndex('crm_dg_week_uq');
		}
		if (!$t->hasIndex('crm_dg_wk_ws_uq')) {
			$t->addUniqueIndex(['week_key', 'workspace_id'], 'crm_dg_wk_ws_uq');
		}
		return $schema;
	}
}
