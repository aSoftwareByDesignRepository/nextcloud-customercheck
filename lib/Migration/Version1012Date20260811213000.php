<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Seed hardware/goods checklist template for installs that already ran Version1011
 * before hardware_order existed (CUC-DEAL-CHECKLIST-1 — product-agnostic deals).
 */
class Version1012Date20260811213000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('crm_cl_tmpl') || $this->systemTemplateExists('hardware_order')) {
			return;
		}

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('crm_cl_tmpl')
			->values([
				'workspace_id' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'tmpl_key' => $qb->createNamedParameter('hardware_order'),
				'name' => $qb->createNamedParameter('Hardware / goods order'),
				'name_de' => $qb->createNamedParameter('Hardware- / Warenauftrag'),
				'is_system' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'is_enabled' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now),
				'updated_at' => $qb->createNamedParameter($now),
			])
			->executeStatement();
		$templateId = (int)$this->db->lastInsertId('crm_cl_tmpl');
		$items = [
			['Enter / confirm order', 'Auftrag erfassen / bestätigen'],
			['Reserve / confirm stock', 'Bestand reservieren / bestätigen'],
			['Send invoice', 'Rechnung senden'],
			['Confirm paid', 'Zahlung bestätigen'],
			['Pack and ship', 'Kommissionieren und versenden'],
			['Confirm delivery (proof)', 'Lieferung bestätigen (Nachweis)'],
		];
		$sort = 0;
		foreach ($items as [$en, $de]) {
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
		$output->info('CustomerCheck: seeded checklist template hardware_order');
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
