<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Controller\MobileController;
use OCA\CustomerCheck\Db\LicenseStateMapper;
use OCA\CustomerCheck\Db\MobileSeatMapper;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ActivityService;
use OCA\CustomerCheck\Service\AgreementService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Service\MobileApiService;
use OCA\CustomerCheck\Service\MobileGateService;
use OCA\CustomerCheck\Service\MobileIdempotencyService;
use OCA\CustomerCheck\Service\MobilePushService;
use OCA\CustomerCheck\Tests\Support\Cuc2TestSigning;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * Companion gate ladder + data/mutation endpoints against the real container
 * (COMPANION-APP §5.3 rungs 1–6, §9.2 error contract).
 *
 * @group integration
 */
final class MobileGateHttpIntegrationTest extends TestCase
{
	private const PASS = 'Cuc-Mob-Http-9xK!';

	/** @var list<string> */
	private array $testUsers = [];

	/** @var array<string, string> */
	private array $savedConfig = [];

	private string $suffix;

	/** Per-run UIDs — fixed names collide when a prior suite leaves orphan oc_users rows. */
	private string $uid = '';

	private string $admin = '';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . Cuc2TestSigning::publicKeyB64());
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE=1');
		\OC_User::setIncognitoMode(false);
		$this->suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		// Nextcloud uid max length is tight; keep under 64 and unique per setUp.
		$this->uid = 'cuc_mu_' . $this->suffix;
		$this->admin = 'cuc_ma_' . $this->suffix;

		$config = \OC::$server->get(IConfig::class);
		foreach ([Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, Constants::CONFIG_APP_ADMIN_USER_IDS] as $key) {
			$this->savedConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}
		// Open access so plain test users pass rung 2 (canUseApp).
		$config->setAppValue(Application::APP_ID, Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, '0');

		$this->wipeLicense();
		$this->ensureUser($this->uid);
		$this->ensureUser($this->admin);
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		\OC::$server->get(IUserSession::class)->setUser(null);
		$this->wipeLicense();
		$config = \OC::$server->get(IConfig::class);
		foreach ($this->savedConfig as $key => $value) {
			if ($value === '') {
				$config->deleteAppValue(Application::APP_ID, $key);
			} else {
				$config->setAppValue(Application::APP_ID, $key, $value);
			}
		}
		$um = \OC::$server->get(IUserManager::class);
		foreach ($this->testUsers as $uid) {
			if ($um->userExists($uid)) {
				$um->get($uid)?->delete();
			}
		}
		$this->testUsers = [];
		putenv('CUC_VENDOR_PUBLIC_KEY_B64');
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE');
	}

	// ── Rung 1: authentication ──────────────────────────────────────────

	public function testBootstrapWithoutSessionReturns401(): void
	{
		\OC::$server->get(IUserSession::class)->setUser(null);
		$response = $this->controller()->bootstrap();

		self::assertSame(401, $response->getStatus());
		$body = $response->getData();
		self::assertFalse($body['success']);
		self::assertSame('AUTH_REQUIRED', $body['error']);
	}

	public function testTodayWithoutSessionReturns401(): void
	{
		\OC::$server->get(IUserSession::class)->setUser(null);
		$response = $this->controller()->today();

		self::assertSame(401, $response->getStatus());
		self::assertSame('AUTH_REQUIRED', $response->getData()['error']);
	}

	// ── Rung 2: app access ──────────────────────────────────────────────

	public function testTodayWithAccessRestrictionReturns403(): void
	{
		\OC::$server->get(IConfig::class)->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED,
			'1',
		);
		$this->loginAs($this->uid);
		$response = $this->controller()->today();

		self::assertSame(403, $response->getStatus());
		self::assertSame('ACCESS_DENIED', $response->getData()['error']);
	}

	// ── Rungs 3–6: license gate (402 ladder) ────────────────────────────

	public function testTodayWithoutLicenseReturns402LicenseRequired(): void
	{
		$this->loginAs($this->uid);
		$this->assertGate402('LICENSE_REQUIRED', $this->controller()->today());
	}

	public function testTodayWithExpiredLicenseReturns402LicenseExpired(): void
	{
		$this->applyLicense(5, '2020-01-01', '2020-12-31');
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$this->assertGate402('LICENSE_EXPIRED', $this->controller()->today());
	}

	public function testTodayWithoutSeatReturns402NoMobileSeat(): void
	{
		$this->applyLicense(5);
		$this->loginAs($this->uid);
		$this->assertGate402('NO_MOBILE_SEAT', $this->controller()->today());
	}

	public function testSeatBeyondDowngradedLimitReturns402NoMobileSeat(): void
	{
		$this->applyLicense(2);
		$this->assignSeat($this->admin);
		$this->assignSeat($this->uid);
		// Downgrade to 1 seat: UID (newer assignment) falls out of limit.
		$this->applyLicense(1);
		$this->loginAs($this->uid);
		$this->assertGate402('NO_MOBILE_SEAT', $this->controller()->today());
	}

	// ── Bootstrap payload (never license-gated) ─────────────────────────

	public function testBootstrapWithoutLicenseHasNullLicensing(): void
	{
		$this->loginAs($this->uid);
		$response = $this->controller()->bootstrap();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertSame('customercheck', $data['appId']);
		self::assertSame(1, $data['companionApi']);
		self::assertSame(MobileGateService::MIN_APP_VERSION, $data['minAppVersion']);
		self::assertSame(MobileGateService::MIN_APP_VERSION, $data['minApp']);
		self::assertNull($data['licensing']);
		self::assertFalse($data['seatAssigned']);
		self::assertSame('available', $data['mobileAppStatus']);
		self::assertSame($this->uid, $data['user']['uid']);
	}

	public function testBootstrapWithLicenseAndSeatExposesEnvelopeAndVendorKey(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$data = $this->controller()->bootstrap()->getData();

		self::assertIsArray($data['licensing']);
		self::assertSame('CUC2', $data['licensing']['format']);
		self::assertSame(Cuc2TestSigning::publicKeyB64(), $data['licensing']['vendorPublicKeyB64']);
		self::assertNotSame('', $data['licensing']['payloadB64']);
		self::assertNotSame('', $data['licensing']['signatureB64']);
		self::assertSame($data['licensing']['payloadB64'], $data['licensing']['envelope']['payloadB64']);
		self::assertTrue($data['licensing']['mobile']['enabledForUser']);
		self::assertTrue($data['seatAssigned']);
		self::assertTrue($data['seatWithinLimit']);
	}

	// ── Data endpoints through the full gate ────────────────────────────

	public function testTodayCompaniesAndDetailWithSeatReturn200(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->assertMobileGateOpen($this->uid);
		$this->loginAs($this->uid);

		$company = $this->createCompany();

		$today = $this->controller()->today();
		self::assertSame(200, $today->getStatus());
		$todayBody = $today->getData();
		self::assertTrue($todayBody['success']);
		self::assertIsArray($todayBody['data']['items']);
		self::assertArrayHasKey('meta', $todayBody);

		$list = $this->controller()->companies('Mobile Co ' . $this->suffix);
		self::assertSame(200, $list->getStatus());
		$rows = $list->getData()['data'];
		self::assertCount(1, $rows);
		self::assertSame((int)$company->getId(), $rows[0]['id']);
		self::assertArrayNotHasKey('notes', $rows[0], 'Mobile list row must stay lite');

		$detail = $this->controller()->companyDetail((int)$company->getId());
		self::assertSame(200, $detail->getStatus());
		$lite = $detail->getData()['data'];
		self::assertSame((int)$company->getId(), $lite['id']);
		self::assertArrayHasKey('nextExpiry', $lite);
		self::assertArrayHasKey('openActivities', $lite);
		self::assertArrayHasKey('capabilities', $lite);
		self::assertArrayHasKey('updatedAt', $lite);
	}

	public function testCompanyDetailUnknownIdReturns404(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);

		$response = $this->controller()->companyDetail(99999999);
		self::assertSame(404, $response->getStatus());
		self::assertSame('NOT_FOUND', $response->getData()['error']);
	}

	// ── Mutations (CSRF-safe channel + optimistic locking) ──────────────

	public function testActivityCreateUpdateDoneRoundTrip(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();

		// Companion aliases (summary/dueOn) must be accepted.
		$create = $this->controller([
			'companyId' => (int)$company->getId(),
			'type' => 'todo',
			'summary' => 'Call from the road',
			'dueOn' => '2099-01-15',
		])->createActivity();
		self::assertSame(201, $create->getStatus());
		$created = $create->getData()['data'];
		self::assertSame('Call from the road', $created['title']);
		self::assertSame('2099-01-15', $created['dueDate']);
		$id = (int)$created['id'];

		$update = $this->controller([
			'title' => 'Call done from the road',
			'updatedAt' => $created['updatedAt'],
		])->updateActivity($id);
		self::assertSame(200, $update->getStatus());
		$updated = $update->getData()['data'];
		self::assertSame('Call done from the road', $updated['title']);

		$done = $this->controller([
			'done' => true,
			'updatedAt' => $updated['updatedAt'],
		])->doneActivity($id);
		self::assertSame(200, $done->getStatus());
		self::assertNotNull($done->getData()['data']['doneAt']);

		$open = $this->controller()->activities(1, (int)$company->getId());
		self::assertSame(200, $open->getStatus());
		self::assertCount(0, $open->getData()['data'], 'Done activity must leave the open list');
	}

	public function testStaleUpdatedAtPreconditionReturns409(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();

		$create = $this->controller([
			'companyId' => (int)$company->getId(),
			'type' => 'todo',
			'title' => 'Race me',
			'dueDate' => '2099-02-01',
		])->createActivity();
		self::assertSame(201, $create->getStatus());
		$created = $create->getData()['data'];

		$stale = $this->controller([
			'title' => 'Stale write',
			'updatedAt' => '2001-01-01T00:00:00+00:00',
		])->updateActivity((int)$created['id']);

		self::assertSame(409, $stale->getStatus());
		self::assertSame(Constants::ERR_CONFLICT_STALE, $stale->getData()['error']);
	}

	public function testMutationWithoutAuthorizationHeaderOrCsrfReturns403(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();

		$response = $this->controller(
			['companyId' => (int)$company->getId(), 'type' => 'todo', 'title' => 'CSRF probe'],
			authorization: '',
		)->createActivity();

		self::assertSame(403, $response->getStatus());
		self::assertSame(Constants::ERR_ACCESS_DENIED, $response->getData()['error']);
	}

	public function testMobileUserCannotSeeOrUpdateAnotherUsersActivity(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->assignSeat($this->admin);
		$company = $this->createCompany();

		$this->loginAs($this->uid);
		$create = $this->controller([
			'companyId' => (int)$company->getId(),
			'type' => 'todo',
			'title' => 'Owner only',
			'dueDate' => '2099-02-02',
		])->createActivity();
		self::assertSame(201, $create->getStatus());
		$created = $create->getData()['data'];

		$this->loginAs($this->admin);
		$list = $this->controller()->activities(1, (int)$company->getId());
		self::assertSame(200, $list->getStatus());
		self::assertCount(0, $list->getData()['data']);

		$update = $this->controller([
			'title' => 'Cross-user write',
			'updatedAt' => $created['updatedAt'],
		])->updateActivity((int)$created['id']);
		self::assertSame(403, $update->getStatus());
		self::assertSame(Constants::ERR_ACCESS_DENIED, $update->getData()['error']);
	}

	public function testValidationErrorReturns422WithFields(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);

		$response = $this->controller([
			'companyId' => 0,
			'type' => 'todo',
			'title' => '',
		])->createActivity();

		self::assertSame(422, $response->getStatus());
		$body = $response->getData();
		self::assertFalse($body['success']);
		self::assertSame('VALIDATION', $body['error']);
		self::assertNotSame('', $body['message']);
	}

	public function testActivityCreateIdempotencyKeyReplaysSameRow(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();
		$key = 'outbox-' . $this->suffix . '-1';

		$first = $this->controller(
			[
				'companyId' => (int)$company->getId(),
				'type' => 'call',
				'summary' => 'Idempotent call',
				'dueOn' => '2099-03-01',
			],
			extraHeaders: ['Idempotency-Key' => $key],
		)->createActivity();
		self::assertSame(201, $first->getStatus());
		$firstId = (int)$first->getData()['data']['id'];

		$replay = $this->controller(
			[
				'companyId' => (int)$company->getId(),
				'type' => 'call',
				'summary' => 'Idempotent call (should not create)',
				'dueOn' => '2099-03-01',
			],
			extraHeaders: ['Idempotency-Key' => $key],
		)->createActivity();
		self::assertSame(201, $replay->getStatus());
		self::assertSame($firstId, (int)$replay->getData()['data']['id']);
		self::assertSame('Idempotent call', $replay->getData()['data']['title']);
	}

	public function testPushRegisterAndUnregisterRoundTrip(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);

		$register = $this->controller([
			'token' => 'ExponentPushToken[cuc-test-' . $this->suffix . ']',
			'platform' => 'android',
		])->registerPush();
		self::assertSame(200, $register->getStatus());
		self::assertTrue($register->getData()['data']['registered']);

		$unregister = $this->controller([
			'token' => 'ExponentPushToken[cuc-test-' . $this->suffix . ']',
		])->unregisterPush();
		self::assertSame(200, $unregister->getStatus());
		self::assertFalse($unregister->getData()['data']['registered']);
	}

	public function testDealsListAndStageChange(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();

		$deal = \OC::$server->get(DealService::class)->create([
			'companyId' => (int)$company->getId(),
			'title' => 'Mobile deal ' . $this->suffix,
			'stage' => 'lead',
			'valueCents' => 250000,
		], $this->uid);

		$list = $this->controller()->deals((int)$company->getId());
		self::assertSame(200, $list->getStatus());
		$rows = $list->getData()['data'];
		self::assertCount(1, $rows);
		self::assertSame('lead', $rows[0]['stage']);
		self::assertArrayHasKey('stale', $rows[0]);

		$change = $this->controller([
			'stage' => 'contacted',
			'updatedAt' => $rows[0]['updatedAt'],
		])->dealStage((int)$deal->getId());
		self::assertSame(200, $change->getStatus());
		$changed = $change->getData()['data'];
		self::assertSame('contacted', $changed['stage']);
		self::assertArrayHasKey('sequencesFired', $changed);
	}

	public function testAgreementsExpiryWindowFilter(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();

		$agreements = \OC::$server->get(AgreementService::class);
		$today = new \DateTimeImmutable('today');
		$soon = $agreements->create([
			'companyId' => (int)$company->getId(),
			'kind' => 'license',
			'productCode' => 'custom',
			'limits' => ['mobileSeats' => 1, 'terminalDevices' => 0, 'bundle' => false],
			'label' => 'Expiring soon ' . $this->suffix,
			'startsAt' => $today->modify('-300 days')->format('Y-m-d'),
			'validUntil' => $today->modify('+30 days')->format('Y-m-d'),
			'amountCents' => 5000,
			'status' => 'active',
		], $this->uid);
		$agreements->create([
			'companyId' => (int)$company->getId(),
			'kind' => 'license',
			'productCode' => 'custom',
			'limits' => ['mobileSeats' => 1, 'terminalDevices' => 0, 'bundle' => false],
			'label' => 'Far out ' . $this->suffix,
			'startsAt' => $today->format('Y-m-d'),
			'validUntil' => $today->modify('+700 days')->format('Y-m-d'),
			'amountCents' => 5000,
			'status' => 'active',
		], $this->uid);

		$response = $this->controller()->agreements(60, (int)$company->getId());
		self::assertSame(200, $response->getStatus());
		$rows = $response->getData()['data'];
		self::assertCount(1, $rows);
		self::assertSame((int)$soon->getId(), $rows[0]['id']);
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Builds the controller with a scripted request: params act as the JSON
	 * body fallback and the Authorization header selects the mutation channel.
	 *
	 * @param array<string, mixed> $params
	 */
	/**
	 * @param array<string, mixed> $params
	 * @param array<string, string> $extraHeaders
	 */
	private function controller(
		array $params = [],
		string $authorization = 'Basic dGVzdDp0ZXN0',
		array $extraHeaders = [],
	): MobileController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default,
		);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($authorization, $extraHeaders): string {
				if (strcasecmp($name, 'Authorization') === 0) {
					return $authorization;
				}
				foreach ($extraHeaders as $header => $value) {
					if (strcasecmp($header, $name) === 0) {
						return $value;
					}
				}

				return '';
			},
		);
		$request->method('passesCSRFCheck')->willReturn(false);

		return new MobileController(
			Application::APP_ID,
			$request,
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(AccessControlService::class),
			\OC::$server->get(MobileGateService::class),
			\OC::$server->get(MobileApiService::class),
			\OC::$server->get(CompanyService::class),
			\OC::$server->get(ActivityService::class),
			\OC::$server->get(DealService::class),
			\OC::$server->get(AgreementService::class),
			\OC::$server->get(MobileIdempotencyService::class),
			\OC::$server->get(MobilePushService::class),
			\OC::$server->get(IAppManager::class),
		);
	}

	private function assertGate402(string $code, JSONResponse $response): void
	{
		self::assertSame(402, $response->getStatus());
		$body = $response->getData();
		self::assertFalse($body['success']);
		self::assertSame($code, $body['error']);
		self::assertNotSame('', $body['message']);
	}

	private function createCompany(): \OCA\CustomerCheck\Db\Company
	{
		return \OC::$server->get(CompanyService::class)->create([
			'name' => 'Mobile Co ' . $this->suffix,
			'slug' => 'mobile-co-' . $this->suffix,
			'lang' => 'de',
			'country' => 'DE',
			'status' => 'active',
		], $this->uid);
	}

	private function applyLicense(int $seats, string $issuedAt = '2026-01-01', string $validUntil = '2099-12-31'): void
	{
		$this->ensureTestVendorKey();
		\OC::$server->get(LicenseService::class)->apply($this->admin, Cuc2TestSigning::signPayload([
			'v' => 2,
			'product' => 'customercheck',
			'customerId' => 'cuc-mob-test',
			'issuedAt' => $issuedAt,
			'validUntil' => $validUntil,
			'mobileSeats' => $seats,
		]));
	}

	private function assignSeat(string $uid): void
	{
		$this->ensureTestVendorKey();
		\OC::$server->get(LicenseService::class)->assignSeat($this->admin, $uid);
	}

	private function assertMobileGateOpen(string $uid): void
	{
		$state = \OC::$server->get(LicenseService::class)->gateState($uid);
		self::assertTrue($state['hasLicense'], 'license row missing after apply');
		self::assertTrue($state['licenseValid'], 'license not valid after apply');
		self::assertTrue($state['seatAssigned'], 'seat missing after assign');
		self::assertTrue($state['seatWithinLimit'], 'seat ranked over limit after assign');
	}

	private function ensureTestVendorKey(): void
	{
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . Cuc2TestSigning::publicKeyB64());
		putenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE=1');
	}

	private function wipeLicense(): void
	{
		\OC::$server->get(MobileSeatMapper::class)->deleteAll();
		\OC::$server->get(LicenseStateMapper::class)->deleteAll();
	}

	private function ensureUser(string $uid): void
	{
		$um = \OC::$server->get(IUserManager::class);
		if (!$um->userExists($uid)) {
			try {
				$um->createUser($uid, self::PASS);
			} catch (\Throwable $e) {
				// TOCTOU / leftover row: another process or orphan primary key.
				if (!$um->userExists($uid)) {
					throw $e;
				}
			}
		}
		$this->testUsers[] = $uid;
	}

	private function loginAs(string $uid): void
	{
		$user = \OC::$server->get(IUserManager::class)->get($uid);
		self::assertNotNull($user);
		\OC::$server->get(IUserSession::class)->setUser($user);
	}
}
