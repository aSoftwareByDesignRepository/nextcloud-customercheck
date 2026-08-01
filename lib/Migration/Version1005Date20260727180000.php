<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * M5 Command Center — files folder link, callback flag, contacts soft-link, activity person.
 *
 * COMMAND-CENTER-SPEC §10.1. Idempotent column/index adds only.
 */
class Version1005Date20260727180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('crm_company')) {
			$t = $schema->getTable('crm_company');
			if (!$t->hasColumn('files_folder_id')) {
				$t->addColumn('files_folder_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasColumn('files_folder_path')) {
				$t->addColumn('files_folder_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
			}
		}

		if ($schema->hasTable('crm_activity')) {
			$t = $schema->getTable('crm_activity');
			if (!$t->hasColumn('is_callback')) {
				// Integer 0/1 — never boolean notnull (Oracle-safe).
				$t->addColumn('is_callback', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if (!$t->hasColumn('person_id')) {
				$t->addColumn('person_id', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasIndex('crm_act_cb_due_idx')) {
				$t->addIndex(['is_callback', 'done_at', 'due_date'], 'crm_act_cb_due_idx');
			}
			if (!$t->hasIndex('crm_act_person_idx')) {
				$t->addIndex(['person_id'], 'crm_act_person_idx');
			}
		}

		if ($schema->hasTable('crm_person')) {
			$t = $schema->getTable('crm_person');
			if (!$t->hasColumn('contacts_uid')) {
				$t->addColumn('contacts_uid', Types::STRING, ['notnull' => false, 'length' => 255]);
			}
			if (!$t->hasIndex('crm_pe_ct_uid_idx')) {
				$t->addIndex(['contacts_uid'], 'crm_pe_ct_uid_idx');
			}
		}

		return $schema;
	}
}
