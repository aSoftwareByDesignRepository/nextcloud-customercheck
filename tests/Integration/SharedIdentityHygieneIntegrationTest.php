<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCA\CustomerCheck\Service\SiblingCustomerWriteService;
use OCA\CustomerCheck\Support\OptimisticLock;
use OCA\ProjectCheck\Service\CustomerService as PcCustomerService;
use PHPUnit\Framework\TestCase;

/**
 * SHARED-IDENTITY W1 — rename push, broken-link, idempotency, health (AC-C-03/05/13/14).
 */
final class SharedIdentityHygieneIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	/** @var list<array{id:int,slug:string}> */
	private array $cleanupCompanies = [];

	/** @var list<int> */
	private array $cleanupPcCustomers = [];

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
		$companies = \OC::$server->get(CompanyService::class);
		foreach (array_reverse($this->cleanupCompanies) as $row) {
			try {
				$companies->gdprErase($row['id'], $row['slug'], self::ACTOR);
			} catch (\Throwable) {
			}
		}
		$this->cleanupCompanies = [];
		if (class_exists(PcCustomerService::class)) {
			try {
				$pc = \OC::$server->get(PcCustomerService::class);
				foreach ($this->cleanupPcCustomers as $pcId) {
					try {
						if (method_exists($pc, 'deleteCustomer')) {
							$pc->deleteCustomer($pcId);
						}
					} catch (\Throwable) {
					}
				}
			} catch (\Throwable) {
			}
		}
		$this->cleanupPcCustomers = [];
	}

	public function testRenameLinkedCompanyPushesPcDisplayName(): void
	{
		$writes = \OC::$server->get(SiblingCustomerWriteService::class);
		if (!$writes->projectWriteAvailable()) {
			$this->markTestSkipped('ProjectCheck write facade unavailable');
		}
		$companies = \OC::$server->get(CompanyService::class);
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$company = $companies->create([
			'name' => 'Rename Src ' . $suffix,
			'slug' => 'rename-' . $suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
		], self::ACTOR);
		$this->cleanupCompanies[] = ['id' => (int)$company->getId(), 'slug' => (string)$company->getSlug()];

		$linked = $writes->createOrLinkPcCustomer(
			(int)$company->getId(),
			self::ACTOR,
			null,
			OptimisticLock::format($company->getUpdatedAt()),
			'idem-rename-' . $suffix,
		);
		$this->cleanupPcCustomers[] = (int)$linked['pcCustomerId'];
		$company = $linked['company'];

		$newName = 'Rename Dst ' . $suffix;
		$updated = $companies->update(
			(int)$company->getId(),
			['name' => $newName],
			self::ACTOR,
			OptimisticLock::format($company->getUpdatedAt()),
		);
		$push = $writes->pushDisplayNameAfterRename($updated, self::ACTOR, 'Rename Src ' . $suffix);
		if ($push['skipped'] && $push['warning'] === null) {
			// Flag off — valid AC-C-18 outcome; PC name intentionally unchanged.
			$this->assertFalse($push['pushed']);
			return;
		}
		$this->assertTrue($push['pushed'], 'expected rename push to succeed: ' . (string)($push['warning'] ?? ''));

		$pc = \OC::$server->get(PcCustomerService::class);
		$pcCustomer = $pc->getCustomer((int)$linked['pcCustomerId']);
		$this->assertNotNull($pcCustomer);
		$this->assertSame($newName, (string)$pcCustomer->getName());
	}

	public function testBrokenLinkDetectedAfterPcDeleteAndUnlinkClears(): void
	{
		$writes = \OC::$server->get(SiblingCustomerWriteService::class);
		if (!$writes->projectWriteAvailable()) {
			$this->markTestSkipped('ProjectCheck write facade unavailable');
		}
		$companies = \OC::$server->get(CompanyService::class);
		$health = \OC::$server->get(IdentityLinkHealthService::class);
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$company = $companies->create([
			'name' => 'Broken Src ' . $suffix,
			'slug' => 'broken-' . $suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
		], self::ACTOR);
		$this->cleanupCompanies[] = ['id' => (int)$company->getId(), 'slug' => (string)$company->getSlug()];

		$linked = $writes->createOrLinkPcCustomer(
			(int)$company->getId(),
			self::ACTOR,
			null,
			OptimisticLock::format($company->getUpdatedAt()),
			'idem-broken-' . $suffix,
		);
		$pcId = (int)$linked['pcCustomerId'];
		$company = $linked['company'];

		$pc = \OC::$server->get(PcCustomerService::class);
		if (!method_exists($pc, 'deleteCustomer')) {
			$this->markTestSkipped('PC deleteCustomer unavailable');
		}
		$pc->deleteCustomer($pcId);

		$reloaded = $companies->get((int)$company->getId());
		$state = $health->classifyCompany($reloaded);
		$this->assertSame(IdentityLinkHealthService::STATE_BROKEN, $state['pc']['state']);

		$cleared = $writes->unlinkPcCustomer(
			(int)$company->getId(),
			self::ACTOR,
			OptimisticLock::format($reloaded->getUpdatedAt()),
		);
		$this->assertNull($cleared->getPcCustomerId());
		$after = $health->classifyCompany($cleared);
		$this->assertSame(IdentityLinkHealthService::STATE_NOT_LINKED, $after['pc']['state']);
	}

	public function testIdempotentCreateOrLinkReplaySameKey(): void
	{
		$writes = \OC::$server->get(SiblingCustomerWriteService::class);
		if (!$writes->projectWriteAvailable()) {
			$this->markTestSkipped('ProjectCheck write facade unavailable');
		}
		$companies = \OC::$server->get(CompanyService::class);
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$key = 'idem-key-' . $suffix;
		$company = $companies->create([
			'name' => 'Idem Src ' . $suffix,
			'slug' => 'idem-' . $suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
		], self::ACTOR);
		$this->cleanupCompanies[] = ['id' => (int)$company->getId(), 'slug' => (string)$company->getSlug()];

		$lock = OptimisticLock::format($company->getUpdatedAt());
		$first = $writes->createOrLinkPcCustomer((int)$company->getId(), self::ACTOR, null, $lock, $key);
		$this->cleanupPcCustomers[] = (int)$first['pcCustomerId'];
		$second = $writes->createOrLinkPcCustomer((int)$company->getId(), self::ACTOR, null, $lock, $key);
		$this->assertSame($first['pcCustomerId'], $second['pcCustomerId']);
		$this->assertTrue(!empty($second['idempotentReplay']) || $second['created'] === false);
	}

	public function testIdentityHealthReturnsIntegerCountsForAdmin(): void
	{
		$access = \OC::$server->get(AccessControlService::class);
		$uid = self::ACTOR;
		if (!$access->canManageAppConfiguration($uid)) {
			$this->markTestSkipped('Actor is not app admin');
		}
		$health = \OC::$server->get(IdentityLinkHealthService::class);
		$counts = $health->adminHealthCounts();
		foreach (['pcLinked', 'pcUnlinked', 'pcBroken', 'tcLinked', 'tcUnlinked', 'tcBroken', 'mnLinkedPc', 'mnLinkedCrm', 'mnUnlinked'] as $key) {
			$this->assertArrayHasKey($key, $counts);
			$this->assertIsInt($counts[$key]);
			$this->assertGreaterThanOrEqual(0, $counts[$key]);
		}
	}
}
