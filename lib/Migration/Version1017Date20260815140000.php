<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Aristoteles 1.6.107 — SF-02 columns + SF-01/SF-05 data cleanup before unique indexes (Version1018).
 */
class Version1017Date20260815140000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('crm_mailbox')) {
			$t = $schema->getTable('crm_mailbox');
			if (!$t->hasColumn('sync_fail_streak')) {
				$t->addColumn('sync_fail_streak', Types::SMALLINT, [
					'notnull' => true,
					'default' => 0,
				]);
			}
			if (!$t->hasColumn('sync_cooldown_until')) {
				$t->addColumn('sync_cooldown_until', Types::DATETIME, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->normalizeAndDedupeMessageIds($db, $schema, $output);
		$this->retireDuplicatePersonalMailboxes($db, $schema, $output);
	}

	private function normalizeAndDedupeMessageIds(IDBConnection $db, ISchemaWrapper $schema, IOutput $output): void
	{
		if (!$schema->hasTable('crm_mail_msg') || !$db->tableExists('crm_mail_msg')) {
			return;
		}

		$qb = $db->getQueryBuilder();
		$qb->update('crm_mail_msg')
			->set('message_id', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('message_id', $qb->createNamedParameter('')))
			->executeStatement();

		$scan = $db->getQueryBuilder();
		$scan->select('id', 'mailbox_id', 'message_id')
			->from('crm_mail_msg')
			->where($scan->expr()->isNotNull('message_id'))
			->orderBy('id', 'ASC')
			->setMaxResults(50000);
		$r = $scan->executeQuery();
		$seen = [];
		$cleared = 0;
		while ($row = $r->fetch()) {
			$mid = trim((string)($row['message_id'] ?? ''));
			if ($mid === '') {
				continue;
			}
			$key = (int)$row['mailbox_id'] . "\0" . $mid;
			if (isset($seen[$key])) {
				$u = $db->getQueryBuilder();
				$u->update('crm_mail_msg')
					->set('message_id', $u->createNamedParameter(null))
					->where($u->expr()->eq('id', $u->createNamedParameter((int)$row['id'])))
					->executeStatement();
				$cleared++;
			} else {
				$seen[$key] = true;
			}
		}
		$r->closeCursor();
		$output->info('CustomerCheck SF-01: cleared duplicate message_id on ' . $cleared . ' row(s)');
	}

	private function retireDuplicatePersonalMailboxes(IDBConnection $db, ISchemaWrapper $schema, IOutput $output): void
	{
		if (!$schema->hasTable('crm_mailbox') || !$db->tableExists('crm_mailbox')) {
			return;
		}
		$qb = $db->getQueryBuilder();
		$qb->select('id', 'workspace_id', 'owner_user_id', 'kind', 'email_address')
			->from('crm_mailbox')
			->where($qb->expr()->isNotNull('owner_user_id'))
			->andWhere($qb->expr()->neq('owner_user_id', $qb->createNamedParameter('')))
			->orderBy('id', 'ASC')
			->setMaxResults(20000);
		$r = $qb->executeQuery();
		$keep = [];
		$retired = 0;
		while ($row = $r->fetch()) {
			$key = (int)$row['workspace_id'] . "\0" . (string)$row['owner_user_id'] . "\0" . (string)$row['kind'];
			$id = (int)$row['id'];
			if (!isset($keep[$key])) {
				$keep[$key] = $id;
				continue;
			}
			$email = (string)($row['email_address'] ?? '');
			$retiredEmail = 'dup-' . $id . '.' . $email;
			if (strlen($retiredEmail) > 250) {
				$retiredEmail = 'dup-' . $id . '@invalid.local';
			}
			$u = $db->getQueryBuilder();
			$u->update('crm_mailbox')
				->set('email_address', $u->createNamedParameter($retiredEmail))
				->set('status', $u->createNamedParameter('error'))
				->set('owner_user_id', $u->createNamedParameter(null))
				->set('last_error', $u->createNamedParameter('Duplicate personal mailbox retired by 1.6.107 migration'))
				->where($u->expr()->eq('id', $u->createNamedParameter($id)))
				->executeStatement();
			$retired++;
		}
		$r->closeCursor();
		$output->info('CustomerCheck SF-05: retired ' . $retired . ' duplicate personal mailbox row(s)');
	}
}
