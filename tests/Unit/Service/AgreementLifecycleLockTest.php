<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Exception\ConflictException;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\TimelineService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Supersede / cancel must lock the row and require an optimistic-lock token.
 */
final class AgreementLifecycleLockTest extends TestCase
{
	private function service(
		AgreementMapper $mapper,
		IDBConnection $db,
		?AgreementStatusService $status = null,
		?TimelineService $timeline = null,
		?ITimeFactory $time = null,
	): AgreementService {
		return new AgreementService(
			$mapper,
			$this->createMock(CompanyService::class),
			$status ?? $this->createMock(AgreementStatusService::class),
			$timeline ?? $this->createMock(TimelineService::class),
			$this->createMock(ProductCodeRegistry::class),
			$db,
			$time ?? $this->createMock(ITimeFactory::class),
			$this->createMock(IL10N::class),
		);
	}

	public function testSupersedeRequiresUpdatedAtPrecondition(): void
	{
		$mapper = $this->createMock(AgreementMapper::class);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');

		$previous = new Agreement();
		$previous->setId(42);
		$previous->setCompanyId(7);
		$previous->setStatus('active');
		$previous->setKind('license');
		$previous->setProductCode('azc');
		$previous->setLabel('AZC');
		$previous->setAmountCents(10000);
		$previous->setUpdatedAt(new \DateTime('2026-07-01T12:00:00+00:00'));

		$mapper->expects($this->once())->method('lockRow')->with(42)->willReturn(true);
		$mapper->expects($this->once())->method('findById')->with(42)->willReturn($previous);
		$mapper->expects($this->never())->method('insert');

		$svc = $this->service($mapper, $db);

		$this->expectException(ConflictException::class);
		$svc->supersede(42, ['validUntil' => '2027-07-01'], 'admin', null);
	}

	public function testCancelRequiresUpdatedAtPrecondition(): void
	{
		$mapper = $this->createMock(AgreementMapper::class);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');

		$agreement = new Agreement();
		$agreement->setId(9);
		$agreement->setCompanyId(3);
		$agreement->setStatus('active');
		$agreement->setUpdatedAt(new \DateTime('2026-07-01T12:00:00+00:00'));

		$mapper->method('lockRow')->with(9)->willReturn(true);
		$mapper->method('findById')->with(9)->willReturn($agreement);

		$svc = $this->service($mapper, $db);

		$this->expectException(ConflictException::class);
		$svc->cancel(9, 'Customer churned', 'admin', null);
	}

	public function testCancelWithFreshTokenLocksThenUpdates(): void
	{
		$mapper = $this->createMock(AgreementMapper::class);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$db->expects($this->never())->method('rollBack');

		$updatedAt = new \DateTime('2026-07-01T12:00:00+00:00');
		$agreement = new Agreement();
		$agreement->setId(9);
		$agreement->setCompanyId(3);
		$agreement->setStatus('active');
		$agreement->setUpdatedAt($updatedAt);

		$mapper->expects($this->once())->method('lockRow')->with(9)->willReturn(true);
		$mapper->expects($this->once())->method('findById')->with(9)->willReturn($agreement);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Agreement $a): Agreement {
				self::assertSame('cancelled', $a->getStatus());
				self::assertSame('Customer churned', $a->getCancelReason());
				return $a;
			}
		);

		$status = $this->createMock(AgreementStatusService::class);
		$status->expects($this->once())->method('assertTransitionAllowed')->with('active', 'cancelled');

		$timeline = $this->createMock(TimelineService::class);
		$timeline->expects($this->once())->method('append');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-27T09:00:00+00:00'));

		$svc = $this->service($mapper, $db, $status, $timeline, $time);

		$out = $svc->cancel(9, 'Customer churned', 'admin', OptimisticLock::format($updatedAt));
		$this->assertSame('cancelled', $out->getStatus());
	}
}
