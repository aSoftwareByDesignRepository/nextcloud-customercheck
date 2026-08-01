<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\DealMapper;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Exception\ValidationException;
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
 * Claim CAS — product §18.7 (no steal, no double-claim race).
 */
final class DealServiceClaimTest extends TestCase
{
	private function baseDeal(?string $owner = null): Deal
	{
		$d = new Deal();
		$d->setId(11);
		$d->setCompanyId(4);
		$d->setTitle('Inbound');
		$d->setStage('lead');
		$d->setOwnerUserId($owner);
		$d->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));
		return $d;
	}

	private function service(DealMapper $mapper, TimelineService $timeline): DealService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-21T12:00:00+00:00'));

		return new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(StageSequenceService::class),
			$timeline,
			$this->createMock(FilesAccessService::class),
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$this->createMock(IDBConnection::class),
			$this->createMock(AgreementMapper::class),
		);
	}

	public function testClaimUnownedSucceedsWithCas(): void
	{
		$deal = $this->baseDeal(null);
		$claimed = $this->baseDeal('ae');
		$claimed->setUpdatedAt(new \DateTime('2026-07-21T12:00:00+00:00'));

		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('findById')->willReturnOnConsecutiveCalls($deal, $claimed);
		$mapper->expects($this->once())
			->method('claimUnowned')
			->with(
				11,
				'ae',
				$this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d H:i:s') === '2026-07-20 10:00:00'),
				$this->isInstanceOf(\DateTimeInterface::class),
			)
			->willReturn(1);

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append')->with(
			4,
			'deal.claimed',
			'ae',
			$this->callback(static fn (array $p): bool => ($p['dealId'] ?? 0) === 11),
		);

		$result = $this->service($mapper, $timeline)->claim(
			11,
			'ae',
			'2026-07-20T10:00:00+00:00',
		);

		$this->assertSame('ae', $result->getOwnerUserId());
	}

	public function testClaimIdempotentWhenAlreadyOwnedByActor(): void
	{
		$deal = $this->baseDeal('ae');
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->never())->method('claimUnowned');

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->never())->method('append');

		$result = $this->service($mapper, $timeline)->claim(
			11,
			'ae',
			'2026-07-20T10:00:00+00:00',
		);
		$this->assertSame('ae', $result->getOwnerUserId());
	}

	public function testClaimRejectsSteal(): void
	{
		$deal = $this->baseDeal('sam');
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->never())->method('claimUnowned');

		$this->expectException(ValidationException::class);
		try {
			$this->service($mapper, $this->createMock(TimelineService::class))->claim(
				11,
				'ae',
				'2026-07-20T10:00:00+00:00',
			);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_DEAL_OWNED, $e->getErrorCode());
			throw $e;
		}
	}

	public function testClaimConflictWhenCasLosesRace(): void
	{
		$deal = $this->baseDeal(null);
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('findById')->willReturn($deal);
		$mapper->method('claimUnowned')->willReturn(0);

		$this->expectException(ConflictException::class);
		try {
			$this->service($mapper, $this->createMock(TimelineService::class))->claim(
				11,
				'ae',
				'2026-07-20T10:00:00+00:00',
			);
		} catch (ConflictException $e) {
			$this->assertSame(Constants::ERR_DEAL_OWNED, $e->getErrorCode());
			throw $e;
		}
	}

	public function testUpdateCannotStealOwnedDeal(): void
	{
		$deal = $this->baseDeal('sam');
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->with(11)->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->never())->method('update');
		$mapper->expects($this->never())->method('claimUnowned');

		$this->expectException(ValidationException::class);
		try {
			$this->service($mapper, $this->createMock(TimelineService::class))->update(
				11,
				['ownerUserId' => 'ae', 'updatedAt' => '2026-07-20T10:00:00+00:00'],
				'ae',
				false,
			);
		} catch (ValidationException $e) {
			$this->assertSame(Constants::ERR_DEAL_OWNED, $e->getErrorCode());
			throw $e;
		}
	}

	public function testUpdateManagerMayReassignOwnedDeal(): void
	{
		$deal = $this->baseDeal('sam');
		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->with(11)->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->once())->method('update')->willReturnCallback(static function (Deal $d): Deal {
			return $d;
		});

		$result = $this->service($mapper, $this->createMock(TimelineService::class))->update(
			11,
			['ownerUserId' => 'ae', 'updatedAt' => '2026-07-20T10:00:00+00:00'],
			'manager',
			true,
		);
		$this->assertSame('ae', $result->getOwnerUserId());
	}
}
