<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\ImportJob;
use OCA\CustomerCheck\Db\ImportJobMapper;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\LicenseKeyEventService;
use OCA\CustomerCheck\Service\NotificationService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LicenseKeyEventServiceTest extends TestCase
{
	private CompanyMapper&MockObject $companies;
	private AgreementMapper&MockObject $agreements;
	private AgreementService&MockObject $agreementService;
	private ImportJobMapper&MockObject $importJobs;
	private NotificationService&MockObject $notifications;
	private TimelineService&MockObject $timeline;
	private LicenseKeyEventService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->companies = $this->createMock(CompanyMapper::class);
		$this->agreements = $this->createMock(AgreementMapper::class);
		$this->agreementService = $this->createMock(AgreementService::class);
		$this->importJobs = $this->createMock(ImportJobMapper::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->timeline = $this->createMock(TimelineService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []) => vsprintf($s, $a));
		$this->service = new LicenseKeyEventService(
			$this->companies,
			$this->agreements,
			$this->agreementService,
			$this->importJobs,
			$this->notifications,
			$this->timeline,
			$time,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testUnknownSlugCreatesInboxNotCompany(): void
	{
		$this->agreements->method('findBySalesLogId')->willReturn(null);
		$this->companies->method('findBySlug')->with('unknown-co')->willReturn(null);
		$this->agreementService->expects($this->never())->method('create');
		$this->importJobs->expects($this->once())->method('insert')->willReturnCallback(
			static function (ImportJob $job): ImportJob {
				self::assertSame('license_event_orphan', $job->getSource());
				$job->setId(9);
				return $job;
			}
		);

		$result = $this->service->handle([
			'salesLogId' => 3,
			'customerId' => 'unknown-co',
			'product' => 'arbeitszeitcheck',
			'fingerprint' => 'AZC2.abc',
			'validUntil' => '2027-07-25',
			'limits' => ['mobileSeats' => 5, 'terminalDevices' => 1],
		]);

		$this->assertSame('inbox', $result['action']);
		$this->assertSame(9, $result['importJobId']);
	}

	public function testKnownSlugCreatesDraftAgreementOnce(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setSlug('acme-gmbh');
		$company->setName('Acme GmbH');
		$company->setWorkspaceId(3);

		$agreement = new Agreement();
		$agreement->setId(11);
		$agreement->setCompanyId(4);

		$this->agreements->method('findBySalesLogId')->willReturn(null);
		$this->companies->method('findBySlug')->with('acme-gmbh')->willReturn($company);
		$this->agreementService->expects($this->once())->method('create')->with(
			$this->callback(static function (array $input): bool {
				return ($input['status'] ?? '') === 'draft'
					&& ($input['productCode'] ?? '') === 'azc'
					&& (int)($input['limits']['mobileSeats'] ?? 0) === 5
					&& (int)($input['salesLogId'] ?? 0) === 3;
			}),
			'system',
		)->willReturn($agreement);
		$this->notifications->expects($this->once())->method('notifyWorkspaceUsers')->with(
			3,
			Constants::NOTIFY_KEY_GENERATED,
			'agreement',
			'11',
			$this->callback(static fn (array $p): bool => ($p['companyName'] ?? '') === 'Acme GmbH'),
		);
		$this->notifications->expects($this->never())->method('notifyCrmUsers');
		$this->importJobs->expects($this->never())->method('insert');

		$result = $this->service->handle([
			'salesLogId' => 3,
			'customerId' => 'acme-gmbh',
			'product' => 'arbeitszeitcheck',
			'companyName' => 'Acme GmbH',
			'fingerprint' => 'AZC2.abc',
			'validUntil' => '2027-07-25',
			'limits' => ['mobileSeats' => 5, 'terminalDevices' => 1],
			'amountEurCents' => 12000,
		]);

		$this->assertSame('draft', $result['action']);
		$this->assertSame(11, $result['agreementId']);
	}

	public function testExistingSalesLogIsIdempotent(): void
	{
		$existing = new Agreement();
		$existing->setId(7);
		$existing->setCompanyId(2);
		$this->agreements->method('findBySalesLogId')->with(3)->willReturn($existing);
		$this->agreementService->expects($this->never())->method('create');
		$this->importJobs->expects($this->never())->method('insert');

		$result = $this->service->handle([
			'salesLogId' => 3,
			'customerId' => 'acme-gmbh',
		]);

		$this->assertSame('skip', $result['action']);
		$this->assertSame(7, $result['agreementId']);
	}

	/**
	 * InvoiceCheck (IC2) must map to CRM product `ic`, never InventoryCheck `iv`.
	 *
	 * @dataProvider invoiceCheckProductAliases
	 */
	public function testInvoiceCheckMapsToIcNotIv(string $productAlias): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setSlug('acme-gmbh');
		$company->setName('Acme GmbH');
		$company->setWorkspaceId(3);

		$agreement = new Agreement();
		$agreement->setId(22);
		$agreement->setCompanyId(4);

		$this->agreements->method('findBySalesLogId')->willReturn(null);
		$this->companies->method('findBySlug')->willReturn($company);
		$this->agreementService->expects($this->once())->method('create')->with(
			$this->callback(static function (array $input): bool {
				return ($input['productCode'] ?? '') === 'ic';
			}),
			'system',
		)->willReturn($agreement);
		$this->notifications->method('notifyWorkspaceUsers');

		$result = $this->service->handle([
			'salesLogId' => 9,
			'customerId' => 'acme-gmbh',
			'product' => $productAlias,
			'companyName' => 'Acme GmbH',
			'fingerprint' => 'IC2.abc',
			'validUntil' => '2027-07-25',
			'limits' => ['mobileSeats' => 5],
			'amountEurCents' => 3000,
		]);

		$this->assertSame('draft', $result['action']);
		$this->assertSame(22, $result['agreementId']);
	}

	/** @return list<array{0: string}> */
	public static function invoiceCheckProductAliases(): array
	{
		return [
			['invoicecheck'],
			['ic'],
			['ic2'],
			['IC2'],
		];
	}

	public function testInventoryCheckMapsToIvNotIc(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setSlug('acme-gmbh');
		$company->setName('Acme GmbH');
		$company->setWorkspaceId(3);

		$agreement = new Agreement();
		$agreement->setId(23);
		$agreement->setCompanyId(4);

		$this->agreements->method('findBySalesLogId')->willReturn(null);
		$this->companies->method('findBySlug')->willReturn($company);
		$this->agreementService->expects($this->once())->method('create')->with(
			$this->callback(static function (array $input): bool {
				return ($input['productCode'] ?? '') === 'iv';
			}),
			'system',
		)->willReturn($agreement);
		$this->notifications->method('notifyWorkspaceUsers');

		$result = $this->service->handle([
			'salesLogId' => 10,
			'customerId' => 'acme-gmbh',
			'product' => 'inventorycheck',
			'fingerprint' => 'IV2.abc',
			'validUntil' => '2027-07-25',
			'limits' => ['mobileSeats' => 5, 'terminalDevices' => 1],
		]);

		$this->assertSame('draft', $result['action']);
	}
}
