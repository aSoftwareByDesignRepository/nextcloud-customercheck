<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Activity assignee — colleague ownership for to-dos + targeted due reminders.
 *
 * Red-team: assignee must be validated as a workspace member at write time
 * (ActivityService). Existing NULL rows keep workspace-wide due fan-out.
 */
class Version1021Date20260824211500 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('crm_activity')) {
			$t = $schema->getTable('crm_activity');
			if (!$t->hasColumn('assignee_user_id')) {
				$t->addColumn('assignee_user_id', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
			}
			if (!$t->hasIndex('crm_ac_asn_idx')) {
				$t->addIndex(['assignee_user_id', 'done_at', 'due_date'], 'crm_ac_asn_idx');
			}
		}

		return $schema;
	}
}
