<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\ImportJobMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\ImportMatcher;
use OCA\CustomerCheck\Service\ImportService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ImportServiceParseTest extends TestCase
{
	private function service(): ImportService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-01-01'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);
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
			$this->createMock(IDBConnection::class),
			$time,
			$l10n,
		);
	}

	public function testParseCsvHappyPath(): void
	{
		$csv = "customerId,companyName,contactName,email,mobileSeats,terminalDevices,validUntil,invoiceId,amountEur,paidAt,keyIssuedAt,renewalDue,notes\n"
			. "acme-gmbh,Acme GmbH,Ada,ada@acme.test,2,1,2027-01-01,INV-1,99.50,,2026-01-01,2026-12-01,hello\n";
		$parsed = $this->service()->parseCsv($csv);
		$this->assertCount(1, $parsed['rows']);
		$this->assertSame([], $parsed['errors']);
		$this->assertSame('acme-gmbh', $parsed['rows'][0]['customerId']);
		$this->assertSame('Acme GmbH', $parsed['rows'][0]['companyName']);
		$this->assertSame(2, $parsed['rows'][0]['mobileSeats']);
	}

	public function testMissingHeaderRejected(): void
	{
		$parsed = $this->service()->parseCsv("foo,bar\nx,y\n");
		$this->assertSame([], $parsed['rows']);
		$this->assertNotEmpty($parsed['errors']);
	}

	public function testInvalidEmailRejected(): void
	{
		$csv = "customerId,companyName,email\nacme-gmbh,Acme,not-an-email\n";
		$parsed = $this->service()->parseCsv($csv);
		$this->assertSame([], $parsed['rows']);
		$this->assertSame('EMAIL_INVALID', $parsed['errors'][0]['reason']);
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
}
