<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\Activity;
use OCA\CustomerCheck\Db\Agreement;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Service\MobileApiService;
use PHPUnit\Framework\TestCase;

/**
 * Mobile contract mapping (COMPANION-APP §9.4–§9.8): Today items, body
 * normalisation, and entity serialisation for the companion app.
 */
final class MobileApiServiceTest extends TestCase
{
	// ── mapTodayItem ────────────────────────────────────────────────────

	public function testMapsAgreementExpiryToRenewalWarning(): void
	{
		$mapped = MobileApiService::mapTodayItem([
			'kind' => 'agreement_expiry',
			'companyId' => 12,
			'companyName' => 'ACME',
			'agreementId' => 7,
			'label' => 'Care contract',
			'dueDate' => '2026-09-01',
			'daysUntil' => 20,
			'inGrace' => false,
		]);

		$this->assertSame('renewal', $mapped['kind']);
		$this->assertSame('warning', $mapped['urgency']);
		$this->assertSame('agr:7:d20', $mapped['id']);
		$this->assertSame('ACME — Care contract', $mapped['title']);
		$this->assertSame('2026-09-01', $mapped['subtitle']);
		$this->assertSame(12, $mapped['companyId']);
		$this->assertSame(['type' => 'agreement', 'id' => 7], $mapped['deepLink']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function renewalDangerCases(): array
	{
		return [
			'within 7 days' => [['daysUntil' => 7, 'inGrace' => false], 'danger'],
			'in grace' => [['daysUntil' => 45, 'inGrace' => true], 'danger'],
			'8 days out' => [['daysUntil' => 8, 'inGrace' => false], 'warning'],
		];
	}

	/** @dataProvider renewalDangerCases */
	public function testRenewalUrgencyBoundaries(array $overrides, string $expected): void
	{
		$mapped = MobileApiService::mapTodayItem(array_merge([
			'kind' => 'agreement_expiry',
			'companyId' => 1,
			'companyName' => 'ACME',
			'agreementId' => 7,
			'label' => 'X',
		], $overrides));

		$this->assertSame($expected, $mapped['urgency']);
	}

	public function testMapsWaitingTicket(): void
	{
		$mapped = MobileApiService::mapTodayItem([
			'kind' => 'ticket_waiting',
			'companyId' => null,
			'companyName' => '',
			'ticketId' => 55,
			'label' => 'Printer on fire',
		]);

		$this->assertSame('ticket_waiting', $mapped['kind']);
		$this->assertSame('warning', $mapped['urgency']);
		$this->assertSame('tkt:55', $mapped['id']);
		$this->assertSame('Printer on fire', $mapped['title'], 'No company prefix without a company');
		$this->assertNull($mapped['companyId']);
		$this->assertSame(['type' => 'ticket', 'id' => 55], $mapped['deepLink']);
	}

	public function testMapsOverdueActivityToDanger(): void
	{
		$mapped = MobileApiService::mapTodayItem([
			'kind' => 'activity_overdue',
			'companyId' => 3,
			'companyName' => 'ACME',
			'activityId' => 9,
			'label' => 'Call back',
			'dueDate' => '2026-07-01',
		]);

		$this->assertSame('activity', $mapped['kind']);
		$this->assertSame('danger', $mapped['urgency']);
		$this->assertSame('act:9', $mapped['id']);
	}

	public function testMapsDueTodayActivityToInfo(): void
	{
		$mapped = MobileApiService::mapTodayItem([
			'kind' => 'activity_due',
			'companyId' => 3,
			'companyName' => 'ACME',
			'activityId' => 9,
			'label' => 'Call back',
			'dueDate' => '2026-07-26',
		]);

		$this->assertSame('activity', $mapped['kind']);
		$this->assertSame('info', $mapped['urgency']);
	}

	public function testMapsStaleDeal(): void
	{
		$mapped = MobileApiService::mapTodayItem([
			'kind' => 'deal_stale',
			'companyId' => 4,
			'companyName' => 'ACME',
			'dealId' => 77,
			'label' => 'Big deal',
		]);

		$this->assertSame('deal_stale', $mapped['kind']);
		$this->assertSame('info', $mapped['urgency']);
		$this->assertSame('deal:77', $mapped['id']);
		$this->assertSame(['type' => 'deal', 'id' => 77], $mapped['deepLink']);
	}

	// ── normaliseActivityBody ───────────────────────────────────────────

	public function testNormaliseMapsCompanionAliases(): void
	{
		$body = MobileApiService::normaliseActivityBody([
			'summary' => 'Call Bob',
			'dueOn' => '2026-08-01',
			'companyId' => 3,
		]);

		$this->assertSame('Call Bob', $body['title']);
		$this->assertSame('2026-08-01', $body['dueDate']);
		$this->assertSame(3, $body['companyId']);
		$this->assertArrayNotHasKey('summary', $body);
		$this->assertArrayNotHasKey('dueOn', $body);
	}

	public function testNormaliseKeepsCanonicalFieldsWhenBothPresent(): void
	{
		$body = MobileApiService::normaliseActivityBody([
			'title' => 'Canonical',
			'summary' => 'Alias',
			'dueDate' => '2026-08-02',
			'dueOn' => '2026-08-01',
		]);

		$this->assertSame('Canonical', $body['title']);
		$this->assertSame('2026-08-02', $body['dueDate']);
	}

	public function testNormaliseLeavesPlainBodyUntouched(): void
	{
		$this->assertSame(
			['title' => 'X', 'companyId' => 1],
			MobileApiService::normaliseActivityBody(['title' => 'X', 'companyId' => 1]),
		);
	}

	// ── serialisers ─────────────────────────────────────────────────────

	public function testSerialiseActivity(): void
	{
		$a = new Activity();
		$a->setId(9);
		$a->setCompanyId(3);
		$a->setDealId(4);
		$a->setAgreementId(null);
		$a->setType('call');
		$a->setTitle('Call back');
		$a->setDetails('after lunch');
		$a->setDueDate(new \DateTime('2026-08-01'));
		$a->setDoneAt(null);
		$a->setOrigin('user');
		$a->setUpdatedAt(new \DateTime('2026-07-26 10:00:00'));

		$row = MobileApiService::serialiseActivity($a);

		$this->assertSame(9, $row['id']);
		$this->assertSame(3, $row['companyId']);
		$this->assertSame(4, $row['dealId']);
		$this->assertNull($row['agreementId']);
		$this->assertSame('call', $row['type']);
		$this->assertSame('Call back', $row['title']);
		$this->assertSame('after lunch', $row['details']);
		$this->assertSame('2026-08-01', $row['dueDate']);
		$this->assertNull($row['doneAt']);
		$this->assertSame('user', $row['origin']);
		$this->assertNotNull($row['updatedAt']);
	}

	public function testSerialiseAgreement(): void
	{
		$a = new Agreement();
		$a->setId(7);
		$a->setCompanyId(3);
		$a->setKind('care');
		$a->setProductCode('CARE-12');
		$a->setLabel('Care contract');
		$a->setLimitsJson('{"hours": 10}');
		$a->setStartsAt(new \DateTime('2026-01-01'));
		$a->setValidUntil(new \DateTime('2026-12-31'));
		$a->setAmountCents(120000);
		$a->setInvoiceStatus('paid');
		$a->setStatus('active');
		$a->setUpdatedAt(new \DateTime('2026-07-26 10:00:00'));

		$row = MobileApiService::serialiseAgreement($a);

		$this->assertSame(7, $row['id']);
		$this->assertSame('care', $row['kind']);
		$this->assertSame('CARE-12', $row['productCode']);
		$this->assertSame(['hours' => 10], $row['limits']);
		$this->assertSame('2026-01-01', $row['startsAt']);
		$this->assertSame('2026-12-31', $row['validUntil']);
		$this->assertSame(120000, $row['amountCents']);
		$this->assertSame('paid', $row['invoiceStatus']);
		$this->assertSame('active', $row['status']);
	}

	public function testSerialiseDealAppliesStaleCallback(): void
	{
		$deal = new Deal();
		$deal->setId(77);
		$deal->setTitle('Big deal');
		$deal->setStage('offer');
		$deal->setCompanyId(4);
		$deal->setValueCents(500000);
		$deal->setProducts('CARE-12');
		$deal->setExpectedClose(new \DateTime('2026-10-01'));
		$deal->setStageChangedAt(new \DateTime('2026-06-01 09:00:00'));
		$deal->setLostReason(null);
		$deal->setUpdatedAt(new \DateTime('2026-07-26 10:00:00'));

		$row = MobileApiService::serialiseDeal($deal, static fn (Deal $d): bool => $d->getId() === 77);

		$this->assertSame(77, $row['id']);
		$this->assertSame('offer', $row['stage']);
		$this->assertSame(500000, $row['valueCents']);
		$this->assertSame('CARE-12', $row['products']);
		$this->assertSame('2026-10-01', $row['expectedClose']);
		$this->assertTrue($row['stale']);
		$this->assertNull($row['lostReason']);
	}

	public function testSerialiseCompanyRow(): void
	{
		$company = new Company();
		$company->setId(12);
		$company->setName('ACME GmbH');
		$company->setSlug('acme-gmbh');
		$company->setStatus('customer');
		$company->setArchivedAt(null);

		$this->assertSame([
			'id' => 12,
			'name' => 'ACME GmbH',
			'slug' => 'acme-gmbh',
			'status' => 'customer',
			'archived' => false,
		], MobileApiService::serialiseCompanyRow($company));
	}
}
