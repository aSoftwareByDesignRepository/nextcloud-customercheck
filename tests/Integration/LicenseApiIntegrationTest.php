<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Integration;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Controller\LicenseController;
use OCA\CustomerCheck\Db\LicenseStateMapper;
use OCA\CustomerCheck\Db\MobileSeatMapper;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Tests\Support\Cuc2TestSigning;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * Settings → License admin API (apply / remove / seats) with real container
 * services and the app-admin guard (COMPANION-APP §5.2).
 *
 * @group integration
 */
final class LicenseApiIntegrationTest extends TestCase
{
	private const PASS = 'Cuc-Lic-Http-9xK!';

	/** @var list<string> */
	private array $testUsers = [];

	private string $savedAppAdmins = '';

	private string $admin = '';

	private string $user = '';

	private string $second = '';

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		$this->ensureTestVendorKey();
		\OC_User::setIncognitoMode(false);

		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		$this->admin = 'cuc_la_' . $suffix;
		$this->user = 'cuc_lu_' . $suffix;
		$this->second = 'cuc_ls_' . $suffix;

		$config = \OC::$server->get(\OCP\IConfig::class);
		$this->savedAppAdmins = $config->getAppValue(
			Application::APP_ID,
			\OCA\CustomerCheck\Constants::CONFIG_APP_ADMIN_USER_IDS,
			'',
		);
		// Empty app-admin list → every system admin is app admin (PSD §2.2).
		$config->setAppValue(Application::APP_ID, \OCA\CustomerCheck\Constants::CONFIG_APP_ADMIN_USER_IDS, '[]');

