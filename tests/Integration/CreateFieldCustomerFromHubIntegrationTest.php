<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SiblingCustomerWriteService;
use OCA\MaintenanceCheck\Db\CustomerMapper as MnCustomerMapper;
use OCA\MaintenanceCheck\Service\CustomerService as MnCustomerService;
use PHPUnit\Framework\TestCase;

/**
 * SHARED-IDENTITY AC-C-07 — create MN field customer from CRM hub with soft links.
 */
final class CreateFieldCustomerFromHubIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	/** @var list<array{id:int,slug:string}> */
	private array $cleanupCompanies = [];

	/** @var list<int> */
	private array $cleanupMn = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		if (class_exists(MnCustomerService::class)) {
			try {
				$mn = \OC::$server->get(MnCustomerService::class);
				foreach ($this->cleanupMn as $id) {
					try {
						$mn->delete($id, true);
					} catch (\Throwable) {
					}
				}
			} catch (\Throwable) {
			}
		}
		$this->cleanupMn = [];
		$companies = \OC::$server->get(CompanyService::class);
		foreach (array_reverse($this->cleanupCompanies) as $row) {
			try {
				$companies->gdprErase($row['id'], $row['slug'], self::ACTOR);
			} catch (\Throwable) {
			}
		}
		$this->cleanupCompanies = [];
	}

	public function testCreateFieldCustomerSetsCrmSoftLink(): void
	{
		$probe = \OC::$server->get(\OCA\CustomerCheck\Service\FacadeProbeService::class);
		if (!$probe->maintenancecheckIdentityAvailable()) {
			$this->markTestSkipped('MaintenanceCheck identity facade unavailable');
		}

		$writes = \OC::$server->get(SiblingCustomerWriteService::class);
		$companies = \OC::$server->get(CompanyService::class);
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$company = $companies->create([
			'name' => 'Field Hub ' . $suffix,
			'slug' => 'field-hub-' . $suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
			'addrStreet' => 'Field Str. 1',
			'addrZip' => '10115',
			'addrCity' => 'Berlin',
		], self::ACTOR);
		$this->cleanupCompanies[] = ['id' => (int)$company->getId(), 'slug' => (string)$company->getSlug()];

		$result = $writes->createFieldCustomerFromHub((int)$company->getId(), self::ACTOR);
		$this->assertGreaterThan(0, $result['mnCustomerId']);
		$this->assertTrue($result['created']);
		$this->cleanupMn[] = $result['mnCustomerId'];

		$mapper = \OC::$server->get(MnCustomerMapper::class);
		$mn = $mapper->findById($result['mnCustomerId']);
		$this->assertSame((int)$company->getId(), (int)$mn->getCrmCompanyId());
		$this->assertSame('Field Hub ' . $suffix, (string)$mn->getName());
	}
}
