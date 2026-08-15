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
 * Zeus SF-04 — indexed mail_msg_id on Assign rows for O(1) inbox close (no full open-mail scan).
 */
class Version1019Date20260815193000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('crm_review_item')) {
			return $schema;
		}
		$t = $schema->getTable('crm_review_item');
		if (!$t->hasColumn('mail_msg_id')) {
			$t->addColumn('mail_msg_id', 'bigint', [
				'notnull' => false,
				'default' => null,
			]);
		}
		if (!$t->hasIndex('crm_ri_mmid')) {
			$t->addIndex(['workspace_id', 'mail_msg_id'], 'crm_ri_mmid');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('crm_review_item')) {
			return;
		}
		// Backfill from source_ref JSON (portable PHP parse — avoid MySQL-only JSON_EXTRACT).
		$afterId = 0;
		$page = 500;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'source_ref')
				->from('crm_review_item')
				->where($qb->expr()->isNull('mail_msg_id'))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in(
					'source_kind',
					$qb->createNamedParameter(
						['shared_mail', 'personal_mail_shared_unmatched'],
						IQueryBuilder::PARAM_STR_ARRAY,
					),
				))
				->orderBy('id', 'ASC')
				->setMaxResults($page);
			$r = $qb->executeQuery();
			$rows = [];
			while ($row = $r->fetch()) {
				$rows[] = $row;
			}
			$r->closeCursor();
			if ($rows === []) {
				break;
			}
			foreach ($rows as $row) {
				$id = (int)$row['id'];
				$afterId = max($afterId, $id);
				$ref = json_decode((string)($row['source_ref'] ?? ''), true);
				$mailId = is_array($ref) ? (int)($ref['mailMsgId'] ?? 0) : 0;
				if ($mailId <= 0) {
					continue;
				}
				$upd = $this->db->getQueryBuilder();
				$upd->update('crm_review_item')
					->set('mail_msg_id', $upd->createNamedParameter($mailId, IQueryBuilder::PARAM_INT))
					->where($upd->expr()->eq('id', $upd->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
					->andWhere($upd->expr()->isNull('mail_msg_id'))
					->executeStatement();
			}
		} while (count($rows) === $page);
	}
}
