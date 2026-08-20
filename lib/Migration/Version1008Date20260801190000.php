<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GNQ Bordmittel delta — review inbox, edit conflicts, value-map, entity revisions.
 *
 * Spec: planning/app-ideas/customercheck/GNQ-BORDMITTEL-DELTA-SPEC.md §§5–7.
 * Logical table names ≤27 chars; short PK/index names; int 0/1 flags (no boolean notnull).
 */
class Version1008Date20260801190000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->createReviewItem($schema);
		$this->createValueMap($schema);
		$this->createEntityRev($schema);
		$this->createEditConflict($schema);
		$this->alterWsSet($schema);

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);
		if (!$db->tableExists('crm_mail_msg') || !$db->tableExists('crm_review_item')) {
			return;
		}

		$sel = $db->getQueryBuilder();
		$sel->select('id', 'workspace_id', 'subject', 'from_addr', 'snippet', 'sent_at', 'created_at')
			->from('crm_mail_msg')
			->where($sel->expr()->eq('triage_status', $sel->createNamedParameter('open')));
		$result = $sel->executeQuery();
		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$n = 0;
		while ($row = $result->fetch()) {
			$msgId = (int)$row['id'];
			$wsId = (int)$row['workspace_id'];
			$fingerprint = hash('sha256', 'shared_mail|' . $wsId . '|' . $msgId);
			$sourceRef = json_encode(['mailMsgId' => $msgId], JSON_THROW_ON_ERROR);
			$ins = $db->getQueryBuilder();
			try {
				$ins->insert('crm_review_item')
					->values([
						'workspace_id' => $ins->createNamedParameter($wsId, IQueryBuilder::PARAM_INT),
						'source_kind' => $ins->createNamedParameter('shared_mail'),
						'source_fingerprint' => $ins->createNamedParameter($fingerprint),
						'source_ref' => $ins->createNamedParameter($sourceRef),
						'subject' => $ins->createNamedParameter(mb_substr((string)($row['subject'] ?? ''), 0, 512)),
						'snippet' => $ins->createNamedParameter(mb_substr((string)($row['snippet'] ?? $row['from_addr'] ?? ''), 0, 2000)),
						'status' => $ins->createNamedParameter('open'),
						'overhead' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
						'created_at' => $ins->createNamedParameter((string)($row['created_at'] ?? $now)),
						'updated_at' => $ins->createNamedParameter($now),
					])
					->executeStatement();
				$n++;
			} catch (\Throwable) {
				// Unique fingerprint — already migrated.
			}
		}
		$result->closeCursor();
		$output->info('Backfilled ' . $n . ' open mail triage rows into crm_review_item');
	}

	private function createReviewItem(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_review_item')) {
			$t = $schema->createTable('crm_review_item');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('source_kind', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('source_fingerprint', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('source_ref', Types::TEXT, ['notnull' => true]);
			$t->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('snippet', Types::STRING, ['notnull' => false, 'length' => 2000]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('snooze_until', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('deal_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('overhead', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('suggestion_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('assigned_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('assigned_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('resolution_note', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_ri_pk');
			$t->addUniqueIndex(['workspace_id', 'source_kind', 'source_fingerprint'], 'crm_ri_fp_uq');
			$t->addIndex(['workspace_id', 'status'], 'crm_ri_ws_st_idx');
			$t->addIndex(['workspace_id', 'source_kind'], 'crm_ri_ws_sk_idx');
		}
	}

	private function createValueMap(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_value_map')) {
			$t = $schema->createTable('crm_value_map');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('field_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('raw_value_norm', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('canonical_value', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_vm_pk');
			$t->addUniqueIndex(['workspace_id', 'field_key', 'raw_value_norm'], 'crm_vm_uq');
		}
	}

	private function createEntityRev(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_entity_rev')) {
			$t = $schema->createTable('crm_entity_rev');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('entity_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('entity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('snapshot_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_er_pk');
			$t->addIndex(['entity_type', 'entity_id', 'updated_at'], 'crm_er_ent_idx');
			$t->addIndex(['workspace_id', 'created_at'], 'crm_er_ws_idx');
		}
	}

	private function createEditConflict(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_edit_conflict')) {
			$t = $schema->createTable('crm_edit_conflict');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('entity_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('entity_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('base_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('current_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('incoming_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('fields_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('actor_a', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('actor_b', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('resolved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('resolved_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('row_version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_ec_pk');
			$t->addIndex(['workspace_id', 'status'], 'crm_ec_ws_st_idx');
			$t->addIndex(['entity_type', 'entity_id'], 'crm_ec_ent_idx');
		}
	}

	private function alterWsSet(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_ws_set')) {
			return;
		}
		$t = $schema->getTable('crm_ws_set');
		if (!$t->hasColumn('review_sources_json')) {
			$t->addColumn('review_sources_json', Types::TEXT, ['notnull' => false]);
		}
	}
}
