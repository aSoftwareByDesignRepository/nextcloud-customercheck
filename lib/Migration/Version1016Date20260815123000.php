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
 * Zeus: draft smtp_acked so idempotency replay never fakes success before SMTP ACK.
 */
class Version1016Date20260815123000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('crm_mail_dft')) {
			return null;
		}
		$t = $schema->getTable('crm_mail_dft');
		if (!$t->hasColumn('smtp_acked')) {
			$t->addColumn('smtp_acked', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('crm_mail_dft') || !$schema->hasTable('crm_mail_msg')) {
			return;
		}
		if (!$schema->getTable('crm_mail_dft')->hasColumn('smtp_acked')) {
			return;
		}

		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id', 'workspace_id', 'idempotency_key')
			->from('crm_mail_dft')
			->where($qb->expr()->isNotNull('idempotency_key'))
			->andWhere($qb->expr()->eq('smtp_acked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(5000);
		$r = $qb->executeQuery();
		$candidates = [];
		while ($row = $r->fetch()) {
			$candidates[] = $row;
		}
		$r->closeCursor();

		foreach ($candidates as $row) {
			$id = (int)($row['id'] ?? 0);
			$ws = (int)($row['workspace_id'] ?? 0);
			$key = trim((string)($row['idempotency_key'] ?? ''));
			if ($id <= 0 || $ws <= 0 || $key === '') {
				continue;
			}
			$uid = 'outbound:' . $key;
			$check = $db->getQueryBuilder();
			$check->select('id')->from('crm_mail_msg')
				->where($check->expr()->eq('workspace_id', $check->createNamedParameter($ws, IQueryBuilder::PARAM_INT)))
				->andWhere($check->expr()->eq('message_uid', $check->createNamedParameter($uid)))
				->setMaxResults(1);
			$cr = $check->executeQuery();
			$found = $cr->fetch();
			$cr->closeCursor();
			if ($found === false) {
				continue;
			}
			$up = $db->getQueryBuilder();
			$up->update('crm_mail_dft')
				->set('smtp_acked', $up->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->where($up->expr()->eq('id', $up->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}
}
