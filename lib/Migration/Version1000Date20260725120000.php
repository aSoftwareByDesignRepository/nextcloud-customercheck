<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CustomerCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial CustomerCheck schema — the complete physical table inventory of PSD §4.0.
 *
 * Portability rules (nextcloud-db-standards): logical table names ≤ 27 chars, explicit
 * short primary-key and index names, every `createTable` behind a `hasTable` guard,
 * no `notnull` booleans (integer 0/1 flags instead), money as bigint minor units,
 * lifecycle dates as `date` and audit stamps as `datetime` in server timezone (D3).
 */
class Version1000Date20260725120000 extends SimpleMigrationStep
{
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->createCompany($schema);
		$this->createPerson($schema);
		$this->createAgreement($schema);
		$this->createDeal($schema);
		$this->createActivity($schema);
		$this->createHours($schema);
		$this->createEvent($schema);
		$this->createRemlog($schema);
		$this->createEmailTouch($schema);
		$this->createDigestLog($schema);
		$this->createSeqLog($schema);
		$this->createPayWatch($schema);
		$this->createImportJob($schema);

		return $schema;
	}

	private function createCompany(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_company')) {
			$t = $schema->createTable('crm_company');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('vat_id', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('addr_street', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('addr_zip', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('addr_city', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('country', Types::STRING, ['notnull' => true, 'length' => 2, 'default' => 'DE']);
			$t->addColumn('lang', Types::STRING, ['notnull' => true, 'length' => 2, 'default' => 'de']);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'lead']);
			$t->addColumn('website', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('pc_customer_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('tc_customer_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('archived_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_company_pk');
			$t->addUniqueIndex(['slug'], 'crm_co_slug_uq');
			$t->addIndex(['status'], 'crm_co_status_idx');
			$t->addIndex(['name'], 'crm_co_name_idx');
			$t->addIndex(['archived_at'], 'crm_co_arch_idx');
		}
	}

	private function createPerson(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_person')) {
			$t = $schema->createTable('crm_person');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('role', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('is_primary', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_person_pk');
			$t->addIndex(['company_id'], 'crm_pe_co_idx');
			$t->addIndex(['email'], 'crm_pe_email_idx');
		}
	}

	private function createAgreement(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_agreement')) {
			$t = $schema->createTable('crm_agreement');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'license']);
			$t->addColumn('product_code', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'custom']);
			$t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('limits_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('starts_at', Types::DATE, ['notnull' => false]);
			$t->addColumn('valid_until', Types::DATE, ['notnull' => false]);
			$t->addColumn('amount_cents', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('invoice_ref', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('invoice_status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'none']);
			$t->addColumn('ic_invoice_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('sales_log_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('key_fingerprint', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'draft']);
			$t->addColumn('superseded_by', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('cancel_reason', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_agreement_pk');
			$t->addIndex(['company_id'], 'crm_ag_co_idx');
			$t->addIndex(['status'], 'crm_ag_status_idx');
			$t->addIndex(['valid_until'], 'crm_ag_until_idx');
			$t->addIndex(['sales_log_id'], 'crm_ag_slog_idx');
			$t->addIndex(['ic_invoice_id'], 'crm_ag_ic_idx');
		}
	}

	private function createDeal(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_deal')) {
			$t = $schema->createTable('crm_deal');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('stage', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'lead']);
			$t->addColumn('value_cents', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('products', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('expected_close', Types::DATE, ['notnull' => false]);
			$t->addColumn('stage_changed_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('lost_reason', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('agreement_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_deal_pk');
			$t->addIndex(['company_id'], 'crm_de_co_idx');
			$t->addIndex(['stage'], 'crm_de_stage_idx');
			$t->addIndex(['stage_changed_at'], 'crm_de_stage_at_idx');
		}
	}

	private function createActivity(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_activity')) {
			$t = $schema->createTable('crm_activity');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('deal_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('agreement_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'todo']);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('details', Types::TEXT, ['notnull' => false]);
			$t->addColumn('due_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('done_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'user']);
			$t->addColumn('todo_uri', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_activity_pk');
			$t->addIndex(['company_id'], 'crm_ac_co_idx');
			$t->addIndex(['due_date'], 'crm_ac_due_idx');
			$t->addIndex(['deal_id'], 'crm_ac_deal_idx');
			$t->addIndex(['done_at'], 'crm_ac_done_idx');
		}
	}

	private function createHours(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_hours')) {
			$t = $schema->createTable('crm_hours');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('agreement_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('entry_date', Types::DATE, ['notnull' => false]);
			$t->addColumn('minutes', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('topic', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_hours_pk');
			$t->addIndex(['agreement_id'], 'crm_hr_ag_idx');
			$t->addIndex(['entry_date'], 'crm_hr_date_idx');
		}
	}

	private function createEvent(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_event')) {
			$t = $schema->createTable('crm_event');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('actor', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'system']);
			$t->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'note']);
			$t->addColumn('payload_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_event_pk');
			$t->addIndex(['company_id'], 'crm_ev_co_idx');
			$t->addIndex(['created_at'], 'crm_ev_created_idx');
		}
	}

	private function createRemlog(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_remlog')) {
			$t = $schema->createTable('crm_remlog');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('agreement_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('offset_days', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('valid_until_snap', Types::DATE, ['notnull' => false]);
			$t->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_remlog_pk');
			$t->addUniqueIndex(['agreement_id', 'offset_days', 'valid_until_snap'], 'crm_rl_uq');
		}
	}

	private function createEmailTouch(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_email_touch')) {
			$t = $schema->createTable('crm_email_touch');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('company_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('direction', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'outbound']);
			$t->addColumn('from_addr', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('to_addr', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('occurred_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'manual']);
			$t->addColumn('mail_message_id', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('snippet', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_email_pk');
			$t->addIndex(['company_id', 'occurred_at'], 'crm_em_co_at_idx');
			$t->addUniqueIndex(['mail_message_id'], 'crm_em_mail_uq');
		}
	}

	private function createDigestLog(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_digest_log')) {
			$t = $schema->createTable('crm_digest_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('week_key', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('payload_json', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_digest_pk');
			$t->addUniqueIndex(['week_key'], 'crm_dg_week_uq');
		}
	}

	private function createSeqLog(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_seq_log')) {
			$t = $schema->createTable('crm_seq_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('deal_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('sequence_id', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_seq_pk');
			$t->addUniqueIndex(['deal_id', 'sequence_id'], 'crm_sq_uq');
		}
	}

	private function createPayWatch(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_pay_watch')) {
			$t = $schema->createTable('crm_pay_watch');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('agreement_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('last_paid_total_cents', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'none']);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_pay_pk');
			$t->addUniqueIndex(['agreement_id'], 'crm_pw_ag_uq');
		}
	}

	private function createImportJob(ISchemaWrapper $schema): void
	{
		if (!$schema->hasTable('crm_import_job')) {
			$t = $schema->createTable('crm_import_job');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'csv']);
			$t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('summary_json', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'crm_imp_pk');
			$t->addIndex(['created_at'], 'crm_imp_created_idx');
		}
	}
}
