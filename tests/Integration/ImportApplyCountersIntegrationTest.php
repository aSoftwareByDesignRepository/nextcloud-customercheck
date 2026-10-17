<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Service\ImportService;
use OCA\CustomerCheck\Tests\Support\DbCascade;
use Test\TestCase;

/**
 * CSV import apply: label fallback for label-less rows and summary counters
 * that only count committed rows (rowDelta merged post-commit).
 */
class ImportApplyCountersIntegrationTest extends TestCase
{
	/** @var list<string> run suffixes of companies imported by this test */
	private array $runSuffixes = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
		\OC_App::loadApp('customercheck');
	}

	protected function tearDown(): void
	{
		if (isset(\OC::$server)) {
			foreach ($this->runSuffixes as $suffix) {
				try {
					DbCascade::companiesBySlugLike('%' . $suffix);
				} catch (\Throwable) {
				}
			}
		}
		$this->runSuffixes = [];
		parent::tearDown();
	}

	public function testCsvRowWithoutLabelGetsCompanyProductFallbackLabel(): void
	{
		$suffix = bin2hex(random_bytes(4));
		$this->runSuffixes[] = $suffix;
		$companyName = 'LblFallback ' . $suffix;
		$csv = "companyName,product,validUntil,amountEur,startsAt\n"
			. "$companyName,cuc,2027-12-31,10,2026-01-01\n";

		/** @var ImportService $imports */
		$imports = \OC::$server->get(ImportService::class);
		$apply = $imports->apply([
			'source' => 'csv',
			'csv' => $csv,
			'workspaceId' => 1,
			'decisions' => [],
		], 'admin');

		$this->assertSame([], $apply['errors'], json_encode($apply));
		$this->assertSame(1, $apply['created']);
		$this->assertSame(1, $apply['agreements']);

		/** @var CompanyMapper $companies */
		$companies = \OC::$server->get(CompanyMapper::class);
		$matches = $companies->findByNameCaseInsensitive($companyName, 1);
		$this->assertNotEmpty($matches);
		$companyId = (int)$matches[0]->getId();

		/** @var AgreementMapper $agreements */
		$agreements = \OC::$server->get(AgreementMapper::class);
		$agreement = $agreements->findActiveByCompanyProduct($companyId, 'cuc');
		$this->assertNotNull($agreement);
		$this->assertSame($companyName . ' cuc', (string)$agreement->getLabel());
	}

	public function testRolledBackRowDoesNotInflateCreatedCounter(): void
	{
		$suffix = bin2hex(random_bytes(4));
		$this->runSuffixes[] = $suffix;
		$good = 'CommitOk ' . $suffix;
		// Row 2's name passes CSV validation (non-empty) but fails
		// CompanyService's 255-char limit — a write-time-only rejection that
		// rolls back the whole per-row transaction.
		$bad = 'Rollback' . $suffix . str_repeat('x', 300);
		$csv = "companyName,product,validUntil,amountEur,startsAt\n"
			. "$good,cuc,2027-12-31,10,2026-01-01\n"
			. "\"$bad\",cuc,2027-12-31,10,2026-01-01\n";

		/** @var ImportService $imports */
		$imports = \OC::$server->get(ImportService::class);
		$apply = $imports->apply([
			'source' => 'csv',
			'csv' => $csv,
			'workspaceId' => 1,
			'decisions' => [],
		], 'admin');

		$this->assertSame(1, $apply['created'], json_encode($apply));
		$this->assertCount(1, $apply['errors'], json_encode($apply));
		$this->assertSame('VALIDATION', (string)$apply['errors'][0]['reason']);

		/** @var CompanyMapper $companies */
		$companies = \OC::$server->get(CompanyMapper::class);
		$this->assertNotEmpty($companies->findByNameCaseInsensitive($good, 1));
		$this->assertSame(
			[],
			$companies->findByNameCaseInsensitive($bad, 1),
			'rolled-back row must not persist a company while claiming created',
		);
	}
}
