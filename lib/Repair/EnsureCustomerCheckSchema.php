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
	// The table set this step verifies lives on {@see SchemaTableCatalog} —
	// a dedicated new class, because upgrade-path code must never reference a
	// member added to a pre-existing class mid-release (the old version may
	// already be loaded → `Undefined constant` stalls `occ upgrade`).

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
		foreach (SchemaTableCatalog::EXPECTED_TABLES as $table) {
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
