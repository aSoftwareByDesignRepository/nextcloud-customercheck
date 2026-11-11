<?php

declare(strict_types=1);

/**
 * CustomerCheck transfer manifest — every appconfig key and every crm_*
 * table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 */

namespace OCA\CustomerCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — app-local appconfig (no
			// platform restriction mirror → no mirrored keys; the door
			// fails closed on empty allowlists, so there is no cross-key
			// invariant that would need an atomic writer) ──
			'access_restriction_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_group_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admin_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── reminders & digest knobs (App settings → Reminders) ──
			'stale_deal_days' => ['class' => 'plain', 'section' => 'jobs'],
			'digest_enabled' => ['class' => 'plain', 'section' => 'jobs'],
			'digest_weekday' => ['class' => 'plain', 'section' => 'jobs'],
			'digest_hour' => ['class' => 'plain', 'section' => 'jobs'],
			'reminder_hour' => ['class' => 'plain', 'section' => 'jobs'],
			'cadence_enabled' => ['class' => 'plain', 'section' => 'jobs'],
			'mail_related_enabled' => ['class' => 'plain', 'section' => 'jobs'],
			// CUC→PC display-name push grants this instance a write path
			// into sibling apps → security-relevant.
			'identity_rename_push' => ['class' => 'plain', 'security' => true, 'section' => 'jobs'],
			// ── feeds & intake tokens (App settings → Feeds) — secrets
			// are redacted at export, reported as "set on this instance" ──
			'ics_token' => ['class' => 'secret', 'section' => 'tokens'],
			'ics_workspace_tokens' => ['class' => 'secret', 'section' => 'tokens'],
			'lead_token' => ['class' => 'secret', 'section' => 'tokens'],
			'order_token' => ['class' => 'secret', 'section' => 'tokens'],
			// A calendar URI names a calendar on THIS instance (NN-10).
			'vtodo_calendar_uri' => ['class' => 'env', 'section' => 'tokens'],
			// ── agreement catalogue (App settings → Catalogue) ──
			'product_codes_custom' => ['class' => 'plain', 'section' => 'product-codes'],
			// ── personal-mail OAuth app credentials (App settings →
			// Mail OAuth): the registration is bound to this instance's
			// callback URL (env) and the secrets never leave (secret) ──
			'oauth_google_client_id' => ['class' => 'env', 'section' => 'personal-mail'],
			'oauth_microsoft_client_id' => ['class' => 'env', 'section' => 'personal-mail'],
			'oauth_google_client_secret' => ['class' => 'secret', 'section' => 'personal-mail'],
			'oauth_microsoft_client_secret' => ['class' => 'secret', 'section' => 'personal-mail'],
			// ── internal bookkeeping — never transferred ──
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'rate_limit:*'       session/webhook rate-limit buckets
			//   'upgrade_backup_*'   snapshot-restore rewrites restored keys
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ workspace + membership + per-workspace settings ══
			'crm_workspace' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['slug']],
			'crm_ws_member' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'user_id'],
				'fk' => ['workspace_id' => 'crm_workspace'], 'depends_on' => ['crm_workspace'],
				'uid_ref' => ['user_id']],
			// Per-workspace knobs (WIP limit, lead SLA, review sources) —
			// configuration, not transactions.
			'crm_ws_set' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id'],
				'fk' => ['workspace_id' => 'crm_workspace'], 'depends_on' => ['crm_workspace']],

			// ══ customer book ══
			// files_folder_*/pc_customer_id/tc_customer_id point at Files-app
			// fileids and sibling-app rows — env-dropped, re-linked on target.
			// account_owner/created_by carry verbatim (provenance/ownership
			// degrade a view, never sink a row — deskcheck precedent).
			'crm_company' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['slug'],
				'fk' => ['workspace_id' => 'crm_workspace'], 'depends_on' => ['crm_workspace'],
				'env_columns' => ['files_folder_id', 'files_folder_path', 'pc_customer_id', 'tc_customer_id']],
			// contacts_uid references a Contacts-app entry on THIS instance.
			'crm_person' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'name', 'email'],
				'fk' => ['company_id' => 'crm_company'],
				'fk_late' => ['reports_to_person_id' => 'crm_person'],
				'depends_on' => ['crm_company'],
				'env_columns' => ['contacts_uid']],
			'crm_acct_plan' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id'],
				'fk' => ['company_id' => 'crm_company', 'workspace_id' => 'crm_workspace'],
				'depends_on' => ['crm_company', 'crm_workspace']],
			'crm_map_step' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['plan_id', 'sort_order'],
				'fk' => ['plan_id' => 'crm_acct_plan'], 'depends_on' => ['crm_acct_plan']],
			'crm_plan_ms' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['plan_id', 'sort_order'],
				'fk' => ['plan_id' => 'crm_acct_plan'], 'depends_on' => ['crm_acct_plan']],

			// ══ pipeline ══
			// ic_invoice_id/sales_log_id/key_fingerprint reference sibling-app
			// rows and license state on the SOURCE instance — env-dropped.
			'crm_agreement' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'product_code', 'label', 'starts_at'],
				'fk' => ['company_id' => 'crm_company'],
				'fk_late' => ['superseded_by' => 'crm_agreement'],
				'depends_on' => ['crm_company'],
				'env_columns' => ['ic_invoice_id', 'sales_log_id', 'key_fingerprint']],
			// (company, title, created_at) is the deal's stable identity —
			// title alone could repeat inside one book.
			'crm_deal' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'title', 'created_at'],
				'fk' => [
					'company_id' => 'crm_company',
					'agreement_id' => 'crm_agreement',
					'person_id' => 'crm_person',
				],
				'depends_on' => ['crm_company', 'crm_agreement', 'crm_person'],
				'env_columns' => ['quote_file_id']],
			// Activity rows have no honest natural key (legitimate
			// duplicates exist) — spec default seed-if-empty on merge.
			'crm_activity' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => [
					'company_id' => 'crm_company',
					'deal_id' => 'crm_deal',
					'agreement_id' => 'crm_agreement',
					'person_id' => 'crm_person',
				],
				'depends_on' => ['crm_company', 'crm_deal', 'crm_agreement', 'crm_person'],
				'env_columns' => ['todo_uri']],
			'crm_deal_cl_item' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['deal_id', 'title', 'sort_order'],
				'fk' => [
					'deal_id' => 'crm_deal',
					'workspace_id' => 'crm_workspace',
					'company_id' => 'crm_company',
				],
				'depends_on' => ['crm_deal', 'crm_workspace', 'crm_company']],
			'crm_commit' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'week_key', 'kind', 'title'],
				'fk' => ['workspace_id' => 'crm_workspace', 'company_id' => 'crm_company'],
				'depends_on' => ['crm_workspace', 'crm_company'],
				'uid_ref' => ['owner_user_id']],
			'crm_cust_prm' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['company_id', 'workspace_id', 'title'],
				'fk' => [
					'company_id' => 'crm_company',
					'workspace_id' => 'crm_workspace',
					'person_id' => 'crm_person',
				],
				'depends_on' => ['crm_company', 'crm_workspace', 'crm_person']],
			'crm_escal' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'subject_user_id', 'owner_user_id', 'created_at'],
				'fk' => [
					'workspace_id' => 'crm_workspace',
					'company_id' => 'crm_company',
					'deal_id' => 'crm_deal',
				],
				'depends_on' => ['crm_workspace', 'crm_company', 'crm_deal'],
				'uid_ref' => ['subject_user_id', 'owner_user_id']],
			'crm_hours' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['agreement_id', 'entry_date', 'topic'],
				'fk' => ['agreement_id' => 'crm_agreement'], 'depends_on' => ['crm_agreement']],
			// KAM touch history — manual touches are content, synced ones
			// regenerate; no honest natural key → seed-if-empty.
			'crm_email_touch' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => ['company_id' => 'crm_company', 'person_id' => 'crm_person'],
				'depends_on' => ['crm_company', 'crm_person']],

			// ══ checklist templates ══
			'crm_cl_tmpl' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'tmpl_key'],
				'fk' => ['workspace_id' => 'crm_workspace'], 'depends_on' => ['crm_workspace']],
			'crm_cl_tmpl_item' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['template_id', 'sort_order'],
				'fk' => ['template_id' => 'crm_cl_tmpl'], 'depends_on' => ['crm_cl_tmpl']],

			// ══ shared / personal mailboxes ══
			// IMAP/SMTP + OAuth secrets are secret_columns (redacted at
			// export, NULL on insert, untouched on update). owner_user_id
			// keeps NULL for shared mailboxes; a non-null owner that is
			// missing on the target skips the personal mailbox.
			'crm_mailbox' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'email_address', 'kind'],
				'fk' => ['workspace_id' => 'crm_workspace'], 'depends_on' => ['crm_workspace'],
				'uid_ref_nullable' => ['owner_user_id'],
				'secret_columns' => ['secret_enc', 'token_enc', 'refresh_enc']],
			'crm_mail_fld' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['mailbox_id', 'folder_path'],
				'fk' => ['mailbox_id' => 'crm_mailbox'], 'depends_on' => ['crm_mailbox']],
			// Drafts carry user content; attachment file ids are env.
			'crm_mail_dft' => ['class' => 'user-data', 'strategy' => 'seed-if-empty',
				'fk' => ['mailbox_id' => 'crm_mailbox', 'company_id' => 'crm_company'],
				'depends_on' => ['crm_mailbox', 'crm_company'],
				'env_columns' => ['attachment_file_ids']],

			// ══ team coaching / 1:1 ══
			'crm_1on1' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'manager_user_id', 'report_user_id', 'meeting_on'],
				'fk' => ['workspace_id' => 'crm_workspace'], 'depends_on' => ['crm_workspace'],
				'uid_ref' => ['manager_user_id', 'report_user_id']],
			'crm_ooo' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'primary_user_id', 'starts_on', 'ends_on'],
				'fk' => ['workspace_id' => 'crm_workspace'], 'depends_on' => ['crm_workspace'],
				'uid_ref' => ['primary_user_id', 'backup_user_id']],

			// ══ review inbox + value maps ══
			// mail_msg_id references the per-instance IMAP index (internal
			// table) → env-dropped, re-linked by the next mail sync.
			'crm_review_item' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'source_kind', 'source_fingerprint'],
				'fk' => [
					'workspace_id' => 'crm_workspace',
					'company_id' => 'crm_company',
					'deal_id' => 'crm_deal',
				],
				'depends_on' => ['crm_workspace', 'crm_company', 'crm_deal'],
				'env_columns' => ['mail_msg_id']],
			// Field canonicalization map — configuration learned per book.
			'crm_value_map' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['workspace_id', 'field_key', 'raw_value_norm'],
				'fk' => ['workspace_id' => 'crm_workspace'], 'depends_on' => ['crm_workspace']],

			// ══ internal / env / log — NEVER transferred ══
			// IMAP message index — bound to this instance's mailbox
			// connections; the target re-indexes via its own sync.
			'crm_mail_msg' => ['class' => 'internal'],
			// Checklist file links point at Files-app fileids that can
			// never remap (dk_floor_plans / bc_tx_attachments class).
			'crm_deal_cl_file' => ['class' => 'internal'],
			// Optimistic-lock conflict rows + entity revision bookkeeping —
			// per-instance parallel-edit state.
			'crm_edit_conflict' => ['class' => 'internal'],
			'crm_entity_rev' => ['class' => 'internal'],
			// CSV import bookkeeping.
			'crm_import_job' => ['class' => 'internal'],
			// License + seats + push tokens are bound to this instance's
			// id, the vendor signature, and per-device bearer secrets.
			'crm_license_state' => ['class' => 'internal'],
			'crm_mobile_seat' => ['class' => 'internal'],
			'crm_push_tok' => ['class' => 'internal'],
			// Idempotency + invoice-paid watch ledgers — per-instance
			// dedup/poll state.
			'crm_link_idem' => ['class' => 'internal'],
			'crm_mob_idem' => ['class' => 'internal'],
			'crm_pay_watch' => ['class' => 'internal'],
			// Exactly-once notification/reminder ledgers + the timeline
			// event log — history stays on the source instance.
			'crm_actdue' => ['class' => 'log'],
			'crm_dealrot' => ['class' => 'log'],
			'crm_digest_log' => ['class' => 'log'],
			'crm_remlog' => ['class' => 'log'],
			'crm_seq_log' => ['class' => 'log'],
			'crm_event' => ['class' => 'log'],
		]);
	}
}
