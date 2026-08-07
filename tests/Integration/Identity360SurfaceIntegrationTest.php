<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\IdentityLinkHealthService;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * SHARED-IDENTITY AC-C-04 — identity classification on real company (360 payload source).
 */
final class Identity360SurfaceIntegrationTest extends TestCase
{
	private const ACTOR = 'admin';

	/** @var list<array{id:int,slug:string}> */
	private array $cleanup = [];

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
		try {
			\OC::$server->get(IUserSession::class)->setUser(null);
		} catch (\Throwable) {
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

	public function testClassifyCompanyProvides360IdentityStates(): void
	{
		$user = \OC::$server->get(IUserManager::class)->get(self::ACTOR);
		if ($user === null) {
			$this->markTestSkipped('admin user missing');
		}
		\OC::$server->get(IUserSession::class)->setUser($user);

		$companies = \OC::$server->get(CompanyService::class);
		$health = \OC::$server->get(IdentityLinkHealthService::class);
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$company = $companies->create([
			'name' => 'Identity Surface ' . $suffix,
			'slug' => 'id-surf-' . $suffix,
			'lang' => 'en',
			'country' => 'DE',
			'status' => 'active',
		], self::ACTOR);
		$this->cleanup[] = ['id' => (int)$company->getId(), 'slug' => (string)$company->getSlug()];

		$identity = $health->classifyCompany($company);
		$this->assertArrayHasKey('pc', $identity);
		$this->assertArrayHasKey('tc', $identity);
		$this->assertContains(
			$identity['pc']['state'],
			[
				IdentityLinkHealthService::STATE_NOT_LINKED,
				IdentityLinkHealthService::STATE_LINKED,
				IdentityLinkHealthService::STATE_BROKEN,
				IdentityLinkHealthService::STATE_SIBLING_UNAVAILABLE,
			],
		);
		$this->assertNotSame('', (string)($identity['pc']['label'] ?? ''));
		$this->assertNotSame('', (string)($identity['tc']['label'] ?? ''));

		$pageSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PageController.php');
		$this->assertStringContainsString("'identityLinks' => \$identityLinks", $pageSrc);
		$this->assertStringContainsString('common/identity-links', $pageSrc);
	}
}
