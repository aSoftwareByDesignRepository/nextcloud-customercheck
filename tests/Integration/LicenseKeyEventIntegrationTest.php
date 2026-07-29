<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Db\AgreementMapper;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Db\ImportJobMapper;
use OCA\CustomerCheck\Service\LicenseKeyEventService;
use OCP\AppFramework\App;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * S7 — LicenseKeyGeneratedEvent effects: draft on match, inbox on unknown slug.
 */
class LicenseKeyEventIntegrationTest extends TestCase
{
	private ?IDBConnection $db = null;
	private LicenseKeyEventService $service;
	private CompanyMapper $companies;
	private AgreementMapper $agreements;
	private ImportJobMapper $importJobs;
	/** @var list<int> */
	private array $companyIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$app = new App('customercheck');
		$c = $app->getContainer();
		$this->db = $c->get(IDBConnection::class);
		$this->service = $c->get(LicenseKeyEventService::class);
		$this->companies = $c->get(CompanyMapper::class);
		$this->agreements = $c->get(AgreementMapper::class);
		$this->importJobs = $c->get(ImportJobMapper::class);
	}

	protected function tearDown(): void
	{
		if ($this->db === null) {
			return;
		}
		foreach ($this->companyIds as $id) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('crm_agreement')->where($qb->expr()->eq('company_id', $qb->createNamedParameter($id)))->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('crm_event')->where($qb->expr()->eq('company_id', $qb->createNamedParameter($id)))->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('crm_company')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
			} catch (\Throwable) {
			}
		}
		parent::tearDown();
	}

	public function testUnknownSlugWritesImportInboxOnly(): void
	{
		$result = $this->service->handle([
			'salesLogId' => 900001,
			'customerId' => 'no-such-customer-' . bin2hex(random_bytes(3)),
			'product' => 'arbeitszeitcheck',
			'fingerprint' => 'AZC2.test',
			'validUntil' => '2027-07-25',
			'limits' => ['mobileSeats' => 2, 'terminalDevices' => 1],
			'generatedBy' => 'integration',
		]);
		$this->assertSame('inbox', $result['action']);
		$this->assertArrayHasKey('importJobId', $result);
		$importJobId = (int)$result['importJobId'];
		$this->assertGreaterThan(0, $importJobId);
		// Prefer id lookup over findRecent(N) length deltas — shared DBs can already
		// have ≥N inbox rows, so a capped list length stays flat after a successful insert.
		$matched = $this->importJobs->findById($importJobId);
		$this->assertNotNull($matched, 'Inbox row must be readable after orphan license event');
		$this->assertSame('license_event_orphan', (string)$matched->getSource());
		$this->assertNull($this->agreements->findBySalesLogId(900001));
	}

	public function testKnownSlugCreatesDraftAgreement(): void
	{
		$slug = 'crm-evt-' . bin2hex(random_bytes(3));
		$now = (new App('customercheck'))->getContainer()->get(ITimeFactory::class)->getDateTime();
		$company = new Company();
		$company->setSlug($slug);
		$company->setName('Event Co');
		$company->setCountry('DE');
		$company->setLang('de');
		$company->setStatus('active');
		$company->setCreatedBy('integration');
		$company->setCreatedAt($now);
		$company->setUpdatedAt($now);
		$company = $this->companies->insert($company);
		$this->companyIds[] = (int)$company->getId();

		$salesLogId = 900002;
		$result = $this->service->handle([
			'salesLogId' => $salesLogId,
			'customerId' => $slug,
			'companyName' => 'Event Co',
			'product' => 'arbeitszeitcheck',
			'fingerprint' => 'AZC2.evt',
			'validUntil' => '2027-07-25',
			'limits' => ['mobileSeats' => 3, 'terminalDevices' => 1],
			'amountEurCents' => 9900,
			'generatedBy' => 'integration',
		]);
		$this->assertSame('draft', $result['action']);
		$agreement = $this->agreements->findBySalesLogId($salesLogId);
		$this->assertNotNull($agreement);
		$this->assertSame('draft', (string)$agreement->getStatus());
		$this->assertSame('azc', (string)$agreement->getProductCode());

		$again = $this->service->handle([
			'salesLogId' => $salesLogId,
			'customerId' => $slug,
			'product' => 'arbeitszeitcheck',
			'fingerprint' => 'AZC2.evt',
			'validUntil' => '2027-07-25',
			'limits' => ['mobileSeats' => 3, 'terminalDevices' => 1],
		]);
		$this->assertSame('skip', $again['action']);
	}
}
