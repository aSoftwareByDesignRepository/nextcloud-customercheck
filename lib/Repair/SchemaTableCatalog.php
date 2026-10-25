<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after customercheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=customercheck
 */
namespace OCA\CustomerCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'crm_1on1',
		'crm_acct_plan',
		'crm_actdue',
		'crm_activity',
		'crm_agreement',
		'crm_cl_tmpl',
		'crm_cl_tmpl_item',
		'crm_commit',
		'crm_company',
		'crm_cust_prm',
		'crm_deal',
		'crm_deal_cl_file',
		'crm_deal_cl_item',
		'crm_dealrot',
		'crm_digest_log',
		'crm_edit_conflict',
		'crm_email_touch',
		'crm_entity_rev',
		'crm_escal',
		'crm_event',
		'crm_hours',
		'crm_import_job',
		'crm_license_state',
		'crm_link_idem',
		'crm_mail_dft',
		'crm_mail_fld',
		'crm_mail_msg',
		'crm_mailbox',
		'crm_map_step',
		'crm_mob_idem',
		'crm_mobile_seat',
		'crm_ooo',
		'crm_pay_watch',
		'crm_person',
		'crm_plan_ms',
		'crm_push_tok',
		'crm_remlog',
		'crm_review_item',
		'crm_seq_log',
		'crm_value_map',
		'crm_workspace',
		'crm_ws_member',
		'crm_ws_set',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
