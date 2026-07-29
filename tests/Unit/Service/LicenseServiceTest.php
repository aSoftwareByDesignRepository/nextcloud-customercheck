<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Db\LicenseState;
use OCA\CustomerCheck\Db\LicenseStateMapper;
use OCA\CustomerCheck\Db\MobileSeat;
use OCA\CustomerCheck\Db\MobileSeatMapper;
use OCA\CustomerCheck\Exception\LicenseException;
use OCA\CustomerCheck\Exception\MobileGateException;
use OCA\CustomerCheck\Service\LicenseService;
use OCA\CustomerCheck\Tests\Support\Cuc2TestSigning;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CUC2 license lifecycle + seat governance (COMPANION-APP §5).
 */
final class LicenseServiceTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private LicenseStateMapper&MockObject $stateMapper;
	private MobileSeatMapper&MockObject $seatMapper;
	private ITimeFactory&MockObject $timeFactory;
	private IUserManager&MockObject $userManager;
	private ILockingProvider&MockObject $locking;

	public static function setUpBeforeClass(): void
	{
		putenv('CUC_VENDOR_PUBLIC_KEY_B64=' . Cuc2TestSigning::publicKeyB64());
	}

	public static function tearDownAfterClass(): void
	{
		putenv('CUC_VENDOR_PUBLIC_KEY_B64');
	}

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->stateMapper = $this->createMock(LicenseStateMapper::class);
		$this->seatMapper = $this->createMock(MobileSeatMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-26 12:00:00'));
		$this->timeFactory->method('getTime')->willReturn(1_784_000_000);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->locking = $this->createMock(ILockingProvider::class);
	}

	private function service(): LicenseService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params),
		);

		return new LicenseService(
			$this->db,
			$this->stateMapper,
			$this->seatMapper,
			$this->timeFactory,
			$this->userManager,
			$this->locking,
			$l10n,
		);
	}

	private function storedState(string $validUntil = '2027-12-31', int $seats = 3): LicenseState
	{
		$state = new LicenseState();
		$state->setId(1);
		$state->setCustomerId('acme-corp');
		$state->setIssuedAt('2026-01-01');
		$state->setValidUntil($validUntil);
		$state->setMobileSeats($seats);
		$state->setPayloadB64('payload-b64');
		$state->setSignatureB64('signature-b64');
		$state->setAppliedAt(1_700_000_000);
		$state->setAppliedBy('admin');

		return $state;
	}

	private function seat(int $id, string $uid, int $assignedAt): MobileSeat
	{
		$seat = new MobileSeat();
		$seat->setId($id);
		$seat->setUid($uid);
		$seat->setAssignedAt($assignedAt);
		$seat->setAssignedBy('admin');

		return $seat;
	}

	// ── status ──────────────────────────────────────────────────────────

	public function testStatusWithoutLicense(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn(null);
		$this->seatMapper->method('countAll')->willReturn(0);

		$status = $this->service()->status();

		$this->assertNull($status['state']);
		$this->assertNull($status['envelope']);
		$this->assertSame(['assigned' => 0, 'limit' => 0], $status['seats']);
		$this->assertSame('available', $status['mobileAppStatus']);
	}

	public function testStatusWithValidLicenseComputesDaysRemaining(): void
	{
		// today 2026-07-26, until 2026-08-25 → 30 days → expiresSoon.
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2026-08-25'));
		$this->seatMapper->method('countAll')->willReturn(2);

		$status = $this->service()->status();

		$this->assertTrue($status['state']['valid']);
		$this->assertSame(30, $status['state']['daysRemaining']);
		$this->assertTrue($status['state']['expiresSoon']);
		$this->assertSame(['assigned' => 2, 'limit' => 3], $status['seats']);
		$this->assertSame(
			['format' => 'CUC2', 'payloadB64' => 'payload-b64', 'signatureB64' => 'signature-b64'],
			$status['envelope'],
		);
	}

	public function testStatusOutside30DayWindowIsNotExpiringSoon(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2026-08-26'));
		$this->seatMapper->method('countAll')->willReturn(0);

		$status = $this->service()->status();

		$this->assertSame(31, $status['state']['daysRemaining']);
		$this->assertFalse($status['state']['expiresSoon']);
	}

	public function testStatusExpiredLicense(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2026-07-25'));
		$this->seatMapper->method('countAll')->willReturn(1);

		$status = $this->service()->status();

		$this->assertFalse($status['state']['valid']);
		$this->assertSame(-1, $status['state']['daysRemaining']);
		$this->assertFalse($status['state']['expiresSoon'], 'Expired must not read as "expiring soon"');
		$this->assertNull($status['envelope'], 'Expired license must not hand out an envelope');
	}

	public function testStatusValidOnLastDayInclusive(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2026-07-26'));
		$this->seatMapper->method('countAll')->willReturn(0);

		$status = $this->service()->status();

		$this->assertTrue($status['state']['valid']);
		$this->assertSame(0, $status['state']['daysRemaining']);
		$this->assertTrue($status['state']['expiresSoon']);
		$this->assertNotNull($status['envelope']);
	}

	// ── gateState / assertMobileAccess ──────────────────────────────────

	public function testGateStateNoLicense(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn(null);
		$this->seatMapper->method('findByUid')->willReturn(null);

		$gate = $this->service()->gateState('alice');

		$this->assertFalse($gate['hasLicense']);
		$this->assertFalse($gate['licenseValid']);
		$this->assertFalse($gate['seatAssigned']);
		$this->assertFalse($gate['seatWithinLimit']);
		$this->assertNull($gate['payloadB64']);
	}

	public function testGateStateSeatWithinLimit(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2027-12-31', 2));
		$seat = $this->seat(2, 'alice', 200);
		$this->seatMapper->method('findByUid')->with('alice')->willReturn($seat);
		$this->seatMapper->method('findAllRanked')->willReturn([
			$this->seat(1, 'bob', 100),
			$seat,
			$this->seat(3, 'carol', 300),
		]);

		$gate = $this->service()->gateState('alice');

		$this->assertTrue($gate['hasLicense']);
		$this->assertTrue($gate['licenseValid']);
		$this->assertTrue($gate['seatAssigned']);
		$this->assertTrue($gate['seatWithinLimit'], 'Rank 2 of limit 2 is within');
	}

	public function testGateStateSeatBeyondLimitAfterDowngrade(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2027-12-31', 2));
		$seat = $this->seat(3, 'carol', 300);
		$this->seatMapper->method('findByUid')->with('carol')->willReturn($seat);
		$this->seatMapper->method('findAllRanked')->willReturn([
			$this->seat(1, 'bob', 100),
			$this->seat(2, 'alice', 200),
			$seat,
		]);

		$gate = $this->service()->gateState('carol');

		$this->assertTrue($gate['seatAssigned']);
		$this->assertFalse($gate['seatWithinLimit'], 'Rank 3 of limit 2 must be disabled');
	}

	/**
	 * @return array<string, array{0: ?string, 1: bool, 2: string}>
	 */
	public static function gateLadder(): array
	{
		return [
			'no license' => [null, false, 'LICENSE_REQUIRED'],
			'expired license' => ['2026-07-25', true, 'LICENSE_EXPIRED'],
			'no seat' => ['2027-12-31', false, 'NO_MOBILE_SEAT'],
		];
	}

	/** @dataProvider gateLadder */
	public function testAssertMobileAccessLadder(?string $validUntil, bool $withSeat, string $expectedCode): void
	{
		$this->stateMapper->method('findSingleton')
			->willReturn($validUntil === null ? null : $this->storedState($validUntil));
		if ($withSeat) {
			$seat = $this->seat(1, 'alice', 100);
			$this->seatMapper->method('findByUid')->willReturn($seat);
			$this->seatMapper->method('findAllRanked')->willReturn([$seat]);
		} else {
			$this->seatMapper->method('findByUid')->willReturn(null);
		}

		try {
			$this->service()->assertMobileAccess('alice');
			$this->fail('Expected MobileGateException ' . $expectedCode);
		} catch (MobileGateException $e) {
			$this->assertSame($expectedCode, $e->getErrorCode());
			$this->assertSame(402, $e->getCode());
			$this->assertNotSame('', $e->getMessage());
		}
	}

	public function testAssertMobileAccessPassesForSeatWithinLimit(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState());
		$seat = $this->seat(1, 'alice', 100);
		$this->seatMapper->method('findByUid')->willReturn($seat);
		$this->seatMapper->method('findAllRanked')->willReturn([$seat]);

		$this->service()->assertMobileAccess('alice');
		$this->addToAssertionCount(1);
	}

	// ── apply ───────────────────────────────────────────────────────────

	public function testApplyRejectsMalformedKey(): void
	{
		try {
			$this->service()->apply('admin', 'garbage');
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('LICENSE_INVALID', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
			$this->assertStringContainsString('CUC2', $e->getMessage());
		}
	}

	public function testApplyRejectsTamperedSignature(): void
	{
		$wire = Cuc2TestSigning::validKey();
		[$fmt, $payload] = explode('.', $wire);
		$forged = $fmt . '.' . $payload . '.' . str_repeat('A', 86);

		try {
			$this->service()->apply('admin', $forged);
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('LICENSE_INVALID', $e->getErrorCode());
			$this->assertStringContainsString('signature', $e->getMessage());
		}
	}

	public function testApplyRejectsWrongProductPayload(): void
	{
		$json = json_encode([
			'v' => 2,
			'product' => 'projectcheck',
			'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2099-12-31',
			'mobileSeats' => 5,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->assertIsString($json);

		try {
			$this->service()->apply('admin', Cuc2TestSigning::signRawBytes($json));
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('LICENSE_INVALID', $e->getErrorCode());
			$this->assertStringContainsString('payload', $e->getMessage());
		}
	}

	public function testApplyPersistsCanonicalStateInTransaction(): void
	{
		$wire = Cuc2TestSigning::validKey(['mobileSeats' => 7, 'validUntil' => '2027-06-30']);
		[, $payloadB64, $signatureB64] = explode('.', $wire);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->stateMapper->expects($this->once())->method('deleteAll');

		$inserted = null;
		$this->stateMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (LicenseState $state) use (&$inserted): LicenseState {
				$inserted = $state;
				return $state;
			});
		$this->stateMapper->method('findSingleton')->willReturnCallback(
			static function () use (&$inserted): ?LicenseState {
				return $inserted;
			},
		);
		$this->seatMapper->method('countAll')->willReturn(0);

		$status = $this->service()->apply('admin', $wire);

		$this->assertInstanceOf(LicenseState::class, $inserted);
		$this->assertSame('acme-corp', $inserted->getCustomerId());
		$this->assertSame('2027-06-30', $inserted->getValidUntil());
		$this->assertSame(7, $inserted->getMobileSeats());
		$this->assertSame($payloadB64, $inserted->getPayloadB64());
		$this->assertSame($signatureB64, $inserted->getSignatureB64());
		$this->assertSame(1_784_000_000, $inserted->getAppliedAt());
		$this->assertSame('admin', $inserted->getAppliedBy());
		$this->assertTrue($status['state']['valid']);
		$this->assertSame(7, $status['seats']['limit']);
	}

	public function testApplyAcceptsWhitespaceWrappedKey(): void
	{
		$wire = Cuc2TestSigning::validKey();
		$wrapped = substr($wire, 0, 15) . "\n " . substr($wire, 15);

		$this->stateMapper->method('findSingleton')->willReturn(null);
		$this->seatMapper->method('countAll')->willReturn(0);
		$this->stateMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->service()->apply('admin', $wrapped);
	}

	public function testApplyRollsBackOnInsertFailure(): void
	{
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->stateMapper->method('insert')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(\RuntimeException::class);
		$this->service()->apply('admin', Cuc2TestSigning::validKey());
	}

	public function testApplyReleasesLockEvenOnFailure(): void
	{
		$this->locking->expects($this->once())->method('acquireLock');
		$this->locking->expects($this->once())->method('releaseLock');
		$this->stateMapper->method('insert')->willThrowException(new \RuntimeException('db down'));

		try {
			$this->service()->apply('admin', Cuc2TestSigning::validKey());
		} catch (\RuntimeException) {
		}
	}

	// ── remove ──────────────────────────────────────────────────────────

	public function testRemoveDeletesSeatsAndStateTransactionally(): void
	{
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->seatMapper->expects($this->once())->method('deleteAll');
		$this->stateMapper->expects($this->once())->method('deleteAll');
		$this->stateMapper->method('findSingleton')->willReturn(null);
		$this->seatMapper->method('countAll')->willReturn(0);

		$status = $this->service()->remove();

		$this->assertNull($status['state']);
	}

	public function testRemoveRollsBackOnFailure(): void
	{
		$this->db->expects($this->once())->method('rollBack');
		$this->seatMapper->method('deleteAll')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(\RuntimeException::class);
		$this->service()->remove();
	}

	// ── seats ───────────────────────────────────────────────────────────

	public function testListSeatsMarksOverLimitAndPaginates(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2027-12-31', 2));
		$this->seatMapper->method('findAllRanked')->willReturn([
			$this->seat(1, 'bob', 100),
			$this->seat(2, 'alice', 200),
			$this->seat(3, 'carol', 300),
		]);
		$this->userManager->method('get')->willReturn(null);

		$svc = $this->service();
		$all = $svc->listSeats();

		$this->assertSame(3, $all['total']);
		$this->assertTrue($all['data'][0]['withinLimit']);
		$this->assertTrue($all['data'][1]['withinLimit']);
		$this->assertFalse($all['data'][2]['withinLimit'], 'Third seat of a 2-seat licence is over limit');
		$this->assertSame('bob', $all['data'][0]['displayName'], 'Falls back to uid without user record');

		$page = $svc->listSeats(1, 1);
		$this->assertCount(1, $page['data']);
		$this->assertSame('alice', $page['data'][0]['uid']);
		$this->assertSame(3, $page['total']);
	}

	public function testListSeatsClampsPathologicalPagination(): void
	{
		$this->stateMapper->method('findSingleton')->willReturn(null);
		$this->seatMapper->method('findAllRanked')->willReturn([]);

		$result = $this->service()->listSeats(-5, -9);

		$this->assertSame(1, $result['limit']);
		$this->assertSame(0, $result['offset']);
	}

	public function testAssignSeatRejectsUnknownUser(): void
	{
		$this->userManager->method('userExists')->willReturn(false);

		try {
			$this->service()->assignSeat('admin', 'ghost');
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('UNKNOWN_USER', $e->getErrorCode());
		}
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function nonStringUserIds(): array
	{
		return [
			'null' => [null],
			'int' => [42],
			'array' => [['alice']],
			'blank' => ['   '],
		];
	}

	/** @dataProvider nonStringUserIds */
	public function testAssignSeatRejectsNonStringUserId(mixed $userId): void
	{
		try {
			$this->service()->assignSeat('admin', $userId);
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('UNKNOWN_USER', $e->getErrorCode());
		}
	}

	public function testAssignSeatIsIdempotentForExistingSeat(): void
	{
		$this->userManager->method('userExists')->willReturn(true);
		$existing = $this->seat(1, 'alice', 100);
		$this->seatMapper->method('findByUid')->willReturn($existing);
		$this->seatMapper->method('findAllRanked')->willReturn([$existing]);
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState());
		$this->seatMapper->expects($this->never())->method('insert');

		$result = $this->service()->assignSeat('admin', 'alice');

		$this->assertFalse($result['created']);
		$this->assertSame('alice', $result['seat']['uid']);
	}

	public function testAssignSeatEnforcesLimit(): void
	{
		$this->userManager->method('userExists')->willReturn(true);
		$this->seatMapper->method('findByUid')->willReturn(null);
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2027-12-31', 1));
		$this->seatMapper->method('countAll')->willReturn(1);

		try {
			$this->service()->assignSeat('admin', 'alice');
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('SEAT_LIMIT_REACHED', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testAssignSeatWithoutLicenseRejectsClearly(): void
	{
		$this->userManager->method('userExists')->willReturn(true);
		$this->seatMapper->method('findByUid')->willReturn(null);
		$this->stateMapper->method('findSingleton')->willReturn(null);
		$this->seatMapper->expects($this->never())->method('countAll');

		try {
			$this->service()->assignSeat('admin', 'alice');
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('NO_LICENSE', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testAssignSeatCreatesSeatWithinLock(): void
	{
		$this->userManager->method('userExists')->willReturn(true);
		$this->seatMapper->method('findByUid')->willReturn(null);
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState('2027-12-31', 3));
		$this->seatMapper->method('countAll')->willReturn(1);
		$this->locking->expects($this->once())->method('acquireLock');
		$this->locking->expects($this->once())->method('releaseLock');

		$this->seatMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (MobileSeat $seat): MobileSeat {
				$seat->setId(9);
				return $seat;
			});
		$this->seatMapper->method('findAllRanked')->willReturnCallback(
			fn (): array => [$this->seat(9, 'alice', 1_784_000_000)],
		);

		$result = $this->service()->assignSeat('admin', ' alice ');

		$this->assertTrue($result['created']);
		$this->assertSame('alice', $result['seat']['uid'], 'userId must be trimmed');
		$this->assertSame('admin', $result['seat']['assignedBy']);
		$this->assertTrue($result['seat']['withinLimit']);
	}

	public function testAssignSeatSurvivesUniqueConstraintRace(): void
	{
		$this->userManager->method('userExists')->willReturn(true);
		$existing = $this->seat(4, 'alice', 500);
		$calls = 0;
		// First check (pre-lock) and second check (in-lock) miss; the insert
		// hits the unique index because a rival request won the race.
		$this->seatMapper->method('findByUid')->willReturnCallback(
			function () use (&$calls, $existing): ?MobileSeat {
				$calls++;
				return $calls >= 3 ? $existing : null;
			},
		);
		$this->stateMapper->method('findSingleton')->willReturn($this->storedState());
		$this->seatMapper->method('countAll')->willReturn(0);
		$this->seatMapper->method('insert')->willThrowException(
			$this->createMock(\OCP\DB\Exception::class),
		);
		$this->seatMapper->method('findAllRanked')->willReturn([$existing]);

		$result = $this->service()->assignSeat('admin', 'alice');

		$this->assertFalse($result['created']);
		$this->assertSame('alice', $result['seat']['uid']);
	}

	public function testContendedLockSurfacesBusyConflictAfterRetries(): void
	{
		$this->locking->method('acquireLock')
			->willThrowException(new \OCP\Lock\LockedException('customercheck/license_apply'));
		$this->locking->expects($this->never())->method('releaseLock');

		try {
			$this->service()->apply('admin', Cuc2TestSigning::validKey());
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('LICENSE_BUSY', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testLockRetrySucceedsAfterTransientContention(): void
	{
		$attempts = 0;
		$this->locking->method('acquireLock')->willReturnCallback(
			function () use (&$attempts): void {
				if (++$attempts < 3) {
					throw new \OCP\Lock\LockedException('customercheck/license_apply');
				}
			},
		);
		$this->locking->expects($this->once())->method('releaseLock');
		$this->stateMapper->method('findSingleton')->willReturn(null);
		$this->seatMapper->method('countAll')->willReturn(0);
		$this->stateMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->service()->apply('admin', Cuc2TestSigning::validKey());

		$this->assertSame(3, $attempts);
	}

	public function testRemoveSeatDeletes(): void
	{
		$seat = $this->seat(1, 'alice', 100);
		$this->seatMapper->method('findByUid')->with('alice')->willReturn($seat);
		$this->seatMapper->expects($this->once())->method('delete')->with($seat);

		$this->service()->removeSeat('alice');
	}

	public function testRemoveSeatNotFoundIs404(): void
	{
		$this->seatMapper->method('findByUid')->willReturn(null);

		try {
			$this->service()->removeSeat('ghost');
			$this->fail('Expected LicenseException');
		} catch (LicenseException $e) {
			$this->assertSame('SEAT_NOT_FOUND', $e->getErrorCode());
			$this->assertSame(404, $e->getHttpStatus());
		}
	}
}
