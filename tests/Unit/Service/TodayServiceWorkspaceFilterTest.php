<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\DisplayLabelService;
use OCA\CustomerCheck\Service\FacadeProbeService;
use OCA\CustomerCheck\Service\TodayService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Workspace-scoped Today must never blend books (product §3.3 / §21.1).
 */
final class TodayServiceWorkspaceFilterTest extends TestCase
{
	private function buildService(
		AgreementMapper $agreements,
		ActivityMapper $activities,
		CompanyMapper $companies,
		FacadeProbeService $facades,
		?DealMapper $deals = null,
		?DealService $dealService = null,
	): TodayService {
		$deals ??= $this->createMock(DealMapper::class);
		$deals->method('findOpenStaleBefore')->willReturn([]);
		$dealService ??= $this->createMock(DealService::class);
		$dealService->method('staleDealDays')->willReturn(14);
		$dealService->method('isStale')->willReturn(false);
		$status = $this->createMock(AgreementStatusService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$labels = new DisplayLabelService($l10n);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		return new TodayService(
			$agreements,
			$activities,
			$companies,
			$deals,
			$dealService,
			$facades,
			$status,
			$labels,
			$time,
			$l10n,
		);
	}

	public function testMappersReceiveWorkspaceIdWhenScoped(): void
	{
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->expects($this->once())
			->method('findRenewalCandidates')
			->with(2000, 0, 7)
			->willReturn([]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->expects($this->once())
			->method('findOpenDueUpTo')
			->with($this->anything(), 500, 7)
			->willReturn([]);
		$deals = $this->createMock(DealMapper::class);
		$deals->expects($this->once())
			->method('findOpenStaleBefore')
			->with($this->anything(), 200, 7)
			->willReturn([]);
		$companies = $this->createMock(CompanyMapper::class);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('ticketcheckAvailable')->willReturn(false);

		$svc = $this->buildService($agreements, $activities, $companies, $facades, $deals);
		$queue = $svc->build(new \DateTime('2026-07-25'), 7);

		$this->assertSame([], $queue['items']);
	}

	public function testWaitingTicketForCompanyInOtherWorkspaceDropped(): void
	{
		$foreign = new Company();
		$foreign->setId(50);
		$foreign->setName('Other book');
		$foreign->setWorkspaceId(99);

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findRenewalCandidates')->willReturn([]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenDueUpTo')->willReturn([]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findByTcCustomerId')->with(900)->willReturn($foreign);
		$companies->method('findById')->with(50)->willReturn($foreign);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('ticketcheckAvailable')->willReturn(true);
		$facades->method('listWaitingTickets')->willReturn([
			['id' => 1, 'companyId' => null, 'customerId' => 900, 'subject' => 'Leak me'],
		]);

		$svc = $this->buildService($agreements, $activities, $companies, $facades);
		$queue = $svc->build(new \DateTime('2026-07-25'), 7);

		$this->assertSame([], $queue['items']);
	}

	public function testWaitingTicketWithoutCompanyDroppedWhenWorkspaceScoped(): void
	{
		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findRenewalCandidates')->willReturn([]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenDueUpTo')->willReturn([]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findByTcCustomerId')->willReturn(null);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('ticketcheckAvailable')->willReturn(true);
		$facades->method('listWaitingTickets')->willReturn([
			['id' => 2, 'companyId' => null, 'customerId' => 0, 'subject' => 'Orphan ticket'],
		]);

		$svc = $this->buildService($agreements, $activities, $companies, $facades);
		$queue = $svc->build(new \DateTime('2026-07-25'), 7);

		$this->assertSame([], $queue['items']);
	}

	public function testWaitingTicketKeptWhenCompanyInWorkspace(): void
	{
		$mine = new Company();
		$mine->setId(10);
		$mine->setName('In book');
		$mine->setWorkspaceId(7);

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findRenewalCandidates')->willReturn([]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenDueUpTo')->willReturn([]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findByTcCustomerId')->with(501)->willReturn($mine);
		$companies->method('findById')->with(10)->willReturn($mine);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('ticketcheckAvailable')->willReturn(true);
		$facades->method('listWaitingTickets')->willReturn([
			// Real TicketCheck facade shape: companyId always null.
			['id' => 3, 'companyId' => null, 'customerId' => 501, 'subject' => 'Keep me'],
		]);

		$svc = $this->buildService($agreements, $activities, $companies, $facades);
		$queue = $svc->build(new \DateTime('2026-07-25'), 7);

		$this->assertCount(1, $queue['items']);
		$this->assertSame('ticket_waiting', $queue['items'][0]['kind']);
		$this->assertSame(10, $queue['items'][0]['companyId']);
	}
}
