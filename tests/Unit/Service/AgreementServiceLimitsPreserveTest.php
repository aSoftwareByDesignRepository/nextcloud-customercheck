<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
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
 * Aristoteles: edit must preserve partner hoursQuota; create without limits gets sellable defaults.
 */
final class AgreementServiceLimitsPreserveTest extends TestCase
{
	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s, array $a = []): string => $s);

		return $l;
	}

	private function service(
		AgreementMapper $mapper,
		IDBConnection $db,
		ProductCodeRegistry $codes,
		?CompanyService $companies = null,
		?AgreementStatusService $status = null,
	): AgreementService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-07T12:00:00+00:00'));

		return new AgreementService(
			$mapper,
			$companies ?? $this->createMock(CompanyService::class),
			$status ?? $this->createMock(AgreementStatusService::class),
			$this->createMock(TimelineService::class),
			$codes,
			$db,
			$time,
			$this->l10n(),
		);
	}

	public function testUpdateWithoutLimitsKeepsPartnerHoursQuota(): void
	{
		$agreement = new Agreement();
		$agreement->setId(44);
		$agreement->setCompanyId(3);
		$agreement->setKind('partner');
		$agreement->setProductCode('cp-std');
		$agreement->setLabel('Partner pack');
		$agreement->setLimitsJson(json_encode(['hoursQuota' => 10], JSON_THROW_ON_ERROR));
		$agreement->setAmountCents(50000);
		$agreement->setStatus('active');
		$agreement->setInvoiceStatus('none');
		$agreement->setStartsAt(new \DateTime('2026-01-01'));
		$agreement->setValidUntil(new \DateTime('2026-12-31'));
		$agreement->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(AgreementMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($agreement);
		$mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Agreement $a): Agreement {
				$limits = $a->decodedLimits();
				TestCase::assertSame(10, (int)($limits['hoursQuota'] ?? 0));
				TestCase::assertArrayNotHasKey('mobileSeats', $limits);
				TestCase::assertSame('Partner pack renamed', $a->getLabel());

				return $a;
			},
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('inTransaction')->willReturn(false);

		$codes = $this->createMock(ProductCodeRegistry::class);
		$codes->method('isAllowed')->willReturn(true);
		$codes->method('kindFor')->willReturn('partner');

		$status = $this->createMock(AgreementStatusService::class);
		$status->expects($this->once())->method('recompute');

		$svc = $this->service($mapper, $db, $codes, null, $status);
		// Mirrors the simplified form: kind/product/dates present, limits ABSENT.
		$svc->update(44, [
			'label' => 'Partner pack renamed',
			'kind' => 'partner',
			'productCode' => 'cp-std',
			'startsAt' => '2026-01-01',
			'validUntil' => '2026-12-31',
			'amountCents' => 50000,
			'invoiceStatus' => 'none',
			'updatedAt' => '2026-08-01T10:00:00+00:00',
		], 'admin', '2026-08-01T10:00:00+00:00');
	}

	public function testCreateWithoutLimitsAppliesLicenseSeatDefault(): void
	{
		$company = new Company();
		$company->setId(9);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(9)->willReturn($company);

		$mapper = $this->createMock(AgreementMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Agreement $a): Agreement {
				$limits = $a->decodedLimits();
				TestCase::assertSame(1, (int)($limits['mobileSeats'] ?? 0));
				TestCase::assertSame(0, (int)($limits['terminalDevices'] ?? 0));
				$a->setId(100);

				return $a;
			},
		);

		$db = $this->createMock(IDBConnection::class);
		$codes = $this->createMock(ProductCodeRegistry::class);
		$codes->method('isAllowed')->willReturn(true);
		$codes->method('kindFor')->willReturn('license');

		$status = $this->createMock(AgreementStatusService::class);
		$status->method('assertTransitionAllowed');
		$status->method('recompute');

		$svc = $this->service($mapper, $db, $codes, $companies, $status);
		$stored = $svc->create([
			'companyId' => 9,
			'label' => 'AZC seats',
			'kind' => 'license',
			'productCode' => 'azc',
			'startsAt' => '2026-08-07',
			'validUntil' => '2027-08-07',
			'amountCents' => 120000,
			'status' => 'active',
			// no limits key — form create path
		], 'admin');
		$this->assertSame(100, (int)$stored->getId());
	}

	public function testCreateWithoutLimitsAppliesPartnerHoursDefault(): void
	{
		$company = new Company();
		$company->setId(9);
		$companies = $this->createMock(CompanyService::class);
		$companies->method('get')->with(9)->willReturn($company);

		$mapper = $this->createMock(AgreementMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Agreement $a): Agreement {
				$limits = $a->decodedLimits();
				TestCase::assertSame(10, (int)($limits['hoursQuota'] ?? 0));
				$a->setId(101);

				return $a;
			},
		);

		$db = $this->createMock(IDBConnection::class);
		$codes = $this->createMock(ProductCodeRegistry::class);
		$codes->method('isAllowed')->willReturn(true);
		$codes->method('kindFor')->willReturn('partner');

		$status = $this->createMock(AgreementStatusService::class);
		$status->method('assertTransitionAllowed');
		$status->method('recompute');

		$svc = $this->service($mapper, $db, $codes, $companies, $status);
		$svc->create([
			'companyId' => 9,
			'label' => 'CP Standard',
			'kind' => 'partner',
			'productCode' => 'cp-std',
			'startsAt' => '2026-08-07',
			'validUntil' => '2027-08-07',
			'amountCents' => 90000,
			'status' => 'active',
		], 'admin');
	}

	public function testRogueLicenseLimitsOnPartnerUpdateAreRejected(): void
	{
		$agreement = new Agreement();
		$agreement->setId(44);
		$agreement->setCompanyId(3);
		$agreement->setKind('partner');
		$agreement->setProductCode('cp-std');
		$agreement->setLabel('Partner pack');
		$agreement->setLimitsJson(json_encode(['hoursQuota' => 10], JSON_THROW_ON_ERROR));
		$agreement->setAmountCents(50000);
		$agreement->setStatus('active');
		$agreement->setInvoiceStatus('none');
		$agreement->setStartsAt(new \DateTime('2026-01-01'));
		$agreement->setValidUntil(new \DateTime('2026-12-31'));
		$agreement->setUpdatedAt(new \DateTime('2026-08-01T10:00:00+00:00'));

		$mapper = $this->createMock(AgreementMapper::class);
		$mapper->method('lockRow')->willReturn(true);
		$mapper->method('findById')->willReturn($agreement);
		$mapper->expects($this->never())->method('update');

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('rollBack');
		$db->method('inTransaction')->willReturn(false);

		$codes = $this->createMock(ProductCodeRegistry::class);
		$codes->method('isAllowed')->willReturn(true);
		$codes->method('kindFor')->willReturn('partner');

		$svc = $this->service($mapper, $db, $codes);
		$this->expectException(\OCA\CustomerCheck\Exception\ValidationException::class);
		$svc->update(44, [
			'label' => 'Partner pack',
			'kind' => 'partner',
			'productCode' => 'cp-std',
			'startsAt' => '2026-01-01',
			'validUntil' => '2026-12-31',
			'amountCents' => 50000,
			'invoiceStatus' => 'none',
			'limits' => ['mobileSeats' => 1, 'terminalDevices' => 0],
			'updatedAt' => '2026-08-01T10:00:00+00:00',
		], 'admin', '2026-08-01T10:00:00+00:00');
	}
}