		$this->wipeLicense();
		self::assertSame(0, \OC::$server->get(MobileSeatMapper::class)->countAll(), 'Seat ledger must be empty after wipe');
		$this->ensureUser($this->user);
		$this->ensureUser($this->second);
		$this->ensureUser($this->admin, true);
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}
		\OC::$server->get(IUserSession::class)->setUser(null);
		$this->wipeLicense();
		$config = \OC::$server->get(\OCP\IConfig::class);
		if ($this->savedAppAdmins === '') {
			$config->deleteAppValue(Application::APP_ID, \OCA\CustomerCheck\Constants::CONFIG_APP_ADMIN_USER_IDS);
		} else {
			$config->setAppValue(Application::APP_ID, \OCA\CustomerCheck\Constants::CONFIG_APP_ADMIN_USER_IDS, $this->savedAppAdmins);
		}
		$um = \OC::$server->get(IUserManager::class);
		foreach ($this->testUsers as $uid) {
			try {
				if ($um->userExists($uid)) {
					$um->get($uid)?->delete();
				}
			} catch (\Throwable) {
				// NC 34 user-config cleanup can throw UnknownKeyException — never fail the suite on teardown.
			}
		}
		$this->testUsers = [];
	}

	public function testNonAdminIsRejectedOnEveryEndpoint(): void
	{
		$this->loginAs($this->user);
		$controller = $this->controller();

		foreach ([
			$controller->show(),
			$controller->apply(),
			$controller->remove(),
			$controller->seats(),
			$controller->assignSeat(),
			$controller->removeSeat($this->user),
		] as $response) {
			self::assertSame(403, $response->getStatus());
			self::assertSame('ACCESS_DENIED', $response->getData()['error']);
		}
	}

	public function testAnonymousIsRejected(): void
	{
		\OC::$server->get(IUserSession::class)->setUser(null);
		self::assertSame(403, $this->controller()->show()->getStatus());
	}

	public function testApplyMissingKeyReturns422(): void
	{
		$this->loginAs($this->admin);
		$response = $this->controller()->apply();

		self::assertSame(422, $response->getStatus());
		self::assertSame('LICENSE_INVALID', $response->getData()['error']);
	}

	public function testApplyGarbageKeyReturns422WithReadableMessage(): void
	{
		$this->loginAs($this->admin);
		$response = $this->controller(['key' => 'CUC2.not.valid'])->apply();

		self::assertSame(422, $response->getStatus());
		$body = $response->getData();
		self::assertSame('LICENSE_INVALID', $body['error']);
		self::assertNotSame('', $body['message']);
	}

	public function testApplyStatusSeatLifecycle(): void
	{
		$this->loginAs($this->admin);

		$applied = $this->controller(['key' => $this->wireKey(2)])->apply();
		self::assertSame(200, $applied->getStatus());
		$status = $applied->getData();
		self::assertTrue($status['state']['valid']);
		self::assertSame(2, $status['state']['mobileSeats']);
		self::assertSame('cuc-lic-test', $status['state']['customerId']);
		self::assertSame($this->admin, $status['state']['appliedBy']);
		self::assertSame(0, $status['seats']['assigned']);
		self::assertSame(2, $status['seats']['limit']);
		self::assertSame('CUC2', $status['envelope']['format']);

		self::assertSame(201, $this->controller(['userId' => $this->user])->assignSeat()->getStatus());
		$second = $this->controller(['userId' => $this->second])->assignSeat();
		self::assertSame(201, $second->getStatus());
		self::assertTrue($second->getData()['withinLimit']);

		$third = $this->controller(['userId' => $this->admin])->assignSeat();
		self::assertSame(409, $third->getStatus());
		self::assertSame('SEAT_LIMIT_REACHED', $third->getData()['error']);

		$again = $this->controller(['userId' => $this->user])->assignSeat();
		self::assertSame(200, $again->getStatus());
		self::assertSame($this->user, $again->getData()['uid']);

		$seats = $this->controller()->seats();
		self::assertSame(200, $seats->getStatus());
		$list = $seats->getData();
		self::assertSame(2, $list['total']);
		self::assertSame($this->user, $list['data'][0]['uid'], 'Oldest assignment ranks first');

		self::assertSame(200, $this->controller()->removeSeat($this->user)->getStatus());
		self::assertSame(404, $this->controller()->removeSeat($this->user)->getStatus());

		$removed = $this->controller()->remove();
		self::assertSame(200, $removed->getStatus());
		self::assertNull($removed->getData()['state']);
		self::assertSame(0, $removed->getData()['seats']['assigned']);
	}

	public function testAssignSeatUnknownUserReturns422(): void
	{
		$this->loginAs($this->admin);
		$this->controller(['key' => $this->wireKey(5)])->apply();

		$response = $this->controller(['userId' => 'no-such-user-' . bin2hex(random_bytes(3))])->assignSeat();
		self::assertSame(422, $response->getStatus());
		self::assertSame('UNKNOWN_USER', $response->getData()['error']);
	}

	public function testReplacingLicenseKeepsSeatsButChangesLimit(): void
	{
		$this->loginAs($this->admin);
		$this->controller(['key' => $this->wireKey(2)])->apply();
		$this->controller(['userId' => $this->user])->assignSeat();
		$this->controller(['userId' => $this->second])->assignSeat();

		$status = $this->controller(['key' => $this->wireKey(1)])->apply()->getData();
		self::assertSame(1, $status['seats']['limit']);
		self::assertSame(2, $status['seats']['assigned']);

		$list = $this->controller()->seats()->getData();
		self::assertTrue($list['data'][0]['withinLimit']);
		self::assertFalse($list['data'][1]['withinLimit']);
	}

	public function testDeletingUserFreesTheSeat(): void
	{
		$this->loginAs($this->admin);
		$this->wipeLicense();
		$apply = $this->controller(['key' => $this->wireKey(5)])->apply();
		self::assertSame(200, $apply->getStatus(), 'License apply must succeed before seat assign: ' . json_encode($apply->getData()));
		$applyBody = $apply->getData();
		self::assertSame(5, (int)($applyBody['seats']['limit'] ?? 0), 'Applied license must expose seat limit: ' . json_encode($applyBody));
		self::assertSame(0, (int)($applyBody['seats']['assigned'] ?? -1), 'Seat ledger must stay empty after apply: ' . json_encode($applyBody));
		$seats = \OC::$server->get(MobileSeatMapper::class);
		$states = \OC::$server->get(LicenseStateMapper::class);
		self::assertSame(0, $seats->countAll(), 'Mapper count must match apply envelope before assign');
		self::assertNotNull($states->findSingleton(), 'License row must persist after apply');

		$victim = 'cuc_lv_' . substr(bin2hex(random_bytes(3)), 0, 6);
		$this->ensureUser($victim);
		self::assertSame(0, $seats->countAll(), 'Creating a Nextcloud user must not consume a mobile seat');
		// Shared-DB suites can wipe or fill crm_* between steps. Restore a clean
		// licensed ledger immediately before assign so NO_LICENSE / SEAT_LIMIT_REACHED
		// never masquerade as a listener bug.
		$this->wipeLicense();
		$ready = $this->controller(['key' => $this->wireKey(5)])->apply();
		self::assertSame(200, $ready->getStatus(), 'License must be present immediately before assign: ' . json_encode($ready->getData()));
		self::assertNotNull($states->findSingleton(), 'License row must persist after pre-assign apply');
		self::assertSame(0, $seats->countAll(), 'Seat ledger must be empty immediately before assign');
		self::assertGreaterThan(0, (int)$states->findSingleton()->getMobileSeats());

		$assign = $this->controller(['userId' => $victim])->assignSeat();
		self::assertContains(
			$assign->getStatus(),
			[200, 201],
			'Seat assign must create or idempotently confirm: ' . json_encode([
				'body' => $assign->getData(),
				'count' => $seats->countAll(),
				'status' => \OC::$server->get(LicenseService::class)->status(),
			]),
		);

		try {
			\OC::$server->get(IUserManager::class)->get($victim)?->delete();
		} catch (\Throwable) {
			// Listener still runs before config cleanup throws on some NC builds.
		}

		self::assertNull($seats->findByUid($victim), 'UserDeletedListener must free the seat');
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function controller(array $params = []): LicenseController
	{
		$this->ensureTestVendorKey();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new LicenseController(
			Application::APP_ID,
			$request,
			\OC::$server->get(AccessControlService::class),
			\OC::$server->get(LicenseService::class),
			\OC::$server->get(\OCP\L10N\IFactory::class)->get(Application::APP_ID),
		);
	}

	private function wireKey(int $seats): string
	{
		$this->ensureTestVendorKey();
		return Cuc2TestSigning::signPayload([
			'v' => 2,
			'product' => 'customercheck',
			'customerId' => 'cuc-lic-test',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'mobileSeats' => $seats,
		]);
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

	private function ensureUser(string $uid, bool $systemAdmin = false): void
	{
		$um = \OC::$server->get(IUserManager::class);
		if (!$um->userExists($uid)) {
			$um->createUser($uid, self::PASS);
		}
		if ($systemAdmin) {
			$gm = \OC::$server->get(IGroupManager::class);
			$admin = $gm->get('admin');
			$user = $um->get($uid);
			if ($admin !== null && $user !== null && !$admin->inGroup($user)) {
				$admin->addUser($user);
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
