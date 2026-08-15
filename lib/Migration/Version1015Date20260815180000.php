<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Outbound CRM mail index metadata (spec §9) — attachment meta JSON + signature flag (NN-12).
 */
class Version1015Date20260815180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('crm_mail_msg')) {
			return null;
		}
		$t = $schema->getTable('crm_mail_msg');
		if (!$t->hasColumn('attachment_meta_json')) {
			$t->addColumn('attachment_meta_json', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('signature_included')) {
			$t->addColumn('signature_included', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
		}

		return $schema;
	}
}
