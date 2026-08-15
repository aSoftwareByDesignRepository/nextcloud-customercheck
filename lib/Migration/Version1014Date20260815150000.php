<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Failed-send draft retains HTML body, signature toggle, and Files attachment ids (US-006 AC-2).
 */
class Version1014Date20260815150000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('crm_mail_dft')) {
			return null;
		}
		$t = $schema->getTable('crm_mail_dft');
		if (!$t->hasColumn('body_html')) {
			$t->addColumn('body_html', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('include_signature')) {
			$t->addColumn('include_signature', Types::SMALLINT, [
				'notnull' => true,
				'default' => 1,
			]);
		}
		if (!$t->hasColumn('attachment_file_ids')) {
			$t->addColumn('attachment_file_ids', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('quoted_text')) {
			$t->addColumn('quoted_text', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
