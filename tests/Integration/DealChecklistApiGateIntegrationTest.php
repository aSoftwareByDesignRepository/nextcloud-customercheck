<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Controller\DealChecklistApiController;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCP\AppFramework\Http;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Live gate: opening a deal checklist must not 500 from missing CompanyService DI.
 *
 * Regression: WorkspaceScopedTrait::requireCompanyCapability needs $this->companies.
 */
final class DealChecklistApiGateIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	/** @var list<array{id:int,slug:string}> */
	private array $cleanupCompanies = [];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped.');
		}
		$user = \OC::$server->get(IUserManager::class)->get(self::ACTOR);
		if ($user === null) {
			$this->markTestSkipped('admin user missing');
		}
		\OC::$server->get(IUserSession::class)->setUser($user);
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
	}

	public function testIndexReturnsOkForEmptyChecklist(): void
	{
		$companies = \OC::$server->get(CompanyService::class);
		$deals = \OC::$server->get(DealService::class);
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$company = $companies->create([
			'name' => 'CL Gate ' . $suffix,
			'slug' => 'cl-gate-' . $suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
		], self::ACTOR);
		$companyId = (int)$company->getId();
		$this->cleanupCompanies[] = ['id' => $companyId, 'slug' => (string)$company->getSlug()];

		$deal = $deals->create([
			'companyId' => $companyId,
			'title' => 'Gate deal ' . $suffix,
			'stage' => 'lead',
		], self::ACTOR);
		$dealId = (int)$deal->getId();

		$ctrl = \OC::$server->get(DealChecklistApiController::class);
		$res = $ctrl->index($dealId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus(), json_encode($res->getData()));
		$payload = $res->getData();
		$this->assertTrue((bool)($payload['success'] ?? false));
		$data = $payload['data'] ?? [];
		$this->assertSame($dealId, (int)($data['dealId'] ?? 0));
		$this->assertSame($companyId, (int)($data['companyId'] ?? 0));
		$this->assertIsArray($data['items'] ?? null);
		$this->assertSame([], $data['items']);
	}

	public function testCreateThenIndexSurfacesItem(): void
	{
		$companies = \OC::$server->get(CompanyService::class);
		$deals = \OC::$server->get(DealService::class);
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$company = $companies->create([
			'name' => 'CL Create ' . $suffix,
			'slug' => 'cl-create-' . $suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
		], self::ACTOR);
		$companyId = (int)$company->getId();
		$this->cleanupCompanies[] = ['id' => $companyId, 'slug' => (string)$company->getSlug()];

		$deal = $deals->create([
			'companyId' => $companyId,
			'title' => 'Create deal ' . $suffix,
			'stage' => 'quoted',
		], self::ACTOR);
		$dealId = (int)$deal->getId();

		$ctrl = \OC::$server->get(DealChecklistApiController::class);
		$checklists = \OC::$server->get(\OCA\CustomerCheck\Service\DealChecklistService::class);
		$item = $checklists->addItem($dealId, ['title' => 'Send invoice'], self::ACTOR);
		$this->assertGreaterThan(0, (int)($item['id'] ?? 0));

		$res = $ctrl->index($dealId);
		$this->assertSame(Http::STATUS_OK, $res->getStatus(), json_encode($res->getData()));
		$items = $res->getData()['data']['items'] ?? [];
		$this->assertCount(1, $items);
		$this->assertSame('Send invoice', (string)($items[0]['title'] ?? ''));
		$this->assertSame('open', (string)($items[0]['status'] ?? ''));
	}
}
