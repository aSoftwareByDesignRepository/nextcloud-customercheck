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
			return ($filters['invoiceStatus'] ?? '') === 'none' ? [$none] : [];
		});

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findById')->willReturn($company);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(false);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'unknown');
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
			return ($filters['invoiceStatus'] ?? '') === 'overdue' ? [$manual] : [];
		});
		$agreements->method('findByInvoiceRef')->with('INV-9')->willReturn([$manual]);

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$company->setPcCustomerId(44);
		$companies = $this->createMock(CompanyMapper::class);
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
		$out = $svc->overview(50, 'overdue');
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
			return ($filters['invoiceStatus'] ?? '') === 'paid' ? [$manual] : [];
		});
		$agreements->method('findByInvoiceRef')->with('INV-PAID')->willReturn([$manual]);

		$company = new Company();
		$company->setId(9);
		$company->setName('Acme');
		$company->setPcCustomerId(44);
		$companies = $this->createMock(CompanyMapper::class);
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
		$out = $svc->overview(50, 'paid30d');
		$this->assertCount(1, $out['groups']['paid30d']);
		$this->assertSame('invoicecheck', $out['groups']['paid30d'][0]['source']);
		$this->assertSame(200, $out['groups']['paid30d'][0]['icInvoiceId']);
	}
}
