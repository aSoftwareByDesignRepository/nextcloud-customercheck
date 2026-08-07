<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use OCA\CustomerCheck\Repair\UninstallDropTables;
use PHPUnit\Framework\TestCase;

/** SHARED-IDENTITY AC-C-11 — uninstall drops only CRM-owned tables. */
final class UninstallSiblingLedgerSafetyContractTest extends TestCase
{
	public function testUninstallTablesAreCrmPrefixedOnly(): void
	{
		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertStringStartsWith('crm_', $table, $table);
			$this->assertStringNotContainsString('pc_', $table);
			$this->assertStringNotContainsString('ic_', $table);
			$this->assertStringNotContainsString('mn_', $table);
			$this->assertStringNotContainsString('tc_', $table);
		}
		$this->assertContains('crm_link_idem', UninstallDropTables::TABLES);
		$this->assertContains('crm_company', UninstallDropTables::TABLES);
	}
}
