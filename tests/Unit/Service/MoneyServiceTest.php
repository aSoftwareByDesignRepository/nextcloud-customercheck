<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\MoneyService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class MoneyServiceTest extends TestCase
{
	public function testMissingWorkspaceFailsClosedEmpty(): void
	{
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->expects($this->never())->method('search');
		$companies = $this->createMock(CompanyMapper::class);
		$companies->expects($this->never())->method('search');
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'unknown', null);
		$this->assertSame(0, $out['meta']['total']);
		$this->assertNull($out['meta']['workspaceId']);
		$this->assertSame([], $out['groups']['unknown']);
	}

	public function testUnknownBucketUsesInvoiceStatusNone(): void
	{
		$none = new Agreement();
		$none->setId(1);
		$none->setCompanyId(9);
		$none->setLabel('No invoice yet');
		$none->setAmountCents(1000);
		$none->setInvoiceStatus('none');

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('search')->willReturnCallback(static function (array $filters) use ($none): array {
			return ($filters['invoiceStatus'] ?? '') === 'none'
				&& ($filters['companyIds'] ?? null) === [9]
				? [$none] : [];
		});

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$company->setWorkspaceId(1);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->with(['workspaceId' => 1], 5000)->willReturn([$company]);
		$companies->method('findById')->willReturn($company);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(false);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'unknown', 1);
		$this->assertCount(1, $out['groups']['unknown']);
		$this->assertSame('unknown', $out['groups']['unknown'][0]['bucket']);
		$this->assertSame('none', $out['groups']['unknown'][0]['invoiceStatus']);
	}

	public function testIcOverdueDedupesMatchingInvoiceRef(): void
	{
		$manual = new Agreement();
		$manual->setId(2);
		$manual->setCompanyId(9);
		$manual->setLabel('CRM overdue');
		$manual->setAmountCents(5000);
		$manual->setInvoiceStatus('overdue');
		$manual->setInvoiceRef('INV-9');

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('search')->willReturnCallback(static function (array $filters) use ($manual): array {
			return ($filters['invoiceStatus'] ?? '') === 'overdue'
				&& ($filters['companyIds'] ?? null) === [9]
				? [$manual] : [];
		});
		$agreements->method('findByInvoiceRef')->with('INV-9')->willReturn([$manual]);

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$company->setPcCustomerId(44);
		$company->setWorkspaceId(1);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->willReturn([$company]);
		$companies->method('findById')->willReturn($company);
		$companies->method('findByPcCustomerId')->with(44)->willReturn($company);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(true);
		$facades->method('listOverdueInvoices')->willReturn([[
			'icInvoiceId' => 100,
			'invoiceRef' => 'INV-9',
			'grossTotalCents' => 5000,
			'paidTotalCents' => 0,
			'pcCustomerId' => 44,
			'dueDate' => '2026-01-01',
		]]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'overdue', 1);
		$this->assertCount(1, $out['groups']['overdue']);
		$this->assertSame('invoicecheck', $out['groups']['overdue'][0]['source']);
		$this->assertSame(2, $out['groups']['overdue'][0]['agreementId']);
	}

	public function testIcPaidDedupesMatchingInvoiceRef(): void
	{
		$manual = new Agreement();
		$manual->setId(3);
		$manual->setCompanyId(9);
		$manual->setLabel('CRM paid');
		$manual->setAmountCents(5000);
		$manual->setInvoiceStatus('paid');
		$manual->setInvoiceRef('INV-PAID');
		$manual->setUpdatedAt(new \DateTime('2026-07-20'));

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('search')->willReturnCallback(static function (array $filters) use ($manual): array {
			return ($filters['invoiceStatus'] ?? '') === 'paid'
				&& ($filters['companyIds'] ?? null) === [9]
				? [$manual] : [];
		});
		$agreements->method('findByInvoiceRef')->with('INV-PAID')->willReturn([$manual]);

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$company->setPcCustomerId(44);
		$company->setWorkspaceId(1);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->willReturn([$company]);
		$companies->method('findById')->willReturn($company);
		$companies->method('findByPcCustomerId')->with(44)->willReturn($company);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(true);
		$facades->method('listOverdueInvoices')->willReturn([]);
		$facades->method('listPaidInvoiceSignals')->willReturn([[
			'icInvoiceId' => 200,
			'invoiceRef' => 'INV-PAID',
			'paidTotalCents' => 5000,
			'status' => 'paid',
			'pcCustomerId' => 44,
		]]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'paid30d', 1);
		$this->assertCount(1, $out['groups']['paid30d']);
		$this->assertSame('invoicecheck', $out['groups']['paid30d'][0]['source']);
		$this->assertSame(200, $out['groups']['paid30d'][0]['icInvoiceId']);
	}

	public function testIcOverdueIgnoresForeignWorkspaceInvoiceRef(): void
	{
		$foreign = new Agreement();
		$foreign->setId(99);
		$foreign->setCompanyId(55);
		$foreign->setInvoiceRef('INV-9');

		$local = new Agreement();
		$local->setId(2);
		$local->setCompanyId(9);
		$local->setLabel('CRM overdue');
		$local->setAmountCents(5000);
		$local->setInvoiceStatus('overdue');
		$local->setInvoiceRef('INV-9');

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('search')->willReturn([]);
		// Foreign book listed first — scoped match must still pick local company 9.
		$agreements->method('findByInvoiceRef')->with('INV-9')->willReturn([$foreign, $local]);

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$company->setPcCustomerId(44);
		$company->setWorkspaceId(1);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->willReturn([$company]);
		$companies->method('findById')->willReturn($company);
		$companies->method('findByPcCustomerId')->with(44)->willReturn($company);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(true);
		$facades->method('listOverdueInvoices')->willReturn([[
			'icInvoiceId' => 100,
			'invoiceRef' => 'INV-9',
			'grossTotalCents' => 5000,
			'paidTotalCents' => 0,
			'pcCustomerId' => 44,
			'dueDate' => '2026-01-01',
		]]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'overdue', 1);
		$this->assertCount(1, $out['groups']['overdue']);
		$this->assertSame(2, $out['groups']['overdue'][0]['agreementId']);
		$this->assertSame(9, $out['groups']['overdue'][0]['companyId']);
	}

	public function testIcOverdueFeatureDetectsAgingNullSafe(): void
	{
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('search')->willReturn([]);
		$agreements->method('findByInvoiceRef')->willReturn([]);

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$company->setPcCustomerId(44);
		$company->setWorkspaceId(1);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->willReturn([$company]);
		$companies->method('findById')->willReturn($company);
		$companies->method('findByPcCustomerId')->with(44)->willReturn($company);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(true);
		// v2-style payload without aging keys — must stay null, never invent.
		$facades->method('listOverdueInvoices')->willReturn([[
			'icInvoiceId' => 100,
			'invoiceRef' => 'INV-V2',
			'grossTotalCents' => 5000,
			'paidTotalCents' => 0,
			'pcCustomerId' => 44,
			'dueDate' => '2026-01-01',
		]]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'overdue', 1);
		$this->assertCount(1, $out['groups']['overdue']);
		$this->assertNull($out['groups']['overdue'][0]['agingBucket']);
		$this->assertNull($out['groups']['overdue'][0]['daysPastDue']);
	}

	public function testIcOverduePassesFacadeV3AgingWhenPresent(): void
	{
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('search')->willReturn([]);
		$agreements->method('findByInvoiceRef')->willReturn([]);

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$company->setPcCustomerId(44);
		$company->setWorkspaceId(1);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->willReturn([$company]);
		$companies->method('findById')->willReturn($company);
		$companies->method('findByPcCustomerId')->with(44)->willReturn($company);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(true);
		$facades->method('listOverdueInvoices')->willReturn([[
			'icInvoiceId' => 101,
			'invoiceRef' => 'INV-V3',
			'grossTotalCents' => 11900,
			'paidTotalCents' => 0,
			'remainingCents' => 11900,
			'pcCustomerId' => 44,
			'dueDate' => '2026-01-01',
			'agingBucket' => '90+',
			'daysPastDue' => 205,
			'writtenOff' => false,
			'facadeVersion' => 3,
		]]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'overdue', 1);
		$row = $out['groups']['overdue'][0];
		$this->assertSame('90+', $row['agingBucket']);
		$this->assertSame(205, $row['daysPastDue']);
		$this->assertSame(11900, $row['amountCents']);
		$this->assertSame(11900, $row['remainingCents']);
	}
}
