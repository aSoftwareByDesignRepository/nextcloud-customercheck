<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Workspaces + persona OS + shared mail + coverage/chase/brief/coaching schema.
 *
 * Product bible §5–§14. Logical names ≤27 chars; short index/PK names; int 0/1 flags.
 */
class Version1006Date20260728120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->createWorkspace($schema);
		$this->createWsMember($schema);
		$this->createWsSet($schema);
		$this->createMailbox($schema);
		$this->createMailFld($schema);
		$this->createMailMsg($schema);
		$this->createMailDft($schema);
		$this->createAcctPlan($schema);
		$this->createPlanMs($schema);
		$this->createMapStep($schema);
		$this->createCustPrm($schema);
		$this->createCommit($schema);
		$this->createEscal($schema);
		$this->createOoo($schema);
		$this->createOneOnOne($schema);
		$this->alterCompany($schema);
		$this->alterPerson($schema);
		$this->alterDeal($schema);

		return $schema;
	}

	private function createWorkspace(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_workspace')) {
			$t = $schema->createTable('crm_workspace');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('is_default', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('archived_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_ws_pk');
			$t->addUniqueIndex(['slug'], 'crm_ws_slug_uq');
		}
	}

	private function createWsMember(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_ws_member')) {
			$t = $schema->createTable('crm_ws_member');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'member']);
			$t->addColumn('can_mail_send', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_wsm_pk');
			$t->addUniqueIndex(['workspace_id', 'user_id'], 'crm_wsm_uq');
			$t->addIndex(['user_id'], 'crm_wsm_uid_idx');
		}
	}

	private function createWsSet(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_ws_set')) {
			$t = $schema->createTable('crm_ws_set');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('wip_limit', Types::INTEGER, ['notnull' => true, 'default' => 5]);
			$t->addColumn('lead_sla_hours', Types::INTEGER, ['notnull' => true, 'default' => 24]);
			$t->addColumn('stuck_chase_days', Types::INTEGER, ['notnull' => true, 'default' => 3]);
			$t->addColumn('mail_retention_days', Types::INTEGER, ['notnull' => true, 'default' => 365]);
			$t->addColumn('digest_enabled', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_wss_pk');
			$t->addUniqueIndex(['workspace_id'], 'crm_wss_ws_uq');
		}
	}

	private function createMailbox(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_mailbox')) {
			$t = $schema->createTable('crm_mailbox');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('email_address', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('imap_host', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('imap_port', Types::INTEGER, ['notnull' => true, 'default' => 993]);
			$t->addColumn('imap_enc', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'ssl']);
			$t->addColumn('smtp_host', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('smtp_port', Types::INTEGER, ['notnull' => true, 'default' => 587]);
			$t->addColumn('smtp_enc', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'tls']);
			$t->addColumn('username', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('secret_enc', Types::TEXT, ['notnull' => true]);
			$t->addColumn('dsgvo_ack_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('dsgvo_ack_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'ok']);
			$t->addColumn('auth_fail_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_sync_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('last_error', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_mb_pk');
			$t->addIndex(['workspace_id'], 'crm_mb_ws_idx');
		}
	}

	private function createMailFld(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_mail_fld')) {
			$t = $schema->createTable('crm_mail_fld');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('mailbox_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('folder_path', Types::STRING, ['notnull' => true, 'length' => 512]);
			$t->addColumn('sync_enabled', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_mf_pk');
			$t->addUniqueIndex(['mailbox_id', 'folder_path'], 'crm_mf_uq');
		}
	}

	private function createMailMsg(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_mail_msg')) {
			$t = $schema->createTable('crm_mail_msg');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('mailbox_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('folder_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('message_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('message_id', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('direction', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'inbound']);
			$t->addColumn('from_addr', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('to_addrs', Types::TEXT, ['notnull' => false]);
			$t->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('snippet', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('preview', Types::TEXT, ['notnull' => false]);
			$t->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('matched_how', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('triage_status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_mm_pk');
			$t->addUniqueIndex(['mailbox_id', 'folder_id', 'message_uid'], 'crm_mm_uid_uq');
			$t->addIndex(['workspace_id', 'company_id'], 'crm_mm_co_idx');
			$t->addIndex(['workspace_id', 'triage_status'], 'crm_mm_tri_idx');
		}
	}

	private function createMailDft(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_mail_dft')) {
			$t = $schema->createTable('crm_mail_dft');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('mailbox_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('to_addrs', Types::TEXT, ['notnull' => false]);
			$t->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 512]);
			$t->addColumn('body_text', Types::TEXT, ['notnull' => false]);
			$t->addColumn('idempotency_key', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_md_pk');
			$t->addIndex(['workspace_id'], 'crm_md_ws_idx');
			$t->addUniqueIndex(['workspace_id', 'idempotency_key'], 'crm_md_idem_uq');
		}
	}

	private function createAcctPlan(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_acct_plan')) {
			$t = $schema->createTable('crm_acct_plan');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('horizon', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('goal', Types::TEXT, ['notnull' => false]);
			$t->addColumn('priority', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'p2']);
			$t->addColumn('owner_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('next_review_on', Types::DATE, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_ap_pk');
			$t->addUniqueIndex(['company_id'], 'crm_ap_co_uq');
			$t->addIndex(['workspace_id', 'owner_user_id'], 'crm_ap_own_idx');
		}
	}

	private function createPlanMs(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_plan_ms')) {
			$t = $schema->createTable('crm_plan_ms');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('plan_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('due_on', Types::DATE, ['notnull' => false]);
			$t->addColumn('done_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_pms_pk');
			$t->addIndex(['plan_id'], 'crm_pms_plan_idx');
		}
	}

	private function createMapStep(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_map_step')) {
			$t = $schema->createTable('crm_map_step');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('plan_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_map_pk');
			$t->addIndex(['plan_id'], 'crm_map_plan_idx');
		}
	}

	private function createCustPrm(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_cust_prm')) {
			$t = $schema->createTable('crm_cust_prm');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('due_on', Types::DATE, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('owner_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('evidence', Types::TEXT, ['notnull' => false]);
			$t->addColumn('done_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_cp_pk');
			$t->addIndex(['workspace_id', 'status', 'due_on'], 'crm_cp_due_idx');
			$t->addIndex(['company_id'], 'crm_cp_co_idx');
		}
	}

	private function createCommit(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_commit')) {
			$t = $schema->createTable('crm_commit');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('week_key', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('owner_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'suggested']);
			$t->addColumn('due_on', Types::DATE, ['notnull' => false]);
			$t->addColumn('carry_streak', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('evidence', Types::TEXT, ['notnull' => false]);
			$t->addColumn('verify_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('counts_wip', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_cm_pk');
			$t->addIndex(['workspace_id', 'week_key', 'status'], 'crm_cm_wk_idx');
			$t->addIndex(['owner_user_id', 'status'], 'crm_cm_own_idx');
		}
	}

	private function createEscal(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_escal')) {
			$t = $schema->createTable('crm_escal');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('subject_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('owner_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('reason', Types::TEXT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('deal_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('resolved_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_esc_pk');
			$t->addIndex(['workspace_id', 'status'], 'crm_esc_st_idx');
		}
	}

	private function createOoo(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_ooo')) {
			$t = $schema->createTable('crm_ooo');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('primary_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('backup_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('starts_on', Types::DATE, ['notnull' => true]);
			$t->addColumn('ends_on', Types::DATE, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'scheduled']);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_ooo_pk');
			$t->addIndex(['workspace_id', 'primary_user_id'], 'crm_ooo_pri_idx');
			$t->addIndex(['workspace_id', 'backup_user_id'], 'crm_ooo_bak_idx');
		}
	}

	private function createOneOnOne(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_1on1')) {
			$t = $schema->createTable('crm_1on1');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('manager_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('report_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('meeting_on', Types::DATE, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_1on1_pk');
			$t->addIndex(['workspace_id', 'report_user_id'], 'crm_1on1_rpt_idx');
		}
	}

	private function alterCompany(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_company')) {
			return;
		}
		$t = $schema->getTable('crm_company');
		if (!$t->hasColumn('workspace_id')) {
			// Nullable until BootstrapDefaultWorkspace backfills; then treated as required.
			$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => false]);
		}
		if (!$t->hasColumn('kam_tier')) {
			$t->addColumn('kam_tier', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'standard']);
		}
		if (!$t->hasColumn('kam_tier_lock')) {
			$t->addColumn('kam_tier_lock', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		}
		if (!$t->hasColumn('account_owner_user_id')) {
			$t->addColumn('account_owner_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		if (!$t->hasColumn('touch_interval_days')) {
			$t->addColumn('touch_interval_days', Types::INTEGER, ['notnull' => false]);
		}
		if (!$t->hasColumn('next_touch_on')) {
			$t->addColumn('next_touch_on', Types::DATE, ['notnull' => false]);
		}
		if (!$t->hasIndex('crm_co_ws_idx')) {
			$t->addIndex(['workspace_id'], 'crm_co_ws_idx');
		}
		if (!$t->hasIndex('crm_co_owner_idx')) {
			$t->addIndex(['account_owner_user_id'], 'crm_co_owner_idx');
		}
		if (!$t->hasIndex('crm_co_touch_idx')) {
			$t->addIndex(['next_touch_on'], 'crm_co_touch_idx');
		}
	}

	private function alterPerson(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_person')) {
			return;
		}
		$t = $schema->getTable('crm_person');
		if (!$t->hasColumn('buying_role')) {
			$t->addColumn('buying_role', Types::STRING, ['notnull' => false, 'length' => 32]);
		}
		if (!$t->hasColumn('status')) {
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
		}
		if (!$t->hasColumn('reports_to_person_id')) {
			$t->addColumn('reports_to_person_id', Types::BIGINT, ['notnull' => false]);
		}
		if (!$t->hasIndex('crm_pe_buy_idx')) {
			$t->addIndex(['company_id', 'buying_role'], 'crm_pe_buy_idx');
		}
	}

	private function alterDeal(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_deal')) {
			return;
		}
		$t = $schema->getTable('crm_deal');
		if (!$t->hasColumn('owner_user_id')) {
			$t->addColumn('owner_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		if (!$t->hasColumn('quote_sent_on')) {
			$t->addColumn('quote_sent_on', Types::DATE, ['notnull' => false]);
		}
		if (!$t->hasColumn('quote_file_id')) {
			$t->addColumn('quote_file_id', Types::BIGINT, ['notnull' => false]);
		}
		if (!$t->hasColumn('forecast_bucket')) {
			$t->addColumn('forecast_bucket', Types::STRING, ['notnull' => false, 'length' => 16]);
		}
		if (!$t->hasColumn('lost_reason_code')) {
			$t->addColumn('lost_reason_code', Types::STRING, ['notnull' => false, 'length' => 32]);
		}
		if (!$t->hasColumn('won_reason_code')) {
			$t->addColumn('won_reason_code', Types::STRING, ['notnull' => false, 'length' => 32]);
		}
		if (!$t->hasColumn('lead_first_touch_at')) {
			$t->addColumn('lead_first_touch_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$t->hasColumn('lead_sla_due_at')) {
			$t->addColumn('lead_sla_due_at', Types::DATETIME, ['notnull' => false]);
		}
		if (!$t->hasColumn('next_action_skip_until')) {
			$t->addColumn('next_action_skip_until', Types::DATE, ['notnull' => false]);
		}
		if (!$t->hasIndex('crm_deal_own_idx')) {
			$t->addIndex(['owner_user_id', 'stage'], 'crm_deal_own_idx');
		}
	}
}
