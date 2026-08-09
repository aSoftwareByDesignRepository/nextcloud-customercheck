<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\ImportJobMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\ImportMatcher;
use OCA\CustomerCheck\Service\ImportService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ImportServiceParseTest extends TestCase
{
	private function service(?ProductCodeRegistry $codes = null): ImportService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-01-01'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		if ($codes === null) {
			$codes = $this->createMock(ProductCodeRegistry::class);
			$codes->method('isAllowed')->willReturnCallback(
				static fn (string $c): bool => in_array($c, Constants::PRODUCT_CODES, true)
			);
			$codes->method('kindFor')->willReturnCallback(
				static fn (string $c): string => Constants::PRODUCT_CODE_KINDS[$c] ?? 'onetime'
			);
		}
		return new ImportService(
			$this->createMock(ImportMatcher::class),
			$this->createMock(CompanyService::class),
			$this->createMock(AgreementService::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(PersonMapper::class),
			$this->createMock(AgreementStatusService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(ImportJobMapper::class),
			$this->createMock(FacadeProbeService::class),
			$codes,
			$this->createMock(IDBConnection::class),
			$time,
			$l10n,
		);
	}

	public function testParseCsvHappyPath(): void
	{
		$csv = "customerId,companyName,contactName,email,productCode,mobileSeats,terminalDevices,validUntil,invoiceId,amountEur,paidAt,keyIssuedAt,renewalDue,notes\n"
			. "acme-gmbh,Acme GmbH,Ada,ada@acme.test,workshop-half,2,1,2027-01-01,INV-1,99.50,,2026-01-01,2026-12-01,hello\n";
		$parsed = $this->service()->parseCsv($csv);
		$this->assertCount(1, $parsed['rows']);
		$this->assertSame([], $parsed['errors']);
		$this->assertSame('acme-gmbh', $parsed['rows'][0]['customerId']);
		$this->assertSame('Acme GmbH', $parsed['rows'][0]['companyName']);
		$this->assertSame('workshop-half', $parsed['rows'][0]['productCode']);
		$this->assertSame(2, $parsed['rows'][0]['mobileSeats']);
	}

	public function testMissingCompanyNameHeaderRejected(): void
	{
		$parsed = $this->service()->parseCsv("customerId,email\nacme,a@b.test\n");
		$this->assertSame([], $parsed['rows']);
		$this->assertNotEmpty($parsed['errors']);
		$this->assertSame('companyName', $parsed['errors'][0]['column']);
		$this->assertSame('missing_header', $parsed['errors'][0]['reason']);
	}

	public function testCompanyNameOnlyHeaderIsAccepted(): void
	{
		$parsed = $this->service()->parseCsv("companyName\nAcme GmbH\n");
		$this->assertSame([], $parsed['errors']);
		$this->assertCount(1, $parsed['rows']);
		$this->assertSame('Acme GmbH', $parsed['rows'][0]['companyName']);
		$this->assertNotSame('', $parsed['rows'][0]['customerId']);
	}

	public function testOneCompanyNamePerLineWithoutHeader(): void
	{
		$parsed = $this->service()->parseCsv("Acme GmbH\nBeta AG\n");
		$this->assertSame([], $parsed['errors']);
		$this->assertCount(2, $parsed['rows']);
		$this->assertSame('Acme GmbH', $parsed['rows'][0]['companyName']);
		$this->assertSame('Beta AG', $parsed['rows'][1]['companyName']);
	}

	public function testInvalidEmailRejected(): void
	{
		$csv = "customerId,companyName,email\nacme-gmbh,Acme,not-an-email\n";
		$parsed = $this->service()->parseCsv($csv);
		$this->assertSame([], $parsed['rows']);
		$this->assertSame('EMAIL_INVALID', $parsed['errors'][0]['reason']);
	}

	public function testInvalidProductCodeRejected(): void
	{
		$csv = "customerId,companyName,productCode\nacme-gmbh,Acme,BAD CODE\n";
		$parsed = $this->service()->parseCsv($csv);
		$this->assertSame([], $parsed['rows']);
		$this->assertSame('PRODUCT_CODE_INVALID', $parsed['errors'][0]['reason']);
	}

	public function testPlanAgreementsRejectsUnknownCatalogueCode(): void
	{
		$codes = $this->createMock(ProductCodeRegistry::class);
		$codes->method('isAllowed')->willReturnCallback(static fn (string $code): bool => $code === 'custom');
		$codes->method('kindFor')->willReturn('onetime');

		$this->expectException(\OCA\CustomerCheck\Exception\ValidationException::class);
		$this->service($codes)->planAgreements([
			'companyName' => 'Acme',
			'productCode' => 'vendor-pack',
			'validUntil' => '2027-01-01',
		], 'csv');
	}

	public function testSalesLogSupersedeChainOrdersOldestToNewest(): void
	{
		$rows = [
			['customerId' => 'acme', 'product' => 'azc', 'salesLogId' => 2, 'validUntil' => '2027-01-01'],
			['customerId' => 'acme', 'product' => 'azc', 'salesLogId' => 1, 'validUntil' => '2026-01-01'],
		];
		$out = $this->service()->buildSalesLogSupersedeProposals($rows);
		$this->assertSame(1, $out[0]['salesLogId']);
		$this->assertSame('superseded', $out[0]['_chainRole']);
		$this->assertSame(2, $out[1]['salesLogId']);
		$this->assertSame('active', $out[1]['_chainRole']);
		$this->assertSame(1, $out[1]['_supersedesSalesLogId']);
	}

	public function testSalesLogCsvMapsContactEmailAlias(): void
	{
		$csv = "product,customerId,companyName,contactEmail,invoiceId,mobileSeats,terminalDevices,validUntil,amountEur\n"
			. "azc,mueller-gmbh,Müller GmbH,it@mueller.de,INV-1,30,1,2027-05-29,154.00\n";
		$parsed = $this->service()->parseTextPayload($csv);
		$this->assertSame('csv', $parsed['format']);
		$this->assertSame([], $parsed['errors']);
		$this->assertCount(1, $parsed['rows']);
		$this->assertSame('it@mueller.de', $parsed['rows'][0]['email']);
		$this->assertSame('mueller-gmbh', $parsed['rows'][0]['customerId']);
		$this->assertSame('azc', $parsed['rows'][0]['productCode']);
	}

	public function testSemicolonCsvAndBomAreAccepted(): void
	{
		$csv = "\xEF\xBB\xBFcompanyName;email\nBeta AG;beta@example.test\n";
		$parsed = $this->service()->parseCsv($csv);
		$this->assertSame([], $parsed['errors']);
		$this->assertSame('Beta AG', $parsed['rows'][0]['companyName']);
		$this->assertSame('beta@example.test', $parsed['rows'][0]['email']);
	}

	public function testTrailingColumnMismatchIsForgiven(): void
	{
		$csv = "companyName,email\nAcme GmbH,a@acme.test,extra-ignored\n";
		$parsed = $this->service()->parseCsv($csv);
		$this->assertSame([], $parsed['errors']);
		$this->assertSame('Acme GmbH', $parsed['rows'][0]['companyName']);
	}

	public function testJsonRowsArrayIsAccepted(): void
	{
		$json = json_encode([
			'rows' => [
				[
					'customerId' => 'acme-gmbh',
					'companyName' => 'Acme GmbH',
					'contactEmail' => 'ada@acme.test',
					'product' => 'azc',
					'mobileSeats' => 5,
					'validUntil' => '2027-01-01',
					'amountEurCents' => 9900,
				],
			],
		], JSON_THROW_ON_ERROR);
		$parsed = $this->service()->parseTextPayload($json);
		$this->assertSame('json', $parsed['format']);
		$this->assertSame([], $parsed['errors']);
		$this->assertCount(1, $parsed['rows']);
		$this->assertSame('ada@acme.test', $parsed['rows'][0]['email']);
		$this->assertSame('99', $parsed['rows'][0]['amountEur']);
		$this->assertSame('azc', $parsed['rows'][0]['productCode']);
	}

	public function testGdprCompanyJsonExpandsAgreements(): void
	{
		$json = json_encode([
			'exportedAt' => '2026-08-07T12:00:00+00:00',
			'company' => [
				'slug' => 'mueller-gmbh',
				'name' => 'Müller GmbH',
				'country' => 'DE',
				'notes' => 'VIP',
			],
			'persons' => [
				['name' => 'Hans', 'email' => 'hans@mueller.de', 'isPrimary' => true],
			],
			'agreements' => [
				[
					'productCode' => 'azc',
					'validUntil' => '2027-05-29',
					'amountCents' => 15400,
					'invoiceRef' => 'INV-9',
					'limits' => ['mobileSeats' => 30, 'terminalDevices' => 1],
				],
			],
		], JSON_THROW_ON_ERROR);
		$parsed = $this->service()->parseJson($json);
		$this->assertSame([], $parsed['errors']);
		$this->assertCount(1, $parsed['rows']);
		$row = $parsed['rows'][0];
		$this->assertSame('mueller-gmbh', $row['customerId']);
		$this->assertSame('Müller GmbH', $row['companyName']);
		$this->assertSame('hans@mueller.de', $row['email']);
		$this->assertSame('Hans', $row['contactName']);
		$this->assertSame('azc', $row['productCode']);
		$this->assertSame('154', $row['amountEur']);
		$this->assertSame('INV-9', $row['invoiceId']);
		$this->assertSame(30, $row['mobileSeats']);
	}

	public function testInvalidJsonIsRejectedCleanly(): void
	{
		$parsed = $this->service()->parseTextPayload('{not-json');
		$this->assertSame([], $parsed['rows']);
		$this->assertSame('json_invalid', $parsed['errors'][0]['reason']);
	}

	public function testMapImportKeysAliasesContactEmail(): void
	{
		$mapped = $this->service()->mapImportKeys([
			'companyName' => 'Acme',
			'contactEmail' => 'a@acme.test',
			'amountEurCents' => 2500,
		]);
		$this->assertSame('a@acme.test', $mapped['email']);
		$this->assertArrayNotHasKey('contactEmail', $mapped);
		$this->assertSame('25', $mapped['amountEur']);

		$mappedLower = $this->service()->mapImportKeys([
			'companyName' => 'Beta',
			'contactemail' => 'b@beta.test',
		]);
		$this->assertSame('b@beta.test', $mappedLower['email']);
	}

	public function testCanonicalizeSalesLogContactEmailHeader(): void
	{
		$csv = "companyName,contactEmail\nAcme GmbH,ops@acme.test\n";
		$parsed = $this->service()->parseCsv($csv);
		$this->assertSame([], $parsed['errors']);
		$this->assertSame('ops@acme.test', $parsed['rows'][0]['email']);
	}
}
