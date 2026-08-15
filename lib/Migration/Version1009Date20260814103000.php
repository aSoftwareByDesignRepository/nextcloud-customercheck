<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Persist forward-body sender hints on indexed mail for Assign rematch.
 */
class Version1009Date20260814103000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('crm_mail_msg')) {
			return null;
		}
		$table = $schema->getTable('crm_mail_msg');
		if (!$table->hasColumn('forward_from_hints')) {
			$table->addColumn('forward_from_hints', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
