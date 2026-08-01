<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Controller;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Controller\MobileController;
use OCA\CustomerCheck\Db\Company;
use OCA\CustomerCheck\Db\Deal;
use OCA\CustomerCheck\Db\Workspace;
use OCA\CustomerCheck\Exception\NotFoundException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\MobileApiService;
use OCA\CustomerCheck\Service\MobileGateService;
use OCA\CustomerCheck\Service\MobileIdempotencyService;
use OCA\CustomerCheck\Service\MobilePushService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Companion MobileController workspace ACL contract (product §3.3 / §21.1).
 *
 * Foreign workspace / object → HTTP 404 (no existence leak). Mutations reject
 * before idempotency or stage change when the company/deal is out of book.
 */
final class MobileWorkspaceAclContractTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var IUserSession&MockObject */
	private IUserSession $userSession;
	/** @var AccessControlService&MockObject */
	private AccessControlService $accessControl;
	/** @var WorkspaceAccessService&MockObject */
	private WorkspaceAccessService $workspaceAccess;
	/** @var MobileGateService&MockObject */
	private MobileGateService $gate;
	/** @var MobileApiService&MockObject */
	private MobileApiService $mobile;
	/** @var CompanyService&MockObject */
	private CompanyService $companies;
	/** @var ActivityService&MockObject */
	private ActivityService $activities;
	/** @var DealService&MockObject */
	private DealService $deals;
	/** @var AgreementService&MockObject */
	private AgreementService $agreements;
	/** @var MobileIdempotencyService&MockObject */
	private MobileIdempotencyService $idempotency;
	/** @var MobilePushService&MockObject */
	private MobilePushService $push;
	/** @var IAppManager&MockObject */
	private IAppManager $appManager;

	/** @var array<string, mixed> */
	private array $requestParams = [];

	protected function setUp(): void
	{
		$this->requestParams = [];
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->accessControl = $this->createMock(AccessControlService::class);
		$this->workspaceAccess = $this->createMock(WorkspaceAccessService::class);
		$this->gate = $this->createMock(MobileGateService::class);
		$this->mobile = $this->createMock(MobileApiService::class);
		$this->companies = $this->createMock(CompanyService::class);
		$this->activities = $this->createMock(ActivityService::class);
		$this->deals = $this->createMock(DealService::class);
		$this->agreements = $this->createMock(AgreementService::class);
		$this->idempotency = $this->createMock(MobileIdempotencyService::class);
		$this->push = $this->createMock(MobilePushService::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->accessControl->method('requireCanUseApp')->willReturn('alice');
		$this->gate->method('assertGatePassed');
		$this->request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if (strcasecmp($name, 'Authorization') === 0) {
					return 'Basic dGVzdDp0ZXN0';
				}

				return '';
			},
		);
		$this->request->method('passesCSRFCheck')->willReturn(false);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->requestParams);
	}

	private function controller(): MobileController
	{
		return new MobileController(
			Application::APP_ID,
			$this->request,
			$this->userSession,
			$this->accessControl,
			$this->workspaceAccess,
			$this->gate,
			$this->mobile,
			$this->companies,
			$this->activities,
			$this->deals,
			$this->agreements,
			$this->idempotency,
			$this->push,
			$this->appManager,
		);
	}

	/**
	 * @return array{workspace: Workspace, role: string, isAppAdmin: bool, member: null, needsPicker: bool}
	 */
	private function workspaceContext(int $id): array
	{
		$ws = new Workspace();
		$ws->setId($id);

		return [
			'workspace' => $ws,
			'role' => 'member',
			'isAppAdmin' => false,
			'member' => null,
			'needsPicker' => false,
		];
	}

	private function allowWorkspace(int $workspaceId, string $capability = 'read'): void
	{
		$this->workspaceAccess->method('assertCapability')
			->with('alice', $workspaceId, $capability);
		$this->workspaceAccess->method('resolveForUser')
			->with('alice', $workspaceId)
			->willReturn($this->workspaceContext($workspaceId));
	}

	public function testForeignWorkspaceIdReturns404(): void
	{
		$this->workspaceAccess->method('assertCapability')
			->with('alice', 99, 'read')
			->willThrowException(new NotFoundException('Workspace not found.'));
		$this->mobile->expects($this->never())->method('today');

		$response = $this->controller()->today(99);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$body = $response->getData();
		$this->assertFalse($body['success']);
		$this->assertSame(Constants::ERR_NOT_FOUND, $body['error']);
	}

	public function testCompanyDetailForeignCompanyReturns404(): void
	{
		$this->allowWorkspace(5);
		$company = new Company();
		$company->setId(10);
		$company->setWorkspaceId(7);
		$this->companies->method('get')->with(10)->willReturn($company);
		$this->workspaceAccess->expects($this->once())
			->method('assertCompanyInWorkspace')
			->with(7, 5)
			->willThrowException(new NotFoundException('Company not found.'));
		$this->mobile->expects($this->never())->method('companyLite');

		$response = $this->controller()->companyDetail(5, 10);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$body = $response->getData();
		$this->assertFalse($body['success']);
		$this->assertSame(Constants::ERR_NOT_FOUND, $body['error']);
	}

	public function testCompaniesSearchPassesWorkspaceIdFilter(): void
	{
		$this->allowWorkspace(12);
		$this->companies->expects($this->once())
			->method('search')
			->with(
				$this->callback(static function (array $filters): bool {
					return ($filters['workspaceId'] ?? null) === 12
						&& ($filters['archived'] ?? null) === false
						&& ($filters['search'] ?? null) === 'acme';
				}),
				25,
				0,
			)
			->willReturn([]);
		$this->companies->expects($this->once())
			->method('count')
			->with($this->callback(static fn (array $f): bool => ($f['workspaceId'] ?? null) === 12))
			->willReturn(0);

		$response = $this->controller()->companies(12, 'acme', 25, 0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testCreateActivityRejectsForeignCompanyBeforeIdempotency(): void
	{
		$this->allowWorkspace(5, 'write');
		$this->requestParams = [
			'companyId' => 42,
			'title' => 'Call',
			'type' => 'call',
		];

		$company = new Company();
		$company->setId(42);
		$company->setWorkspaceId(9);
		$this->companies->method('get')->with(42)->willReturn($company);
		$this->workspaceAccess->expects($this->once())
			->method('assertCompanyInWorkspace')
			->with(9, 5)
			->willThrowException(new NotFoundException('Company not found.'));
		$this->idempotency->expects($this->never())->method('createActivity');

		$response = $this->controller()->createActivity(5);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(Constants::ERR_NOT_FOUND, $response->getData()['error']);
	}

	public function testDealStageRejectsForeignDeal(): void
	{
		$this->allowWorkspace(5, 'write');
		$this->requestParams = ['stage' => 'won'];

		$deal = new Deal();
		$deal->setId(77);
		$deal->setCompanyId(42);
		$this->deals->method('get')->with(77)->willReturn($deal);

		$company = new Company();
		$company->setId(42);
		$company->setWorkspaceId(9);
		$this->companies->method('get')->with(42)->willReturn($company);
		$this->workspaceAccess->expects($this->once())
			->method('assertCompanyInWorkspace')
			->with(9, 5)
			->willThrowException(new NotFoundException('Company not found.'));
		$this->deals->expects($this->never())->method('changeStage');

		$response = $this->controller()->dealStage(5, 77);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(Constants::ERR_NOT_FOUND, $response->getData()['error']);
	}

	public function testWorkspacesReturnsListForMobileWithoutRemember(): void
	{
		$payload = [
			'workspaces' => [
				[
					'id' => 3,
					'name' => 'Org',
					'slug' => 'org',
					'archived' => false,
					'isDefault' => true,
					'role' => 'member',
				],
			],
			'lastUsedWorkspaceId' => 3,
		];
		$this->workspaceAccess->expects($this->once())
			->method('listForMobile')
			->with('alice')
			->willReturn($payload);
		// remember() lives inside WorkspaceAccessService::resolveForUser — listing
		// must never go through that path from the controller.
		$this->workspaceAccess->expects($this->never())->method('resolveForUser');
		$this->workspaceAccess->expects($this->never())->method('assertCapability');

		$response = $this->controller()->workspaces();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$body = $response->getData();
		$this->assertTrue($body['success']);
		$this->assertSame($payload, $body['data']);
	}

	public function testWorkspaceIdZeroReturns404WithoutTouchingServices(): void
	{
		$this->mobile->expects($this->never())->method('today');
		$this->workspaceAccess->expects($this->never())->method('assertCapability');

		$response = $this->controller()->today(0);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(Constants::ERR_NOT_FOUND, $response->getData()['error']);
	}

	public function testAgreementsSearchPassesWorkspaceIdFilter(): void
	{
		$this->allowWorkspace(8);
		$this->agreements->expects($this->once())
			->method('search')
			->with(
				$this->callback(static function (array $filters): bool {
					return ($filters['workspaceId'] ?? null) === 8
						&& ($filters['companyId'] ?? null) === 0;
				}),
				200,
			)
			->willReturn([]);

		$response = $this->controller()->agreements(8, 60, 0, 200);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testActivitiesListPassesWorkspaceIdAndCompanyGate(): void
	{
		$this->allowWorkspace(4);
		$company = new Company();
		$company->setId(15);
		$company->setWorkspaceId(4);
		$this->companies->method('get')->with(15)->willReturn($company);
		$this->workspaceAccess->expects($this->once())
			->method('assertCompanyInWorkspace')
			->with(4, 4);
		$this->activities->expects($this->once())
			->method('searchForMobile')
			->with(
				'alice',
				$this->callback(static function (array $filters): bool {
					return ($filters['workspaceId'] ?? null) === 4
						&& ($filters['companyId'] ?? null) === 15
						&& ($filters['openOnly'] ?? null) === true;
				}),
				100,
			)
			->willReturn([]);

		$response = $this->controller()->activities(4, 1, 15, 100);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testViewerWriteDeniedBeforeIdempotency(): void
	{
		$this->workspaceAccess->method('assertCapability')
			->with('alice', 5, 'write')
			->willThrowException(
				\OCA\CustomerCheck\Exception\ValidationException::field(
					Constants::ERR_WS_ROLE,
					'capability',
					'denied',
				),
			);
		$this->idempotency->expects($this->never())->method('createActivity');
		$this->requestParams = [
			'companyId' => 1,
			'title' => 'Nope',
			'type' => 'call',
		];

		$response = $this->controller()->createActivity(5);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$body = $response->getData();
		$this->assertSame('VALIDATION', $body['error']);
		$this->assertSame(Constants::ERR_WS_ROLE, $body['detail'] ?? null);
	}
}
