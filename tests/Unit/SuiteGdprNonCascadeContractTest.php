<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AC-L7 / G10 — CUC erase & merge must not SQL-touch sibling ledgers.
 */
final class SuiteGdprNonCascadeContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testGdprEraseDoesNotReferenceSiblingTables(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/CompanyService.php');
		$this->assertStringContainsString('function gdprErase', $src);
		$method = $this->extractMethod($src, 'gdprErase');
		$this->assertNotSame('', trim($method));

		foreach ([
			'pc_customers',
			'pc_projects',
			'ic_invoices',
			'ic_payments',
			'helpdesk_customers',
			'mn_work_orders',
			'iv_movements',
			'StockIssueFacade',
			'CrmCustomerWriteFacade',
			'INSERT INTO',
			'DELETE FROM oc_',
		] as $needle) {
			$this->assertStringNotContainsString(
				$needle,
				$method,
				'gdprErase must not touch sibling ledger "' . $needle . '"',
			);
		}
	}

	public function testMergeServiceStaysInsideCrmTables(): void
	{
		$src = (string)file_get_contents($this->root . '/lib/Service/CompanyMergeService.php');
		$this->assertStringContainsString('reassignCompany', $src);
		$this->assertStringNotContainsString('pc_customers', $src);
		$this->assertStringNotContainsString('ic_invoices', $src);
		$this->assertStringNotContainsString('StockIssue', $src);
		$this->assertStringContainsString('EVENT_COMPANY_MERGED', $src);
		$this->assertStringContainsString('Never cascades into ProjectCheck', $src);
	}

	public function testSuiteGdprRunbookExists(): void
	{
		$path = $this->root . '/docs/SUITE-GDPR-ERASE.md';
		$this->assertFileExists($path);
		$body = (string)file_get_contents($path);
		$this->assertStringContainsString('AC-L7', $body);
		$this->assertStringContainsString('pc_*', $body);
		$this->assertStringContainsString('never', strtolower($body));
	}

	private function extractMethod(string $src, string $name): string
	{
		$pos = strpos($src, 'function ' . $name);
		$this->assertNotFalse($pos);
		$chunk = substr($src, (int)$pos);
		// Stop at next top-level method or class end.
		if (preg_match('/^function\s+' . preg_quote($name, '/') . '\b.*?(?=\n\tpublic function|\n\tprivate function|\n\tprotected function|\n}\n)/s', $chunk, $m) === 1) {
			return $m[0];
		}
		return substr($chunk, 0, 4500);
	}
}
