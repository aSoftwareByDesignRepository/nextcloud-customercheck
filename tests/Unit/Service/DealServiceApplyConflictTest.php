<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

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
 * Conflict apply must skip optimistic precondition (GNQ §6.4).
 */
final class DealServiceApplyConflictTest extends TestCase
{
	public function testApplyConflictResolutionWithoutUpdatedAt(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(2);
		$deal->setTitle('Old');
		$deal->setStage('quoted');
		$deal->setValueCents(100);
		$deal->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));

		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Deal $d): Deal {
				return $d;
			},
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('inTransaction')->willReturn(false);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-01T12:00:00+00:00'));

		$svc = new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(StageSequenceService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(FilesAccessService::class),
			$this->createMock(IConfig::class),
			$time,
			$l10n,
			$db,
			$this->createMock(AgreementMapper::class),
		);

		$result = $svc->applyConflictResolution(5, ['title' => 'Resolved title', 'valueCents' => 500], 'manager', true);
		$this->assertSame('Resolved title', $result->getTitle());
		$this->assertSame(500, $result->getValueCents());
	}

	public function testUpdateWithoutUpdatedAtStillRequiresPrecondition(): void
	{
		$deal = new Deal();
		$deal->setId(5);
		$deal->setCompanyId(2);
		$deal->setTitle('Old');
		$deal->setStage('quoted');
		$deal->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));

		$mapper = $this->createMock(DealMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($deal);
		$mapper->expects($this->never())->method('update');

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(false);
		$db->expects($this->once())->method('rollBack');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new DealService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(WorkspaceService::class),
			$this->createMock(StageSequenceService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(FilesAccessService::class),
			$this->createMock(IConfig::class),
			$this->createMock(ITimeFactory::class),
			$l10n,
			$db,
			$this->createMock(AgreementMapper::class),
		);

		$this->expectException(\OCA\CustomerCheck\Exception\ConflictException::class);
		$svc->update(5, ['title' => 'Nope'], 'ae');
	}
}
