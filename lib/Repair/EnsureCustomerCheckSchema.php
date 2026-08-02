<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Repair;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Install / post-migration guard: verifies the CustomerCheck schema landed and
 * clears any legacy uninstall pass marker so a later upgrade cannot inherit it.
 */
final class EnsureCustomerCheckSchema implements IRepairStep
{
	/** @var list<string> Tables that must exist once migrations ran. */
	private const REQUIRED_TABLES = [
		'crm_company',
		'crm_person',
		'crm_agreement',
		'crm_deal',
		'crm_activity',
		'crm_hours',
		'crm_event',
		'crm_remlog',
		'crm_actdue',
		'crm_email_touch',
		'crm_digest_log',
		'crm_seq_log',
		'crm_pay_watch',
		'crm_import_job',
		'crm_license_state',
		'crm_mobile_seat',
		'crm_mob_idem',
		'crm_push_tok',
		'crm_workspace',
		'crm_ws_member',
		'crm_ws_set',
		'crm_mailbox',
		'crm_mail_fld',
		'crm_mail_msg',
		'crm_mail_dft',
		'crm_acct_plan',
		'crm_plan_ms',
		'crm_map_step',
		'crm_cust_prm',
		'crm_commit',
		'crm_escal',
		'crm_ooo',
		'crm_1on1',
		'crm_review_item',
		'crm_edit_conflict',
		'crm_entity_rev',
		'crm_value_map',
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure CustomerCheck schema exists';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missing = [];
		foreach (self::REQUIRED_TABLES as $table) {
			if (!$this->db->tableExists($table)) {
				$missing[] = $table;
			}
		}

		if ($missing !== []) {
			$output->warning(sprintf(
				'CustomerCheck: %d table(s) missing (%s) — run `occ upgrade` to apply migrations.',
				count($missing),
				implode(', ', $missing),
			));
		}
	}
}
