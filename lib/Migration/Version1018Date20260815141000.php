<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Aristoteles 1.6.107 — unique indexes after Version1017 cleanup (SF-01 Message-ID, SF-05 owner+kind).
 */
class Version1018Date20260815141000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('crm_mail_msg')) {
			$t = $schema->getTable('crm_mail_msg');
			if (!$t->hasIndex('crm_mm_mid_uq')) {
				$t->addUniqueIndex(['mailbox_id', 'message_id'], 'crm_mm_mid_uq');
			}
		}

		if ($schema->hasTable('crm_mailbox')) {
			$t = $schema->getTable('crm_mailbox');
			if (!$t->hasIndex('crm_mb_own_kind_uq')) {
				$t->addUniqueIndex(['workspace_id', 'owner_user_id', 'kind'], 'crm_mb_own_kind_uq');
			}
		}

		return $schema;
	}
}
