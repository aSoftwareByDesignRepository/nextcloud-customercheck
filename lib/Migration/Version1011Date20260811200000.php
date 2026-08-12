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
 * Deal checklists — templates + items + file links on crm_deal (CUC-DEAL-CHECKLIST-1).
 */
class Version1011Date20260811200000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('crm_cl_tmpl')) {
			$t = $schema->createTable('crm_cl_tmpl');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('tmpl_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 120]);
			$t->addColumn('name_de', Types::STRING, ['notnull' => false, 'length' => 120]);
			$t->addColumn('is_system', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('is_enabled', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'crm_clt_pk');
			$t->addUniqueIndex(['workspace_id', 'tmpl_key'], 'crm_clt_ws_key');
			$t->addIndex(['workspace_id', 'is_enabled'], 'crm_clt_ws_en');
		}

		if (!$schema->hasTable('crm_cl_tmpl_item')) {
			$t = $schema->createTable('crm_cl_tmpl_item');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('template_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 160]);
			$t->addColumn('title_de', Types::STRING, ['notnull' => false, 'length' => 160]);
			$t->addColumn('instructions', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->setPrimaryKey(['id'], 'crm_clti_pk');
			$t->addIndex(['template_id', 'sort_order'], 'crm_clti_tmpl');
		}

		if (!$schema->hasTable('crm_deal_cl_item')) {
			$t = $schema->createTable('crm_deal_cl_item');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('deal_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 160]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('assignee_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('due_on', Types::DATE, ['notnull' => false]);
			$t->addColumn('evidence_note', Types::STRING, ['notnull' => false, 'length' => 2000]);
			$t->addColumn('done_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('done_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'crm_dcli_pk');
			$t->addIndex(['deal_id', 'sort_order'], 'crm_dcli_deal');
			$t->addIndex(['workspace_id', 'assignee_user_id', 'status'], 'crm_dcli_assn');
			$t->addIndex(['company_id', 'status'], 'crm_dcli_co');
		}

		if (!$schema->hasTable('crm_deal_cl_file')) {
			$t = $schema->createTable('crm_deal_cl_file');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('name_snap', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'crm_dclf_pk');
			$t->addUniqueIndex(['item_id', 'file_id'], 'crm_dclf_uniq');
			$t->addIndex(['item_id'], 'crm_dclf_item');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('crm_cl_tmpl')) {
			return;
		}

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$templates = [
			[
				'key' => 'license_order',
				'name' => 'License order',
				'name_de' => 'Lizenzauftrag',
				'items' => [
					['Enter / confirm order', 'Auftrag erfassen / bestätigen'],
					['Send invoice', 'Rechnung senden'],
					['Confirm paid', 'Zahlung bestätigen'],
					['Issue license', 'Lizenz ausstellen'],
					['Send delivery note to customer', 'Lieferhinweis an Kunden senden'],
				],
			],
			[
				'key' => 'consulting_order',
				'name' => 'Consulting order',
				'name_de' => 'Beratungsauftrag',
				'items' => [
					['Enter / confirm order', 'Auftrag erfassen / bestätigen'],
					['Send invoice', 'Rechnung senden'],
					['Confirm paid', 'Zahlung bestätigen'],
					['Open / link project', 'Projekt anlegen / verknüpfen'],
					['Schedule kickoff', 'Kick-off planen'],
				],
			],
			[
				'key' => 'project_order',
				'name' => 'Project order',
				'name_de' => 'Projektauftrag',
				'items' => [
					['Enter / confirm order', 'Auftrag erfassen / bestätigen'],
					['Send invoice', 'Rechnung senden'],
					['Confirm paid', 'Zahlung bestätigen'],
					['Create / link project', 'Projekt anlegen / verknüpfen'],
					['Assign internal owner', 'Internen Verantwortlichen zuweisen'],
				],
			],
			[
				'key' => 'hardware_order',
				'name' => 'Hardware / goods order',
				'name_de' => 'Hardware- / Warenauftrag',
				'items' => [
					['Enter / confirm order', 'Auftrag erfassen / bestätigen'],
					['Reserve / confirm stock', 'Bestand reservieren / bestätigen'],
					['Send invoice', 'Rechnung senden'],
					['Confirm paid', 'Zahlung bestätigen'],
					['Pack and ship', 'Kommissionieren und versenden'],
					['Confirm delivery (proof)', 'Lieferung bestätigen (Nachweis)'],
				],
			],
		];

		foreach ($templates as $tmpl) {
			if ($this->systemTemplateExists($tmpl['key'])) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->insert('crm_cl_tmpl')
				->values([
					'workspace_id' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'tmpl_key' => $qb->createNamedParameter($tmpl['key']),
					'name' => $qb->createNamedParameter($tmpl['name']),
					'name_de' => $qb->createNamedParameter($tmpl['name_de']),
					'is_system' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'is_enabled' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now),
					'updated_at' => $qb->createNamedParameter($now),
				])
				->executeStatement();
			$templateId = (int)$this->db->lastInsertId('crm_cl_tmpl');
			$sort = 0;
			foreach ($tmpl['items'] as [$en, $de]) {
				$iq = $this->db->getQueryBuilder();
				$iq->insert('crm_cl_tmpl_item')
					->values([
						'template_id' => $iq->createNamedParameter($templateId, IQueryBuilder::PARAM_INT),
						'sort_order' => $iq->createNamedParameter($sort, IQueryBuilder::PARAM_INT),
						'title' => $iq->createNamedParameter($en),
						'title_de' => $iq->createNamedParameter($de),
						'instructions' => $iq->createNamedParameter(null),
					])
					->executeStatement();
				$sort++;
			}
			$output->info('CustomerCheck: seeded checklist template ' . $tmpl['key']);
		}
	}

	private function systemTemplateExists(string $key): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('crm_cl_tmpl')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('tmpl_key', $qb->createNamedParameter($key)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}
}
