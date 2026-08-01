<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Search;

use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Search\CompanySearchProvider;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\AppIconService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CompanySearchProviderTest extends TestCase
{
	private AccessControlService&MockObject $access;
	private CompanyService&MockObject $companies;
	private WorkspaceAccessService&MockObject $workspaces;
	private CompanySearchProvider $provider;

	protected function setUp(): void
	{
		parent::setUp();
		$this->access = $this->createMock(AccessControlService::class);
		$this->companies = $this->createMock(CompanyService::class);
		$this->workspaces = $this->createMock(WorkspaceAccessService::class);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/customercheck/img/app-dashboard.svg');
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.test/c/1');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.0.4');
		$this->provider = new CompanySearchProvider(
			$l10n,
			$url,
			$this->companies,
			$this->access,
			$this->workspaces,
			new AppIconService($url, $apps),
		);
	}

	public function testDeniedUserGetsZeroResults(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->access->method('canUseApp')->with('bob')->willReturn(false);
		$this->companies->expects($this->never())->method('search');
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn('acme');
		$query->method('getLimit')->willReturn(10);
		$result = $this->provider->search($user, $query);
		$this->assertSame([], $result->jsonSerialize()['entries'] ?? $this->extractEntries($result));
	}

	public function testEmptyWorkspaceMembershipGetsZeroResults(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->access->method('canUseApp')->with('alice')->willReturn(true);
		$this->workspaces->method('listAccessibleWorkspaceIds')->with('alice')->willReturn([]);
		$this->companies->expects($this->never())->method('search');
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn('acme');
		$query->method('getLimit')->willReturn(10);
		$result = $this->provider->search($user, $query);
		$this->assertSame([], $this->extractEntries($result));
	}

	public function testFindsBySlugFragmentScopedToWorkspaces(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->access->method('canUseApp')->with('alice')->willReturn(true);
		$this->workspaces->method('listAccessibleWorkspaceIds')->with('alice')->willReturn([3, 9]);
		$company = new Company();
		$company->setId(1);
		$company->setName('Acme GmbH');
		$company->setSlug('acme-gmbh');
		$this->companies->expects($this->once())->method('search')
			->with(['search' => 'acme', 'workspaceIds' => [3, 9]], 10, 0)
			->willReturn([$company]);
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn('acme');
		$query->method('getLimit')->willReturn(10);
		$result = $this->provider->search($user, $query);
		$entries = $this->extractEntries($result);
		$this->assertCount(1, $entries);
	}

	/** @return list<mixed> */
	private function extractEntries(object $result): array
	{
		if (method_exists($result, 'jsonSerialize')) {
			$data = $result->jsonSerialize();
			return is_array($data['entries'] ?? null) ? $data['entries'] : [];
		}
		$ref = new \ReflectionObject($result);
		if ($ref->hasProperty('entries')) {
			$prop = $ref->getProperty('entries');
			$prop->setAccessible(true);
			$entries = $prop->getValue($result);
			return is_array($entries) ? $entries : [];
		}
		return [];
	}
}
