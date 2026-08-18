<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\FilesAccessService;
use OCA\CustomerCheck\Service\StageSequenceService;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Deal create uses workspace lead SLA hours (§11.8).
 */
final class DealServiceLeadSlaTest extends TestCase
{
	public function testCreateLeadUsesWorkspaceSlaHours(): void
	{
		$company = new Company();
		$company->setId(4);
		$company->setWorkspaceId(2);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(4)->willReturn($company);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects($this->once())->method('getSettings')->with(2)->willReturn([
			'wipLimit' => 5,
			'leadSlaHours' => 36,
			'stuckChaseDays' => 3,
			'mailRetentionDays' => 365,
			'digestEnabled' => true,
		]);

		$inserted = null;
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('insert')->willReturnCallback(static function (Deal $d) use (&$inserted): Deal {
			$d->setId(88);
			$inserted = $d;
			return $d;
		});

		$sequences = $this->createMock(StageSequenceService::class);
		$sequences->method('fireForStage')->willReturn([]);
		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-20 10:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$config = $this->createMock(IConfig::class);

		$svc = new DealService(
			$mapper,
			$companies,
			$workspaces,
			$sequences,
			$timeline,
			$this->createMock(FilesAccessService::class),
			$config,
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\PersonMapper::class),
		);

		$deal = $svc->create([
			'companyId' => 4,
			'title' => 'Inbound',
			'stage' => 'lead',
		], 'ae');

		$this->assertSame(88, (int)$deal->getId());
		$this->assertNotNull($inserted);
		$this->assertSame('2026-07-21 22:00:00', $inserted->getLeadSlaDueAt()->format('Y-m-d H:i:s'));
	}

	public function testCreateLeadFallsBackToDefaultWithoutWorkspace(): void
	{
		$company = new Company();
		$company->setId(5);
		$company->setWorkspaceId(null);

		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->willReturn($company);

		$workspaces = $this->createMock(WorkspaceService::class);
		$workspaces->expects($this->never())->method('getSettings');

		$inserted = null;
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('insert')->willReturnCallback(static function (Deal $d) use (&$inserted): Deal {
			$d->setId(9);
			$inserted = $d;
			return $d;
		});

		$sequences = $this->createMock(StageSequenceService::class);
		$sequences->method('fireForStage')->willReturn([]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-20 10:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new DealService(
			$mapper,
			$companies,
			$workspaces,
			$sequences,
			$this->createMock(TimelineService::class),
			$this->createMock(FilesAccessService::class),
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$this->createMock(AgreementMapper::class),
			$this->createMock(\OCA\CustomerCheck\Db\PersonMapper::class),
		);

		$svc->create(['companyId' => 5, 'title' => 'X', 'stage' => 'lead'], 'ae');
		$hours = Constants::LEAD_SLA_HOURS_DEFAULT;
		$this->assertSame(
			(new \DateTime('2026-07-20 10:00:00'))->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s'),
			$inserted->getLeadSlaDueAt()->format('Y-m-d H:i:s'),
		);
	}
}
