<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\ActivityMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\ChaseService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\OooCoverService;
use OCA\CustomerCheck\Service\StageSequenceService;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance gates: OOO covering in Mine + loss code required (§11.6 / §13.4 / §18.7).
 */
final class ChaseOooAndLossCodeTest extends TestCase
{
	private function company(int $ws = 7): Company
	{
		$c = new Company();
		$c->setId(9);
		$c->setName('Acme');
		$c->setSlug('acme');
		$c->setWorkspaceId($ws);
		return $c;
	}

	private function openDeal(string $owner = 'alex'): Deal
	{
		$d = new Deal();
		$d->setId(42);
		$d->setCompanyId(9);
		$d->setTitle('Big deal');
		$d->setStage('quoted');
		$d->setOwnerUserId($owner);
		$d->setStageChangedAt(new \DateTime('2026-06-01'));
		return $d;
	}

	public function testMyChaseIncludesCoveredPrimaryDealsWithBadge(): void
	{
		$today = new \DateTime('2026-07-28');
		$ooo = $this->createMock(OooCoverService::class);
		$ooo->method('mineOwnerIds')->willReturn(['sam', 'alex']);

		$deals = $this->createMock(DealMapper::class);
		$deals->method('search')->willReturn([$this->openDeal('alex')]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findById')->willReturn($this->company());
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('search')->willReturn([]);

		$dealService = $this->createMock(DealService::class);
		$dealService->method('staleDealDays')->willReturn(14);
		$dealService->method('toArray')->willReturn([
			'id' => 42,
			'companyId' => 9,
			'title' => 'Big deal',
			'stage' => 'quoted',
			'ownerUserId' => 'alex',
			'quoteAgeDays' => 20,
		]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($today);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new ChaseService($deals, $activities, $companies, $dealService, $ooo, $time, $l10n);
		$rows = $svc->myChase('sam', 7, $today, true);

		$this->assertCount(1, $rows);
		$this->assertTrue($rows[0]['covering']);
		$this->assertSame('alex', $rows[0]['coveringPrimary']);
		$this->assertSame('Covering', $rows[0]['coveringBadge']);
	}

	public function testMyChaseWithoutOooIgnoresCoveredPrimary(): void
	{
		$today = new \DateTime('2026-07-28');
		$ooo = $this->createMock(OooCoverService::class);
		$ooo->expects($this->never())->method('mineOwnerIds');

		$deals = $this->createMock(DealMapper::class);
		$deals->method('search')->willReturn([$this->openDeal('alex')]);
		$companies = $this->createMock(CompanyMapper::class);
		$companies->method('findById')->willReturn($this->company());
		$activities = $this->createMock(ActivityMapper::class);
		$activities->method('search')->willReturn([]);
		$dealService = $this->createMock(DealService::class);
		$dealService->method('staleDealDays')->willReturn(14);
		$dealService->method('toArray')->willReturn(['id' => 42, 'companyId' => 9, 'title' => 'x', 'stage' => 'quoted']);

		$time = $this->createMock(ITimeFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new ChaseService($deals, $activities, $companies, $dealService, $ooo, $time, $l10n);
		$this->assertSame([], $svc->myChase('sam', 7, $today, false));
	}

	public function testLostWithoutCodeRejectedEvenWithFreeText(): void
	{
		$deal = new Deal();
		$deal->setId(1);
		$deal->setCompanyId(9);
		$deal->setTitle('X');
		$deal->setStage('quoted');
		$deal->setUpdatedAt(new \DateTime('2026-07-01T12:00:00+00:00'));

		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->never())->method('update');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('14');

		$svc = new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(StageSequenceService::class),
			$this->createMock(TimelineService::class),
			$config,
			$time,
			$l10n,
		);

		try {
			$svc->changeStage(1, 'lost', 'they ghosted us', '2026-07-01T12:00:00+00:00', 'ae', []);
			$this->fail('Expected ValidationException for missing loss code');
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_LOSS_CODE_REQUIRED, $e->getErrorCode());
		}
	}

	public function testLostWithKnownCodeSucceeds(): void
	{
		$deal = new Deal();
		$deal->setId(1);
		$deal->setCompanyId(9);
		$deal->setTitle('X');
		$deal->setStage('quoted');
		$deal->setUpdatedAt(new \DateTime('2026-07-01T12:00:00+00:00'));

		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('findById')->willReturn($deal);
		$mapper->method('update')->willReturnCallback(static fn (Deal $d): Deal => $d);

		$sequences = $this->createMock(StageSequenceService::class);
		$sequences->method('fireForStage')->willReturn([]);

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-28'));
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('14');

		$svc = new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$sequences,
			$timeline,
			$config,
			$time,
			$l10n,
		);
		$result = $svc->changeStage(1, 'lost', null, '2026-07-01T12:00:00+00:00', 'ae', [
			'lostReasonCode' => 'competitor',
		]);
		$this->assertSame('lost', $result['deal']->getStage());
		$this->assertSame('competitor', $result['deal']->getLostReasonCode());
	}
}
