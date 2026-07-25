<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Service\ImportService;
use OCA\CustomerCheck\Service\InvoicePaidSyncService;
use Test\TestCase;

/**
 * Smoke that critical M4 services resolve and pure paths work inside NC.
 */
class ImportInvoicePaidIntegrationTest extends TestCase
{
	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
	}

	public function testImportServiceResolvesAndParsesCsv(): void
	{
		/** @var ImportService $imports */
		$imports = \OC::$server->get(ImportService::class);
		$csv = "customerId,companyName,email,validUntil,amountEur\n"
			. "integ-co,Integ Co,integ@example.com,2027-06-01,10.00\n";
		$parsed = $imports->parseCsv($csv);
		$this->assertSame([], $parsed['errors']);
		$this->assertCount(1, $parsed['rows']);
		$this->assertSame('integ-co', $parsed['rows'][0]['customerId']);
	}

	public function testInvoicePaidSyncResolvesAndReportsIdlePoll(): void
	{
		/** @var InvoicePaidSyncService $sync */
		$sync = \OC::$server->get(InvoicePaidSyncService::class);
		$this->assertFalse($sync->isPaidTransition('paid', 100, 'paid', 100));
		$result = $sync->poll();
		$this->assertArrayHasKey('processed', $result);
		$this->assertArrayHasKey('ambiguous', $result);
	}
}
