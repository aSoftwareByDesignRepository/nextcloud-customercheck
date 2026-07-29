<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Companion P2 — mobile activity idempotency ledger + Expo push token registry.
 *
 * Tables stay short (Oracle-safe). Push tokens are stored hashed; raw tokens
 * never leave the register request path except for Expo delivery.
 */
class Version1002Date20260726183000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('crm_mob_idem')) {
			$t = $schema->createTable('crm_mob_idem');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('idem_key', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('activity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'crm_midem_pk');
			$t->addUniqueIndex(['uid', 'idem_key'], 'crm_midem_uq');
			$t->addIndex(['activity_id'], 'crm_midem_act_idx');
		}

		if (!$schema->hasTable('crm_push_tok')) {
			$t = $schema->createTable('crm_push_tok');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('token_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('token', Types::TEXT, ['notnull' => true]);
			$t->addColumn('platform', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'android']);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'crm_ptok_pk');
			$t->addUniqueIndex(['uid', 'token_hash'], 'crm_ptok_uq');
			$t->addIndex(['uid'], 'crm_ptok_uid_idx');
		}

		return $schema;
	}
}
