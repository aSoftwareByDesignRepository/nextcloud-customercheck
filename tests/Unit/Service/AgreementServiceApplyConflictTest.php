<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\AgreementStatusService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\TimelineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Conflict apply must skip optimistic precondition (GNQ §6.4 / agreement parity).
 */
final class AgreementServiceApplyConflictTest extends TestCase
{
	public function testApplyConflictResolutionWithoutUpdatedAt(): void
	{
		$agreement = new Agreement();
		$agreement->setId(9);
		$agreement->setCompanyId(2);
		$agreement->setKind('license');
		$agreement->setProductCode('azc');
		$agreement->setLabel('Old label');
		$agreement->setAmountCents(1000);
		$agreement->setStatus('active');
		$agreement->setInvoiceStatus('none');
		$agreement->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));

		$mapper = $this->createMock(AgreementMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($agreement);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Agreement $a): Agreement {
				return $a;
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

		$status = $this->createMock(AgreementStatusService::class);
		$status->expects($this->once())->method('recompute');

		$codes = $this->createMock(ProductCodeRegistry::class);
		$codes->method('isAllowed')->willReturn(true);

		$svc = new AgreementService(
			$mapper,
			$this->createMock(CompanyService::class),
			$status,
			$this->createMock(TimelineService::class),
			$codes,
			$db,
			$time,
			$l10n,
		);

		$result = $svc->applyConflictResolution(9, ['label' => 'Resolved label', 'amountCents' => 2500], 'manager');
		$this->assertSame('Resolved label', $result->getLabel());
		$this->assertSame(2500, $result->getAmountCents());
	}

	public function testUpdateWithoutUpdatedAtStillRequiresPrecondition(): void
	{
		$agreement = new Agreement();
		$agreement->setId(9);
		$agreement->setCompanyId(2);
		$agreement->setKind('license');
		$agreement->setProductCode('azc');
		$agreement->setLabel('Old');
		$agreement->setStatus('active');
		$agreement->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));

		$mapper = $this->createMock(AgreementMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($agreement);
		$mapper->expects($this->never())->method('update');

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(false);
		$db->expects($this->once())->method('rollBack');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new AgreementService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(AgreementStatusService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(ProductCodeRegistry::class),
			$db,
			$this->createMock(ITimeFactory::class),
			$l10n,
		);

		$this->expectException(\OCA\CustomerCheck\Exception\ConflictException::class);
		// Stale stamp must fail on user-facing update.
		$svc->update(9, ['label' => 'Nope'], 'ae', '2026-01-01T00:00:00+00:00');
	}

	public function testUpdateWithoutUpdatedAtPreconditionIsRejected(): void
	{
		$agreement = new Agreement();
		$agreement->setId(9);
		$agreement->setCompanyId(2);
		$agreement->setKind('license');
		$agreement->setProductCode('azc');
		$agreement->setLabel('Old');
		$agreement->setStatus('active');
		$agreement->setUpdatedAt(new \DateTime('2026-07-20T10:00:00+00:00'));

		$mapper = $this->createMock(AgreementMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($agreement);
		$mapper->expects($this->never())->method('update');

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('inTransaction')->willReturn(false);
		$db->expects($this->once())->method('rollBack');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);

		$svc = new AgreementService(
			$mapper,
			$this->createMock(CompanyService::class),
			$this->createMock(AgreementStatusService::class),
			$this->createMock(TimelineService::class),
			$this->createMock(ProductCodeRegistry::class),
			$db,
			$this->createMock(ITimeFactory::class),
			$l10n,
		);

		$this->expectException(\OCA\CustomerCheck\Exception\ConflictException::class);
		$svc->update(9, ['label' => 'Nope'], 'ae', null);
	}
}
