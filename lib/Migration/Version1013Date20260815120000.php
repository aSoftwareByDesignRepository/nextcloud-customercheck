<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Personal Email Sync — per-mailbox signature (Q12 / MH-17).
 */
class Version1013Date20260815120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('crm_mailbox')) {
			return null;
		}
		$t = $schema->getTable('crm_mailbox');
		if (!$t->hasColumn('signature_html')) {
			$t->addColumn('signature_html', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('signature_plain')) {
			$t->addColumn('signature_plain', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('signature_updated_at')) {
			$t->addColumn('signature_updated_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$t->hasColumn('signature_updated_by')) {
			$t->addColumn('signature_updated_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}

		return $schema;
	}
}
