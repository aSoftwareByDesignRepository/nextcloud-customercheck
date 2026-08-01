<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PayWatchMapper;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\InvoicePaidSyncService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * PSD §14.3 — watermark transitions + ambiguity fail-closed.
 */
class InvoicePaidSyncServiceTest extends TestCase
{
	private function service(): InvoicePaidSyncService
	{
		return new InvoicePaidSyncService(
			$this->createMock(AgreementMapper::class),
			$this->createMock(PayWatchMapper::class),
			$this->createMock(ActivityService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(CompanyMapper::class),
			$this->createMock(FacadeProbeService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);
	}

	public function testBecamePaidIsTransition(): void
	{
		$s = $this->service();
		$this->assertTrue($s->isPaidTransition('sent', 0, 'paid', 1000));
	}

	public function testPaidIncreaseFromUnpaidIsTransition(): void
	{
		$s = $this->service();
		$this->assertTrue($s->isPaidTransition('sent', 0, 'sent', 500));
	}

	public function testSamePaidWatermarkIsNotTransition(): void
	{
		$s = $this->service();
		$this->assertFalse($s->isPaidTransition('paid', 1000, 'paid', 1000));
	}

	public function testPaidGrowthWhileAlreadyPaidIsTransition(): void
	{
		$s = $this->service();
		$this->assertTrue($s->isPaidTransition('paid', 500, 'paid', 1000));
	}

	public function testAmbiguousInvoiceRefDoesNotUpdate(): void
	{
		$a1 = new Agreement();
		$a1->setId(1);
		$a1->setCompanyId(10);
		$a2 = new Agreement();
		$a2->setId(2);
		$a2->setCompanyId(10);

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->expects($this->once())->method('findByInvoiceRef')->with('INV-9')->willReturn([$a1, $a2]);
		$agreements->expects($this->never())->method('update');

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append')->with(
			10,
			'invoice.paid.ambiguous',
			'system',
			$this->callback(static fn ($p) => ($p['invoiceRef'] ?? '') === 'INV-9'),
		);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('notifyWorkspaceUsers')->with(
			7,
			$this->anything(),
			'invoice_ref',
			'INV-9',
			$this->callback(static fn ($p) => ($p['ambiguous'] ?? 0) === 1),
		);
		$notifications->expects($this->never())->method('notifyCrmUsers');

		$company = new Company();
		$company->setId(10);
		$company->setWorkspaceId(7);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findById')->with(10)->willReturn($company);
		$companies->method('findByPcCustomerId')->with(42)->willReturn($company);

		$s = new InvoicePaidSyncService(
			$agreements,
			$this->createMock(PayWatchMapper::class),
			$this->createMock(ActivityService::class),
			$timeline,
			$notifications,
			$companies,
			$this->createMock(FacadeProbeService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);

		$this->assertSame('ambiguous', $s->applySignal([
			'invoiceRef' => 'INV-9',
			'status' => 'paid',
			'paidTotalCents' => 100,
			'pcCustomerId' => 42,
		]));
	}

	public function testInvoiceRefWithoutCompanyPinIsNoop(): void
	{
		$a1 = new Agreement();
		$a1->setId(1);
		$a1->setCompanyId(10);

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->expects($this->once())->method('findByInvoiceRef')->with('INV-ONLY')->willReturn([$a1]);
		$agreements->expects($this->never())->method('update');

		$s = new InvoicePaidSyncService(
			$agreements,
			$this->createMock(PayWatchMapper::class),
			$this->createMock(ActivityService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(CompanyMapper::class),
			$this->createMock(FacadeProbeService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);

		$this->assertSame('noop', $s->applySignal([
			'invoiceRef' => 'INV-ONLY',
			'status' => 'paid',
			'paidTotalCents' => 100,
		]));
	}

	public function testInvoiceRefPinnedToForeignPcCustomerIsNoop(): void
	{
		$a1 = new Agreement();
		$a1->setId(1);
		$a1->setCompanyId(10);

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->expects($this->once())->method('findByInvoiceRef')->willReturn([$a1]);
		$agreements->expects($this->never())->method('update');

		$foreign = new Company();
		$foreign->setId(99);
		$foreign->setWorkspaceId(2);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findByPcCustomerId')->with(7)->willReturn($foreign);

		$s = new InvoicePaidSyncService(
			$agreements,
			$this->createMock(PayWatchMapper::class),
			$this->createMock(ActivityService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(NotificationService::class),
			$companies,
			$this->createMock(FacadeProbeService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);

		$this->assertSame('noop', $s->applySignal([
			'invoiceRef' => 'INV-X',
			'status' => 'paid',
			'paidTotalCents' => 50,
			'pcCustomerId' => 7,
		]));
	}

	public function testPollNoopWhenFacadeAbsent(): void
	{
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(false);
		$facades->expects($this->never())->method('listPaidInvoiceSignals');

		$s = new InvoicePaidSyncService(
			$this->createMock(AgreementMapper::class),
			$this->createMock(PayWatchMapper::class),
			$this->createMock(ActivityService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(CompanyMapper::class),
			$facades,
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);

		$this->assertSame(['processed' => 0, 'paid' => 0, 'ambiguous' => 0, 'noop' => 0], $s->poll());
	}
}
