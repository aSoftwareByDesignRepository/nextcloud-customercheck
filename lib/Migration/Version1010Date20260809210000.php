<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Deal notes — free-text team notes on quotes/opportunities (≤4000 chars in service).
 */
class Version1010Date20260809210000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('crm_deal')) {
			$t = $schema->getTable('crm_deal');
			if (!$t->hasColumn('notes')) {
				$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			}
		}

		return $schema;
	}
}
