<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Deal;
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
 * Today queue must attach company names — never "#id".
 */
final class TodayServiceTest extends TestCase
{
	/** @return array{0:TodayService,1:DealMapper,2:DealService} */
	private function buildService(
		AgreementMapper $agreements,
		ActivityMapper $activities,
		CompanyMapper $companies,
		FacadeProbeService $facades,
		AgreementStatusService $status,
		ITimeFactory $time,
		IL10N $l10n,
		DisplayLabelService $labels,
		?DealMapper $deals = null,
		?DealService $dealService = null,
	): array {
		$deals ??= $this->createMock(DealMapper::class);
		$deals->method('findOpenStaleBefore')->willReturn([]);
		$dealService ??= $this->createMock(DealService::class);
		$dealService->method('staleDealDays')->willReturn(14);
		$dealService->method('isStale')->willReturn(false);
		$svc = new TodayService(
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

		return [$svc, $deals, $dealService];
	}

	public function testAgreementAndActivityIncludeCompanyNames(): void
	{
		$company = new Company();
		$company->setId(5);
		$company->setName('Acme GmbH');

		$agreement = new Agreement();
		$agreement->setId(11);
		$agreement->setCompanyId(5);
		$agreement->setLabel('AZC renew');
		$agreement->setValidUntil(new \DateTime('2026-08-01'));

		$activity = new Activity();
		$activity->setId(22);
		$activity->setCompanyId(5);
		$activity->setTitle('Call back');
		$activity->setType('call');
		$activity->setDueDate(new \DateTime('2026-07-25'));

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findTodayRenewalCandidates')->willReturn([$agreement]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenDueUpTo')->willReturn([$activity]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findById')->with(5)->willReturn($company);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('ticketcheckAvailable')->willReturn(false);

		$status = $this->createMock(AgreementStatusService::class);
		$status->method('daysUntilExpiry')->willReturn(7);
		$status->method('isInGrace')->willReturn(false);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$labels = new DisplayLabelService($l10n);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		[$svc] = $this->buildService($agreements, $activities, $companies, $facades, $status, $time, $l10n, $labels);
		$queue = $svc->build(new \DateTime('2026-07-25'));

		$this->assertCount(2, $queue['items']);
		foreach ($queue['items'] as $item) {
			$this->assertSame('Acme GmbH', $item['companyName']);
			$this->assertStringNotContainsString('#', (string)$item['companyName']);
		}
	}

	public function testMissingCompanyUsesUnknownLabelNotHashId(): void
	{
		$agreement = new Agreement();
		$agreement->setId(1);
		$agreement->setCompanyId(999);
		$agreement->setLabel('Orphan');
		$agreement->setValidUntil(new \DateTime('2026-08-01'));

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findTodayRenewalCandidates')->willReturn([$agreement]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenDueUpTo')->willReturn([]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findById')->willThrowException(new \RuntimeException('gone'));

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('ticketcheckAvailable')->willReturn(false);
		$status = $this->createMock(AgreementStatusService::class);
		$status->method('daysUntilExpiry')->willReturn(3);
		$status->method('isInGrace')->willReturn(false);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$labels = new DisplayLabelService($l10n);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		[$svc] = $this->buildService($agreements, $activities, $companies, $facades, $status, $time, $l10n, $labels);
		$queue = $svc->build(new \DateTime('2026-07-25'));
		$this->assertSame('Unknown company', $queue['items'][0]['companyName']);
	}

	public function testEmitsStaleDealsWithUrgencyBandFifty(): void
	{
		$company = new Company();
		$company->setId(3);
		$company->setName('Stale Co');

		$deal = new Deal();
		$deal->setId(77);
		$deal->setCompanyId(3);
		$deal->setTitle('Old quote');
		$deal->setStage('quoted');
		$deal->setStageChangedAt(new \DateTime('2026-06-01'));

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findTodayRenewalCandidates')->willReturn([]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenDueUpTo')->willReturn([]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findById')->with(3)->willReturn($company);

		$deals = $this->createMock(DealMapper::class);
		$deals->expects($this->once())->method('findOpenStaleBefore')->willReturn([$deal]);
		$dealService = $this->createMock(DealService::class);
		$dealService->method('staleDealDays')->willReturn(14);
		$dealService->method('isStale')->with($deal, $this->anything())->willReturn(true);

		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('ticketcheckAvailable')->willReturn(false);
		$status = $this->createMock(AgreementStatusService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$labels = new DisplayLabelService($l10n);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		[$svc] = $this->buildService(
			$agreements,
			$activities,
			$companies,
			$facades,
			$status,
			$time,
			$l10n,
			$labels,
			$deals,
			$dealService,
		);
		$queue = $svc->build(new \DateTime('2026-07-25'));
		$this->assertCount(1, $queue['items']);
		$this->assertSame('deal_stale', $queue['items'][0]['kind']);
		$this->assertSame(50, $queue['items'][0]['urgency']);
		$this->assertSame(77, $queue['items'][0]['dealId']);
		$this->assertSame('Stale Co', $queue['items'][0]['companyName']);
	}

	public function testQueueSortsByUrgencyThenDueDate(): void
	{
		$company = new Company();
		$company->setId(1);
		$company->setName('Sort Co');

		$soon = new Agreement();
		$soon->setId(1);
		$soon->setCompanyId(1);
		$soon->setLabel('Soon');
		$soon->setValidUntil(new \DateTime('2026-07-28')); // ≤7d → urgency 10

		$later = new Agreement();
		$later->setId(2);
		$later->setCompanyId(1);
		$later->setLabel('Later');
		$later->setValidUntil(new \DateTime('2026-08-20')); // ≤30d → urgency 15

		$activityLate = new Activity();
		$activityLate->setId(3);
		$activityLate->setCompanyId(1);
		$activityLate->setTitle('Overdue call');
		$activityLate->setType('call');
		$activityLate->setDueDate(new \DateTime('2026-07-20')); // late → urgency 30

		$activitySameDay = new Activity();
		$activitySameDay->setId(4);
		$activitySameDay->setCompanyId(1);
		$activitySameDay->setTitle('Today call');
		$activitySameDay->setType('call');
		$activitySameDay->setDueDate(new \DateTime('2026-07-25')); // due today → urgency 40

		$agreements = $this->createMock(AgreementMapper::class);
		$agreements->method('findTodayRenewalCandidates')->willReturn([$later, $soon]);
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('findOpenDueUpTo')->willReturn([$activitySameDay, $activityLate]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findById')->willReturn($company);
		$facades = $this->createMock(FacadeProbeService::class);
		$facades->method('ticketcheckAvailable')->willReturn(false);
		$status = $this->createMock(AgreementStatusService::class);
		$status->method('daysUntilExpiry')->willReturnCallback(static function ($until, $today = null) {
			$base = $today instanceof \DateTimeInterface ? $today : new \DateTime('2026-07-25');
			if (!$until instanceof \DateTimeInterface) {
				return null;
			}
			$a = (new \DateTimeImmutable($base->format('Y-m-d')));
			$b = (new \DateTimeImmutable($until->format('Y-m-d')));
			return (int)$a->diff($b)->format('%r%a');
		});
		$status->method('isInGrace')->willReturn(false);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$labels = new DisplayLabelService($l10n);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-25'));

		[$svc] = $this->buildService($agreements, $activities, $companies, $facades, $status, $time, $l10n, $labels);
		$queue = $svc->build(new \DateTime('2026-07-25'));
		$kinds = array_map(
			static fn (array $i): string => (string)$i['kind'] . ':' . (string)$i['urgency'],
			$queue['items'],
		);
		// urgency ASC: 10 (soon), 15 (later), 30 (overdue activity), 40 (today activity)
		$this->assertSame(['agreement_expiry:10', 'agreement_expiry:15', 'activity_overdue:30', 'activity_due:40'], $kinds);
		$this->assertSame('2026-07-28', $queue['items'][0]['dueDate']);
		$this->assertSame('2026-08-20', $queue['items'][1]['dueDate']);
	}
}
