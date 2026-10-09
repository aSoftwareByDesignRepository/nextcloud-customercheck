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
use OCA\CustomerCheck\Service\CompanyDocumentsService;
use OCA\CustomerCheck\Service\CompanyService;
use OCA\CustomerCheck\Service\DealChecklistService;
use OCA\CustomerCheck\Service\DealService;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Service\MobileApiService;
use OCA\CustomerCheck\Service\MobileGateService;
use OCA\CustomerCheck\Service\MobileIdempotencyService;
use OCA\CustomerCheck\Service\MobilePushService;
use OCA\CustomerCheck\Service\PersonService;
use OCA\CustomerCheck\Service\WorkspaceAccessService;
use OCA\CustomerCheck\Service\WorkspaceService;
use OCA\CustomerCheck\Tests\Support\Cuc2TestSigning;
use OCA\CustomerCheck\Tests\Support\DbCascade;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDBConnection;
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

	/** Peer mobile user — not an app admin (app admins bypass activity ownership). */
	private string $peer = '';

	/** Isolated workspace for this run — never the polluted default book. */
	private ?int $runWorkspaceId = null;

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
		$this->peer = 'cuc_mp_' . $this->suffix;

		$config = \OC::$server->get(IConfig::class);
		foreach ([Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, Constants::CONFIG_APP_ADMIN_USER_IDS] as $key) {
			$this->savedConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}
		// Open access so plain test users pass rung 2 (canUseApp).
		$config->setAppValue(Application::APP_ID, Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, '0');

		$this->wipeLicense();
		$this->ensureUser($this->uid);
		$this->ensureUser($this->admin);
		$this->ensureUser($this->peer);
		// App admin so WorkspaceService::create / addMember work in helpers.
		$config->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_APP_ADMIN_USER_IDS,
			json_encode([$this->admin], JSON_THROW_ON_ERROR),
		);
		// Default org workspace is often at the 200-member cap from prior runs —
		// pin every company into a fresh book so addMember cannot fail closed.
		$this->runWorkspaceId = null;
		$this->isolatedWorkspaceId();
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		$this->purgeRunWorkspace();
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
			// uid-keyed ledgers (mob_idem rows with no activity link, push tokens)
			// are not reachable via workspace cascade — sweep them by uid.
			$this->deleteUidRows($uid);
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
		$response = $this->controller()->today(1);

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
		$response = $this->controller()->today(1);

		self::assertSame(403, $response->getStatus());
		self::assertSame('ACCESS_DENIED', $response->getData()['error']);
	}

	// ── Rungs 3–6: license gate (402 ladder) ────────────────────────────

	public function testTodayWithoutLicenseReturns402LicenseRequired(): void
	{
		$this->loginAs($this->uid);
		$this->assertGate402('LICENSE_REQUIRED', $this->controller()->today(1));
	}

	public function testTodayWithExpiredLicenseReturns402LicenseExpired(): void
	{
		$this->applyLicense(5, '2020-01-01', '2020-12-31');
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$this->assertGate402('LICENSE_EXPIRED', $this->controller()->today(1));
	}

	public function testTodayWithoutSeatReturns402NoMobileSeat(): void
	{
		$this->applyLicense(5);
		$this->loginAs($this->uid);
		$this->assertGate402('NO_MOBILE_SEAT', $this->controller()->today(1));
	}

	public function testSeatBeyondDowngradedLimitReturns402NoMobileSeat(): void
	{
		$this->applyLicense(2);
		$this->assignSeat($this->admin);
		$this->assignSeat($this->uid);
		// Downgrade to 1 seat: UID (newer assignment) falls out of limit.
		$this->applyLicense(1);
		$this->loginAs($this->uid);
		$this->assertGate402('NO_MOBILE_SEAT', $this->controller()->today(1));
	}

	// ── Bootstrap payload (never license-gated) ─────────────────────────

	public function testBootstrapWithoutLicenseHasNullLicensing(): void
	{
		$this->loginAs($this->uid);
		$response = $this->controller()->bootstrap();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertSame('customercheck', $data['appId']);
		self::assertSame(MobileGateService::COMPANION_API_VERSION, $data['companionApi']);
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

	// ── Workspace ACL ───────────────────────────────────────────────────

	public function testWorkspaceIdZeroReturns404(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);

		$response = $this->controller()->today(0);
		self::assertSame(404, $response->getStatus());
		self::assertSame('NOT_FOUND', $response->getData()['error']);
	}

	public function testForeignWorkspaceCompanyReturns404(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);

		$companyA = $this->createCompany();
		$wsA = $this->workspaceIdForCompany($companyA);

		// Legacy multi-book row inserted outside WorkspaceService::create (single-org lock).
		$wsB = $this->insertLegacyForeignWorkspace('foreign-ws-' . $this->suffix, 'Foreign WS ' . $this->suffix);
		$wsBId = (int)$wsB->getId();
		$companyB = \OC::$server->get(CompanyService::class)->create([
			'name' => 'Foreign Co ' . $this->suffix,
			'slug' => 'foreign-co-' . $this->suffix,
			'lang' => 'de',
			'country' => 'DE',
			'status' => 'active',
			'workspaceId' => $wsBId,
		], $this->admin);

		// Path-scoped under wsA but company lives in wsB → 404 (no existence leak).
		$detail = $this->controller()->companyDetail($wsA, (int)$companyB->getId());
		self::assertSame(404, $detail->getStatus());
		self::assertSame('NOT_FOUND', $detail->getData()['error']);

		// Listing a book the user is not a member of → 404.
		$list = $this->controller()->companies($wsBId);
		self::assertSame(404, $list->getStatus());
		self::assertSame('NOT_FOUND', $list->getData()['error']);

		$this->deleteWorkspaceCascade($wsBId);
	}

	public function testViewerCannotCreateActivity(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);
		$this->ensureMember($wsId, $this->uid, 'viewer');
		$this->loginAs($this->uid);

		$response = $this->controller([
			'companyId' => (int)$company->getId(),
			'type' => 'todo',
			'summary' => 'Viewer write probe',
			'dueOn' => '2099-01-15',
		])->createActivity($wsId);

		self::assertSame(422, $response->getStatus());
		$body = $response->getData();
		self::assertFalse($body['success']);
		self::assertSame('VALIDATION', $body['error']);
		self::assertSame(Constants::ERR_WS_ROLE, $body['detail']);
	}

	// ── Data endpoints through the full gate ────────────────────────────

	public function testTodayCompaniesAndDetailWithSeatReturn200(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->assertMobileGateOpen($this->uid);
		$this->loginAs($this->uid);

		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);

		$today = $this->controller()->today($wsId);
		self::assertSame(200, $today->getStatus());
		$todayBody = $today->getData();
		self::assertTrue($todayBody['success']);
		self::assertIsArray($todayBody['data']['items']);
		self::assertArrayHasKey('meta', $todayBody);

		$list = $this->controller()->companies($wsId, 'Mobile Co ' . $this->suffix);
		self::assertSame(200, $list->getStatus());
		$rows = $list->getData()['data'];
		self::assertCount(1, $rows);
		self::assertSame((int)$company->getId(), $rows[0]['id']);
		self::assertArrayNotHasKey('notes', $rows[0], 'Mobile list row must stay lite');

		$detail = $this->controller()->companyDetail($wsId, (int)$company->getId());
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
		$wsId = $this->isolatedWorkspaceId();
		$this->ensureMember($wsId, $this->uid);

		$response = $this->controller()->companyDetail($wsId, 99999999);
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
		$wsId = $this->workspaceIdForCompany($company);

		// Companion aliases (summary/dueOn) must be accepted.
		$create = $this->controller([
			'companyId' => (int)$company->getId(),
			'type' => 'todo',
			'summary' => 'Call from the road',
			'dueOn' => '2099-01-15',
		])->createActivity($wsId);
		self::assertSame(201, $create->getStatus());
		$created = $create->getData()['data'];
		self::assertSame('Call from the road', $created['title']);
		self::assertSame('2099-01-15', $created['dueDate']);
		$id = (int)$created['id'];

		$update = $this->controller([
			'title' => 'Call done from the road',
			'updatedAt' => $created['updatedAt'],
		])->updateActivity($wsId, $id);
		self::assertSame(200, $update->getStatus());
		$updated = $update->getData()['data'];
		self::assertSame('Call done from the road', $updated['title']);

		$done = $this->controller([
			'done' => true,
			'updatedAt' => $updated['updatedAt'],
		])->doneActivity($wsId, $id);
		self::assertSame(200, $done->getStatus());
		self::assertNotNull($done->getData()['data']['doneAt']);

		$open = $this->controller()->activities($wsId, 1, (int)$company->getId());
		self::assertSame(200, $open->getStatus());
		self::assertCount(0, $open->getData()['data'], 'Done activity must leave the open list');
	}

	public function testStaleUpdatedAtPreconditionReturns409(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);

		$create = $this->controller([
			'companyId' => (int)$company->getId(),
			'type' => 'todo',
			'title' => 'Race me',
			'dueDate' => '2099-02-01',
		])->createActivity($wsId);
		self::assertSame(201, $create->getStatus());
		$created = $create->getData()['data'];

		$stale = $this->controller([
			'title' => 'Stale write',
			'updatedAt' => '2001-01-01T00:00:00+00:00',
		])->updateActivity($wsId, (int)$created['id']);

		self::assertSame(409, $stale->getStatus());
		self::assertSame(Constants::ERR_CONFLICT_STALE, $stale->getData()['error']);
	}

	public function testMutationWithoutAuthorizationHeaderOrCsrfReturns403(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);

		$response = $this->controller(
			['companyId' => (int)$company->getId(), 'type' => 'todo', 'title' => 'CSRF probe'],
			authorization: '',
			csrfOk: false,
		)->createActivity($wsId);

		self::assertSame(403, $response->getStatus());
		self::assertSame(Constants::ERR_ACCESS_DENIED, $response->getData()['error']);
	}

	public function testMutationJunkBearerWithSessionCookieDoesNotBypassCsrf(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);

		$response = $this->controller(
			['companyId' => (int)$company->getId(), 'type' => 'todo', 'title' => 'Bearer CSRF'],
			authorization: 'Bearer x',
			csrfOk: false,
		)->createActivity($wsId);

		self::assertSame(403, $response->getStatus());
		self::assertSame(Constants::ERR_ACCESS_DENIED, $response->getData()['error']);
	}

	public function testMutationJunkBasicWithSessionCookieDoesNotBypassCsrf(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);

		$response = $this->controller(
			['companyId' => (int)$company->getId(), 'type' => 'todo', 'title' => 'Basic CSRF'],
			authorization: 'Basic ' . base64_encode('not-a-real-user:wrong-password'),
			csrfOk: false,
		)->createActivity($wsId);

		self::assertSame(403, $response->getStatus());
		self::assertSame(Constants::ERR_ACCESS_DENIED, $response->getData()['error']);
	}

	public function testMobileCompanyUpdateRejectsFilesFolderMassAssignment(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);
		$beforeId = $company->getFilesFolderId();
		$detail = $this->controller()->companyDetail($wsId, (int)$company->getId());
		self::assertSame(200, $detail->getStatus());
		$updatedAt = (string)($detail->getData()['data']['updatedAt'] ?? '');
		self::assertNotSame('', $updatedAt);

		$response = $this->controller([
			'name' => (string)$company->getName(),
			'filesFolderId' => 999999001,
			'filesFolderPath' => '/smuggled/path',
			'updatedAt' => $updatedAt,
		])->updateCompany($wsId, (int)$company->getId());

		self::assertSame(200, $response->getStatus());
		$fresh = \OC::$server->get(CompanyService::class)->get((int)$company->getId());
		self::assertSame($beforeId, $fresh->getFilesFolderId());
		self::assertNotSame('/smuggled/path', (string)($fresh->getFilesFolderPath() ?? ''));
	}

	public function testMobileUserCannotSeeOrUpdateAnotherUsersActivity(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->assignSeat($this->peer);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);
		// Peer must be a workspace member, but must not be an app admin
		// (app admins bypass createdBy filtering in searchForMobile / requireMobileAccess).
		$this->ensureMember($wsId, $this->peer);

		$this->loginAs($this->uid);
		$create = $this->controller([
			'companyId' => (int)$company->getId(),
			'type' => 'todo',
			'title' => 'Owner only',
			'dueDate' => '2099-02-02',
		])->createActivity($wsId);
		self::assertSame(201, $create->getStatus());
		$created = $create->getData()['data'];

		$this->loginAs($this->peer);
		$list = $this->controller()->activities($wsId, 1, (int)$company->getId());
		self::assertSame(200, $list->getStatus());
		self::assertCount(0, $list->getData()['data']);

		$update = $this->controller([
			'title' => 'Cross-user write',
			'updatedAt' => $created['updatedAt'],
		])->updateActivity($wsId, (int)$created['id']);
		self::assertSame(403, $update->getStatus());
		self::assertSame(Constants::ERR_ACCESS_DENIED, $update->getData()['error']);
	}

	public function testValidationErrorReturns422WithFields(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);

		// companyId must resolve in-workspace before field validation runs.
		$response = $this->controller([
			'companyId' => (int)$company->getId(),
			'type' => 'todo',
			'title' => '',
		])->createActivity($wsId);

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
		$wsId = $this->workspaceIdForCompany($company);
		$key = 'outbox-' . $this->suffix . '-1';

		$first = $this->controller(
			[
				'companyId' => (int)$company->getId(),
				'type' => 'call',
				'summary' => 'Idempotent call',
				'dueOn' => '2099-03-01',
			],
			extraHeaders: ['Idempotency-Key' => $key],
		)->createActivity($wsId);
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
		)->createActivity($wsId);
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
		$wsId = $this->workspaceIdForCompany($company);

		$deal = \OC::$server->get(DealService::class)->create([
			'companyId' => (int)$company->getId(),
			'title' => 'Mobile deal ' . $this->suffix,
			'stage' => 'lead',
			'valueCents' => 250000,
		], $this->uid);

		$list = $this->controller()->deals($wsId, (int)$company->getId());
		self::assertSame(200, $list->getStatus());
		$rows = $list->getData()['data'];
		self::assertCount(1, $rows);
		self::assertSame('lead', $rows[0]['stage']);
		self::assertArrayHasKey('stale', $rows[0]);

		$change = $this->controller([
			'stage' => 'contacted',
			'updatedAt' => $rows[0]['updatedAt'],
		])->dealStage($wsId, (int)$deal->getId());
		self::assertSame(200, $change->getStatus());
		$changed = $change->getData()['data'];
		self::assertSame('contacted', $changed['stage']);
		self::assertArrayHasKey('sequencesFired', $changed);

		$lostWithoutCode = $this->controller([
			'stage' => 'lost',
			'lostReason' => 'free text alone is not enough',
			'updatedAt' => $changed['updatedAt'],
		])->dealStage($wsId, (int)$deal->getId());
		self::assertSame(422, $lostWithoutCode->getStatus());

		$lost = $this->controller([
			'stage' => 'lost',
			'lostReasonCode' => 'competitor',
			'updatedAt' => $changed['updatedAt'],
		])->dealStage($wsId, (int)$deal->getId());
		self::assertSame(200, $lost->getStatus());
		self::assertSame('lost', $lost->getData()['data']['stage']);
		self::assertSame('competitor', $lost->getData()['data']['lostReasonCode'] ?? null);
	}

	/**
	 * CUC-COMPANION-ROAD-1 §10 Integration — mobile HTTP:
	 * create company → person → deal → apply template → assign → Done.
	 */
	public function testRoadAuthoringCompanyPersonDealChecklistAssignDone(): void
	{
		$db = \OC::$server->get(IDBConnection::class);
		if (!$db->tableExists('crm_cl_tmpl') || !$db->tableExists('crm_deal_cl_item')) {
			$this->markTestSkipped('Deal checklist tables not installed');
		}

		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->assignSeat($this->peer);
		$this->loginAs($this->uid);

		$wsId = $this->isolatedWorkspaceId();
		$this->ensureMember($wsId, $this->uid, 'member');
		$this->ensureMember($wsId, $this->peer, 'member');

		$key = 'road-co-' . $this->suffix;
		$createCompany = $this->controller(
			['name' => 'Road Co ' . $this->suffix, 'status' => 'lead'],
			extraHeaders: ['Idempotency-Key' => $key],
		)->createCompany($wsId);
		self::assertSame(201, $createCompany->getStatus());
		$companyPayload = $createCompany->getData()['data'];
		$companyId = (int)$companyPayload['id'];
		self::assertGreaterThan(0, $companyId);

		$replay = $this->controller(
			['name' => 'Road Co DUPLICATE ' . $this->suffix, 'status' => 'active'],
			extraHeaders: ['Idempotency-Key' => $key],
		)->createCompany($wsId);
		self::assertSame(201, $replay->getStatus());
		self::assertSame($companyId, (int)$replay->getData()['data']['id']);

		$createPerson = $this->controller(
			['name' => 'Road Buyer', 'email' => 'buyer-' . $this->suffix . '@example.com'],
			extraHeaders: ['Idempotency-Key' => 'road-person-' . $this->suffix],
		)->createPerson($wsId, $companyId);
		self::assertSame(201, $createPerson->getStatus());
		self::assertSame('Road Buyer', $createPerson->getData()['data']['name']);

		$createDeal = $this->controller(
			['title' => '2× AP + install', 'products' => 'AP + install'],
			extraHeaders: ['Idempotency-Key' => 'road-deal-' . $this->suffix],
		)->createDeal($wsId, $companyId);
		self::assertSame(201, $createDeal->getStatus());
		$dealId = (int)$createDeal->getData()['data']['id'];
		self::assertGreaterThan(0, $dealId);

		$tmpl = \OC::$server->get(DealChecklistService::class)->createTemplate(
			$wsId,
			['name' => 'Road tmpl ' . $this->suffix, 'steps' => ['Send invoice', 'Ship goods']],
			$this->admin,
		);
		$templateId = (int)$tmpl['id'];

		$apply = $this->controller(
			['templateId' => $templateId, 'mode' => 'append'],
			extraHeaders: ['Idempotency-Key' => 'road-apply-' . $this->suffix],
		)->applyChecklistTemplate($wsId, $dealId);
		self::assertSame(201, $apply->getStatus());
		$items = $apply->getData()['data'];
		self::assertIsArray($items);
		self::assertGreaterThanOrEqual(2, count($items));
		$open = null;
		foreach ($items as $row) {
			if (($row['status'] ?? '') === 'open') {
				$open = $row;
				break;
			}
		}
		self::assertNotNull($open);
		$itemId = (int)$open['id'];
		$updatedAt = (string)($open['updatedAt'] ?? '');

		$assign = $this->controller([
			'assigneeUserId' => $this->peer,
			'updatedAt' => $updatedAt,
		])->updateChecklistItem($wsId, $itemId);
		self::assertSame(200, $assign->getStatus());
		self::assertSame($this->peer, $assign->getData()['data']['assigneeUserId'] ?? null);
		$updatedAt = (string)($assign->getData()['data']['updatedAt'] ?? $updatedAt);

		// MH-05: due date update (same optimistic lock path as assign).
		$due = $this->controller([
			'dueOn' => '2099-08-15',
			'updatedAt' => $updatedAt,
		])->updateChecklistItem($wsId, $itemId);
		self::assertSame(200, $due->getStatus());
		self::assertSame('2099-08-15', $due->getData()['data']['dueOn'] ?? null);
		$updatedAt = (string)($due->getData()['data']['updatedAt'] ?? $updatedAt);

		$done = $this->controller([
			'evidenceNote' => 'Done',
			'updatedAt' => $updatedAt,
		])->doneChecklistItem($wsId, $itemId);
		self::assertSame(200, $done->getStatus());
		self::assertSame('done', $done->getData()['data']['status']);

		// Closed item must reject assign (US-06 AC-3).
		$closedAssign = $this->controller([
			'assigneeUserId' => $this->uid,
			'updatedAt' => (string)($done->getData()['data']['updatedAt'] ?? $updatedAt),
		])->updateChecklistItem($wsId, $itemId);
		self::assertSame(422, $closedAssign->getStatus());

		// Cross-WS IDOR: foreign book must not mutate this company (404).
		$foreign = $this->insertLegacyForeignWorkspace('mg-fx-' . $this->suffix, 'Foreign ' . $this->suffix);
		$foreignId = (int)$foreign->getId();
		$this->ensureMember($foreignId, $this->uid, 'member');
		$idor = $this->controller(
			['name' => 'Leak'],
			extraHeaders: ['Idempotency-Key' => 'road-idor-' . $this->suffix],
		)->createPerson($foreignId, $companyId);
		self::assertSame(404, $idor->getStatus());
		$this->deleteWorkspaceCascade($foreignId);
	}

	public function testRoadAuthoringViewerCannotCreateCompany(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$wsId = $this->isolatedWorkspaceId();
		$this->ensureMember($wsId, $this->uid, 'viewer');

		$response = $this->controller(
			['name' => 'Viewer blocked ' . $this->suffix],
			extraHeaders: ['Idempotency-Key' => 'road-viewer-' . $this->suffix],
		)->createCompany($wsId);
		self::assertSame(422, $response->getStatus());
		self::assertSame('VALIDATION', $response->getData()['error']);
	}

	public function testAgreementsExpiryWindowFilter(): void
	{
		$this->applyLicense(5);
		$this->assignSeat($this->uid);
		$this->loginAs($this->uid);
		$company = $this->createCompany();
		$wsId = $this->workspaceIdForCompany($company);

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

		$response = $this->controller()->agreements($wsId, 60, (int)$company->getId());
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
	 * @param array<string, string> $extraHeaders
	 */
	private function controller(
		array $params = [],
		string $authorization = 'Basic dGVzdDp0ZXN0',
		array $extraHeaders = [],
		bool $csrfOk = true,
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
		// Session-authenticated integration calls use CSRF-ok by default (browser path).
		// CSRF / fake-Bearer probes set $csrfOk false.
		$request->method('passesCSRFCheck')->willReturn($csrfOk);

		return new MobileController(
			Application::APP_ID,
			$request,
			\OC::$server->get(IUserSession::class),
			\OC::$server->get(AccessControlService::class),
			\OC::$server->get(WorkspaceAccessService::class),
			\OC::$server->get(MobileGateService::class),
			\OC::$server->get(MobileApiService::class),
			\OC::$server->get(CompanyService::class),
			\OC::$server->get(PersonService::class),
			\OC::$server->get(ActivityService::class),
			\OC::$server->get(DealService::class),
			\OC::$server->get(AgreementService::class),
			\OC::$server->get(MobileIdempotencyService::class),
			\OC::$server->get(MobilePushService::class),
			\OC::$server->get(CompanyDocumentsService::class),
			\OC::$server->get(WorkspaceService::class),
			\OC::$server->get(IUserManager::class),
			\OC::$server->get(IAppManager::class),
			\OC::$server->get(DealChecklistService::class),
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

	private function isolatedWorkspaceId(): int
	{
		if ($this->runWorkspaceId !== null && $this->runWorkspaceId > 0) {
			return $this->runWorkspaceId;
		}
		// Production create is locked to one organisation — tests mint an isolated
		// book via the mapper so MobileGate ACL stays hermetic and never pollutes Default.
		$ws = $this->insertLegacyForeignWorkspace('mg-' . $this->suffix, 'Mobile Gate ' . $this->suffix);
		$this->runWorkspaceId = (int)$ws->getId();
		$this->ensureMember($this->runWorkspaceId, $this->admin, 'manager');
		return $this->runWorkspaceId;
	}

	/** Drop this run’s isolated book (best-effort). */
	private function purgeRunWorkspace(): void
	{
		if ($this->runWorkspaceId === null || $this->runWorkspaceId <= 0) {
			return;
		}
		$this->deleteWorkspaceCascade($this->runWorkspaceId);
		$this->runWorkspaceId = null;
	}

	/**
	 * Insert a non-default workspace row for legacy ACL isolation probes.
	 * WorkspaceService::create is locked in single-org mode.
	 */
	private function insertLegacyForeignWorkspace(string $slug, string $name): \OCA\CustomerCheck\Db\Workspace
	{
		$mapper = \OC::$server->get(\OCA\CustomerCheck\Db\WorkspaceMapper::class);
		$now = new \DateTime('now');
		$ws = new \OCA\CustomerCheck\Db\Workspace();
		$ws->setSlug($slug);
		$ws->setName($name);
		$ws->setIsDefault(0);
		$ws->setCreatedBy($this->admin);
		$ws->setCreatedAt($now);
		$ws->setUpdatedAt($now);
		return $mapper->insert($ws);
	}

	/**
	 * Delete leftover MobileGate integration books so WS_MAX_PER_INSTANCE does not flake.
	 */
	private function purgeStaleMobileGateWorkspaces(): void
	{
		$db = \OC::$server->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('crm_workspace')
			->where($qb->expr()->like('slug', $qb->createNamedParameter('mg-%')))
			->setMaxResults(200);
		$r = $qb->executeQuery();
		$ids = [];
		while ($row = $r->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$r->closeCursor();
		foreach ($ids as $id) {
			$this->deleteWorkspaceCascade($id);
		}
	}

	/**
	 * Hard-delete a workspace and every workspace-scoped row via the shared
	 * cascade (tests/Support/DbCascade.php — leaf tables key off entity ids,
	 * and the real settings table is 'crm_ws_set', not 'crm_ws_settings').
	 */
	private function deleteWorkspaceCascade(int $workspaceId): void
	{
		DbCascade::workspace($workspaceId);
	}

	/** Drop uid-keyed leftovers (idempotency ledger, push tokens) for a test user. */
	private function deleteUidRows(string $uid): void
	{
		DbCascade::uidRows($uid);
	}

	private function ensureMember(int $workspaceId, string $uid, string $role = 'member'): void
	{
		\OC::$server->get(WorkspaceService::class)->addMember(
			$workspaceId,
			$uid,
			$role,
			true,
			$this->admin,
		);
	}

	private function workspaceIdForCompany(\OCA\CustomerCheck\Db\Company $company): int
	{
		return (int)$company->getWorkspaceId();
	}

	private function createCompany(?int $workspaceId = null): \OCA\CustomerCheck\Db\Company
	{
		$ws = ($workspaceId !== null && $workspaceId > 0)
			? $workspaceId
			: $this->isolatedWorkspaceId();
		$this->ensureMember($ws, $this->uid);
		$input = [
			'name' => 'Mobile Co ' . $this->suffix,
			'slug' => 'mobile-co-' . $this->suffix,
			'lang' => 'de',
			'country' => 'DE',
			'status' => 'active',
			'workspaceId' => $ws,
		];
		return \OC::$server->get(CompanyService::class)->create($input, $this->uid);
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
