<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Service\AgreementStatusService;
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
		$agreements->method('findRenewalCandidates')->willReturn([$agreement]);
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

		$svc = new TodayService($agreements, $activities, $companies, $facades, $status, $labels, $time, $l10n);
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
		$agreements->method('findRenewalCandidates')->willReturn([$agreement]);
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

		$svc = new TodayService($agreements, $activities, $companies, $facades, $status, $labels, $time, $l10n);
		$queue = $svc->build(new \DateTime('2026-07-25'));
		$this->assertSame('Unknown company', $queue['items'][0]['companyName']);
	}
}
