<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Service\AccountPlanService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\MoneyService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class WorkspaceMoneyIsolationTest extends TestCase
{
	public function testOverviewEmptyWhenWorkspaceHasNoCompanies(): void
	{
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->expects($this->never())->method('search');

		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->with(['workspaceId' => 9], 5000)->willReturn([]);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(false);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, null, 9);
		$this->assertSame(0, $out['meta']['total']);
		$this->assertSame(9, $out['meta']['workspaceId']);
		$this->assertSame([], $out['groups']['overdue']);
	}

	public function testOverviewPassesCompanyIdsForWorkspace(): void
	{
		$co = new Company();
		$co->setId(5);
		$co->setName('In WS');
		$co->setWorkspaceId(3);

		$ag = new Agreement();
		$ag->setId(1);
		$ag->setCompanyId(5);
		$ag->setLabel('Inv');
		$ag->setAmountCents(1200);
		$ag->setInvoiceStatus('overdue');

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('search')->willReturnCallback(static function (array $filters) use ($ag): array {
			if (($filters['invoiceStatus'] ?? '') === 'overdue'
				&& ($filters['companyIds'] ?? null) === [5]) {
				return [$ag];
			}
			return [];
		});

		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('search')->willReturn([$co]);
		$companies->method('findById')->with(5)->willReturn($co);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('invoicecheckAvailable')->willReturn(false);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));

		$svc = new MoneyService($agreements, $companies, $facades, $time);
		$out = $svc->overview(50, 'overdue', 3);
		$this->assertCount(1, $out['groups']['overdue']);
		$this->assertSame(1200, $out['groups']['overdue'][0]['amountCents']);
	}

	public function testAccountPlanHealthDeterministic(): void
	{
		$this->assertSame('on_track', AccountPlanService::health([
			'nextReviewOn' => '2099-01-01',
			'touchOverdue' => false,
			'openCallbacks' => 0,
			'renewalsHot' => false,
		]));
		$this->assertSame('stalled', AccountPlanService::health([
			'nextReviewOn' => '2099-01-01',
			'touchOverdue' => true,
		]));
		$this->assertSame('at_risk', AccountPlanService::health([
			'nextReviewOn' => '2020-01-01',
			'touchOverdue' => false,
		]));
		$this->assertSame('unknown', AccountPlanService::health([]));
	}
}
