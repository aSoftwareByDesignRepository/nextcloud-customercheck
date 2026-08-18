<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\PersonMapper;
use OCA\CustomerCheck\Service\ImportService;
use Test\TestCase;

/**
 * Website-order JSON paste must expand agreements and create one company + N licenses.
 */
class ImportWebsiteOrderIntegrationTest extends TestCase
{
	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
		\OC_App::loadApp('customercheck');
	}

	public function testWebsiteOrderPasteCreatesOneCompanyAndTwoAgreements(): void
	{
		$suffix = bin2hex(random_bytes(4));
		$companyName = 'Website Order Integ ' . $suffix;
		$email = 'wo-' . $suffix . '@example.test';
		$json = json_encode([
			'orderId' => 'order-' . $suffix,
			'source' => 'website-order',
			'lang' => 'de',
			'companyName' => $companyName,
			'contactName' => 'Contact ' . $suffix,
			'email' => $email,
			'address' => $companyName . "\nHoltenauer Straße 267\n24106 Kiel",
			'startsAt' => '2026-08-01',
			'validUntil' => '2027-08-01',
			'totalCents' => 4500,
			'agreements' => [
				[
					'productCode' => 'azc',
					'label' => 'ArbeitszeitCheck',
					'seats' => 5,
					'devices' => 0,
					'amountCents' => 2000,
					'startsAt' => '2026-08-01',
					'validUntil' => '2027-08-01',
				],
				[
					'productCode' => 'pc',
					'label' => 'ProjectCheck',
					'seats' => 5,
					'devices' => 0,
					'amountCents' => 2500,
					'startsAt' => '2026-08-01',
					'validUntil' => '2027-08-01',
				],
			],
		], JSON_THROW_ON_ERROR);

		/** @var ImportService $imports */
		$imports = \OC::$server->get(ImportService::class);
		$parsed = $imports->parseTextPayload($json);
		$this->assertSame([], $parsed['errors']);
		$this->assertCount(2, $parsed['rows']);
		$this->assertSame('azc', $parsed['rows'][0]['productCode']);
		$this->assertSame(5, $parsed['rows'][0]['mobileSeats']);

		$scan = $imports->scan([
			'source' => 'csv',
			'csv' => $json,
			'workspaceId' => 1,
		]);
		$this->assertSame(0, $scan['meta']['rowErrors']);
		$this->assertSame(2, $scan['meta']['total']);
		$this->assertSame(2, $scan['meta']['create']);

		$decisions = [];
		foreach ($scan['proposals'] as $proposal) {
			$decisions[] = ['index' => (int)$proposal['index'], 'action' => 'create'];
		}
		$apply = $imports->apply([
			'source' => 'csv',
			'csv' => $json,
			'workspaceId' => 1,
			'decisions' => $decisions,
		], 'admin');

		$this->assertSame([], $apply['errors'], json_encode($apply));
		$this->assertSame(1, $apply['created']);
		$this->assertSame(2, $apply['agreements']);

		/** @var CompanyMapper $companies */
		$companies = \OC::$server->get(CompanyMapper::class);
		$matches = $companies->findByNameCaseInsensitive($companyName, 1);
		$this->assertNotEmpty($matches);
		$company = $matches[0];
		$companyId = (int)$company->getId();
		$this->assertSame(1, (int)($company->getWorkspaceId() ?? 0));
		$this->assertSame('Holtenauer Straße 267', (string)$company->getAddrStreet());
		$this->assertSame('24106', (string)$company->getAddrZip());
		$this->assertSame('Kiel', (string)$company->getAddrCity());

		/** @var AgreementMapper $agreements */
		$agreements = \OC::$server->get(AgreementMapper::class);
		$azc = $agreements->findActiveByCompanyProduct($companyId, 'azc');
		$pc = $agreements->findActiveByCompanyProduct($companyId, 'pc');
		$this->assertNotNull($azc);
		$this->assertNotNull($pc);
		$this->assertSame(2000, (int)$azc->getAmountCents());
		$this->assertSame(2500, (int)$pc->getAmountCents());

		/** @var PersonMapper $persons */
		$persons = \OC::$server->get(PersonMapper::class);
		$people = $persons->findByEmail($email, 1);
		$this->assertNotEmpty($people);
		$this->assertSame($companyId, (int)$people[0]->getCompanyId());
	}

	public function testGdprMultiAgreementApplyDoesNotSlugTaken(): void
	{
		$suffix = bin2hex(random_bytes(4));
		$slug = 'gdpr-multi-' . $suffix;
		$json = json_encode([
			'company' => [
				'slug' => $slug,
				'name' => 'GDPR Multi ' . $suffix,
				'country' => 'DE',
			],
			'persons' => [
				['name' => 'Ada', 'email' => 'ada-' . $suffix . '@example.test', 'isPrimary' => true],
			],
			'agreements' => [
				[
					'productCode' => 'azc',
					'label' => 'AZC',
					'validUntil' => '2027-08-01',
					'startsAt' => '2026-08-01',
					'amountCents' => 1000,
					'limits' => ['mobileSeats' => 3, 'terminalDevices' => 0],
				],
				[
					'productCode' => 'pc',
					'label' => 'PC',
					'validUntil' => '2027-08-01',
					'startsAt' => '2026-08-01',
					'amountCents' => 1500,
					'limits' => ['mobileSeats' => 3, 'terminalDevices' => 0],
				],
			],
		], JSON_THROW_ON_ERROR);

		/** @var ImportService $imports */
		$imports = \OC::$server->get(ImportService::class);
		$scan = $imports->scan(['source' => 'csv', 'csv' => $json, 'workspaceId' => 1]);
		$decisions = array_map(
			static fn (array $p): array => ['index' => (int)$p['index'], 'action' => 'create'],
			$scan['proposals'],
		);
		$apply = $imports->apply([
			'source' => 'csv',
			'csv' => $json,
			'workspaceId' => 1,
			'decisions' => $decisions,
		], 'admin');

		$this->assertSame([], $apply['errors'], json_encode($apply));
		$this->assertSame(1, $apply['created']);
		$this->assertSame(2, $apply['agreements']);
	}
}
