<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\SiblingCustomerWriteService;
use OCA\CustomerCheck\Support\OptimisticLock;
use PHPUnit\Framework\TestCase;

/**
 * WP-S1-CUC-TCLINK — create-or-link must set tc_customer_id when TC write is available.
 */
final class TcLinkIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	/** @var list<array{id: int, slug: string}> */
	private array $cleanup = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
	}

	protected function tearDown(): void
	{
		if ($this->cleanup === [] || !isset(\OC::$server)) {
			return;
		}
		$companies = \OC::$server->get(CompanyService::class);
		foreach (array_reverse($this->cleanup) as $row) {
			try {
				$companies->gdprErase($row['id'], $row['slug'], self::ACTOR);
			} catch (\Throwable) {
			}
		}
		$this->cleanup = [];
	}

	public function testCreateTcCustomerSetsLinkOnCompany(): void
	{
		/** @var SiblingCustomerWriteService $writes */
		$writes = \OC::$server->get(SiblingCustomerWriteService::class);
		if (!$writes->ticketWriteAvailable()) {
			$this->markTestSkipped('TicketCheck write facade unavailable');
		}

		/** @var CompanyService $companies */
		$companies = \OC::$server->get(CompanyService::class);
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$company = $companies->create([
			'name' => 'TC Link ' . $suffix,
			'slug' => 'tclink-' . $suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
		], self::ACTOR);
		$this->cleanup[] = ['id' => (int)$company->getId(), 'slug' => (string)$company->getSlug()];

		$result = $writes->createOrLinkTcCustomer(
			(int)$company->getId(),
			self::ACTOR,
			null,
			OptimisticLock::format($company->getUpdatedAt()),
		);

		$this->assertGreaterThan(0, $result['tcCustomerId']);
		$this->assertTrue($result['created']);
		$reloaded = $companies->get((int)$company->getId());
		$this->assertSame($result['tcCustomerId'], (int)$reloaded->getTcCustomerId());
	}
}
