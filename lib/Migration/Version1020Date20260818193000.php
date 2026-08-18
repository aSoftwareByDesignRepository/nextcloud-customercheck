<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Deal primary contact + lead source (Pipedrive/SF parity).
 */
class Version1020Date20260818193000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('crm_deal')) {
			return $schema;
		}

		$t = $schema->getTable('crm_deal');
		if (!$t->hasColumn('person_id')) {
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => false]);
		}
		if (!$t->hasColumn('lead_source')) {
			$t->addColumn('lead_source', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
		}
		if (!$t->hasIndex('crm_deal_per_idx')) {
			$t->addIndex(['company_id', 'person_id'], 'crm_deal_per_idx');
		}

		return $schema;
	}
}
