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
 * Personal Email Sync M1.1 — extend mailbox/folder/msg + workspace gate.
 *
 * Spec: planning/app-ideas/customercheck/PERSONAL-EMAIL-SYNC-SPEC.md
 * In-place extend of crm_mailbox as account (kind personal|team) — AS-10.
 */
class Version1009Date20260815093000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->alterMailbox($schema);
		$this->alterMailFld($schema);
		$this->alterMailMsg($schema);
		$this->alterWsSet($schema);

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);

		if ($db->tableExists('crm_mailbox')) {
			$qb = $db->getQueryBuilder();
			$qb->update('crm_mailbox')
				->set('kind', $qb->createNamedParameter('team'))
				->set('auth_kind', $qb->createNamedParameter('imap'))
				->where($qb->expr()->orX(
					$qb->expr()->isNull('kind'),
					$qb->expr()->eq('kind', $qb->createNamedParameter('')),
				))
				->executeStatement();
		}

		if ($db->tableExists('crm_mail_fld')) {
			$qb = $db->getQueryBuilder();
			// Existing folders belong to Team Inboxes — treat as workspace-shared.
			$qb->update('crm_mail_fld')
				->set('share_with_workspace', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('share_with_workspace', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		if ($db->tableExists('crm_mail_msg')) {
			$qb = $db->getQueryBuilder();
			$qb->update('crm_mail_msg')
				->set('visibility', $qb->createNamedParameter('shared'))
				->where($qb->expr()->orX(
					$qb->expr()->isNull('visibility'),
					$qb->expr()->eq('visibility', $qb->createNamedParameter('')),
				))
				->executeStatement();
		}

		$output->info('CustomerCheck personal-mail schema backfill: team mailboxes + shared visibility');
	}

	private function alterMailbox(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_mailbox')) {
			return;
		}
		$t = $schema->getTable('crm_mailbox');
		if (!$t->hasColumn('kind')) {
			$t->addColumn('kind', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'team',
			]);
		}
		if (!$t->hasColumn('owner_user_id')) {
			$t->addColumn('owner_user_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}
		if (!$t->hasColumn('auth_kind')) {
			$t->addColumn('auth_kind', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'imap',
			]);
		}
		if (!$t->hasColumn('token_enc')) {
			$t->addColumn('token_enc', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('refresh_enc')) {
			$t->addColumn('refresh_enc', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('token_expires_at')) {
			$t->addColumn('token_expires_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$t->hasIndex('crm_mb_own_idx')) {
			$t->addIndex(['workspace_id', 'owner_user_id'], 'crm_mb_own_idx');
		}
		if (!$t->hasIndex('crm_mb_em_uq')) {
			$t->addUniqueIndex(['workspace_id', 'email_address'], 'crm_mb_em_uq');
		}
	}

	private function alterMailFld(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_mail_fld')) {
			return;
		}
		$t = $schema->getTable('crm_mail_fld');
		if (!$t->hasColumn('share_with_workspace')) {
			$t->addColumn('share_with_workspace', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
		}
		if (!$t->hasColumn('share_ack_at')) {
			$t->addColumn('share_ack_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$t->hasColumn('share_ack_by')) {
			$t->addColumn('share_ack_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}
	}

	private function alterMailMsg(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_mail_msg')) {
			return;
		}
		$t = $schema->getTable('crm_mail_msg');
		if (!$t->hasColumn('visibility')) {
			$t->addColumn('visibility', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'shared',
			]);
		}
		if (!$t->hasColumn('owner_user_id')) {
			$t->addColumn('owner_user_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}
		if (!$t->hasColumn('body_text')) {
			$t->addColumn('body_text', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('body_html')) {
			$t->addColumn('body_html', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('cc_addrs')) {
			$t->addColumn('cc_addrs', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasColumn('forward_from_hints')) {
			$t->addColumn('forward_from_hints', Types::TEXT, ['notnull' => false]);
		}
		if (!$t->hasIndex('crm_mm_vis_idx')) {
			$t->addIndex(['workspace_id', 'visibility', 'owner_user_id'], 'crm_mm_vis_idx');
		}
	}

	private function alterWsSet(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_ws_set')) {
			return;
		}
		$t = $schema->getTable('crm_ws_set');
		if (!$t->hasColumn('personal_mail_enabled')) {
			$t->addColumn('personal_mail_enabled', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
		}
		if (!$t->hasColumn('personal_mail_dsgvo_at')) {
			$t->addColumn('personal_mail_dsgvo_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$t->hasColumn('personal_mail_dsgvo_by')) {
			$t->addColumn('personal_mail_dsgvo_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}
	}
}
