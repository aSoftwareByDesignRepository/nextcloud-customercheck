<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Db\LicenseState;
use OCA\CustomerCheck\Db\LicenseStateMapper;
use OCA\CustomerCheck\Db\MobileSeat;
use OCA\CustomerCheck\Db\MobileSeatMapper;
use OCA\CustomerCheck\Exception\LicenseException;
use OCA\CustomerCheck\Exception\MobileGateException;
use OCA\CustomerCheck\License\Cuc2Codec;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * CUC2 singleton license + named mobile seats (COMPANION-APP §5).
 *
 * The web UI is never gated (core plan D2 / companion AC1): this service only
 * feeds the Settings → License tab and the `/api/mobile/*` gate. Missing or
 * expired licenses degrade the companion app, never the browser.
 */
class LicenseService
{
	public const MOBILE_APP_STATUS = 'available';

	private const SEAT_LOCK = 'customercheck/seat_assign';
	private const LICENSE_LOCK = 'customercheck/license_apply';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LicenseStateMapper $licenseState,
		private readonly MobileSeatMapper $seats,
		private readonly ITimeFactory $timeFactory,
		private readonly IUserManager $userManager,
		private readonly ILockingProvider $locking,
		private readonly IL10N $l10n,
	) {
	}

	/** @return array<string, mixed> */
	public function status(): array
	{
		$state = $this->licenseState->findSingleton();
		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		$valid = $state !== null && Cuc2Codec::isValidOn($state->getValidUntil(), $today);
		$daysLeft = null;
		$expiresSoon = false;
		if ($state !== null) {
			$until = \DateTimeImmutable::createFromFormat('!Y-m-d', $state->getValidUntil());
			$todayDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
			if ($until !== false && $todayDt !== false) {
				$daysLeft = (int)$todayDt->diff($until)->format('%r%a');
				$expiresSoon = $daysLeft >= 0 && $daysLeft <= 30;
			}
		}

		return [
			'state' => $state === null ? null : [
				'customerId' => $state->getCustomerId(),
				'issuedAt' => $state->getIssuedAt(),
				'validUntil' => $state->getValidUntil(),
				'mobileSeats' => $state->getMobileSeats(),
				'valid' => $valid,
				'expiresSoon' => $expiresSoon,
				'daysRemaining' => $daysLeft,
				'appliedAt' => $state->getAppliedAt(),
				'appliedBy' => $state->getAppliedBy(),
			],
			'seats' => [
				'assigned' => $this->seats->countAll(),
				'limit' => $state?->getMobileSeats() ?? 0,
			],
			'envelope' => $this->buildEnvelope(),
			'mobileAppStatus' => self::MOBILE_APP_STATUS,
		];
	}

	/**
	 * Signed envelope for the official companion (client-side trust anchor).
	 *
	 * @return array{format: string, payloadB64: string, signatureB64: string}|null
	 */
	public function buildEnvelope(): ?array
	{
		$state = $this->licenseState->findSingleton();
		if ($state === null) {
			return null;
		}
		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		if (!Cuc2Codec::isValidOn($state->getValidUntil(), $today)) {
			return null;
		}

		return [
			'format' => Cuc2Codec::FORMAT,
			'payloadB64' => $state->getPayloadB64(),
			'signatureB64' => $state->getSignatureB64(),
		];
	}

	/**
	 * Mobile gate inputs for one user (companion §5.3 ladder rungs 3–6).
	 *
	 * @return array{hasLicense: bool, licenseValid: bool, seatAssigned: bool, seatWithinLimit: bool,
	 *               payloadB64: ?string, signatureB64: ?string}
	 */
	public function gateState(string $uid): array
	{
		$state = $this->licenseState->findSingleton();
		$seat = $this->seats->findByUid($uid);
		$withinLimit = false;
		if ($seat !== null && $state !== null) {
			$rankInput = array_map(
				static fn (MobileSeat $s): array => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
				$this->seats->findAllRanked(),
			);
			$withinLimit = SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $state->getMobileSeats());
		}
		$today = $this->timeFactory->getDateTime()->format('Y-m-d');

		return [
			'hasLicense' => $state !== null,
			'licenseValid' => $state !== null && Cuc2Codec::isValidOn($state->getValidUntil(), $today),
			'seatAssigned' => $seat !== null,
			'seatWithinLimit' => $withinLimit,
			'payloadB64' => $state?->getPayloadB64(),
			'signatureB64' => $state?->getSignatureB64(),
		];
	}

	/**
	 * Gate for `/api/mobile/*` data routes. Web UI stays ungated.
	 * Error codes are the COMPANION-APP §9.2 contract.
	 *
	 * @throws MobileGateException
	 */
	public function assertMobileAccess(string $uid): void
	{
		$state = $this->gateState($uid);
		if (!$state['hasLicense']) {
			throw new MobileGateException(
				'LICENSE_REQUIRED',
				$this->l10n->t('CustomerCheck Mobile needs a CUC2 license. Ask your administrator.'),
			);
		}
		if (!$state['licenseValid']) {
			throw new MobileGateException(
				'LICENSE_EXPIRED',
				$this->l10n->t('The CustomerCheck Mobile license has expired. Ask your administrator to renew it.'),
			);
		}
		if (!$state['seatAssigned'] || !$state['seatWithinLimit']) {
			throw new MobileGateException(
				'NO_MOBILE_SEAT',
				$this->l10n->t('Your IT must assign you a CustomerCheck Mobile seat.'),
			);
		}
	}

	/** @return array<string, mixed> */
	public function apply(string $uid, string $wireKey): array
	{
		$error = Cuc2Codec::classifyError($wireKey);
		if ($error !== '') {
			$message = match ($error) {
				Cuc2Codec::ERROR_INVALID_FORMAT => $this->l10n->t('The key does not have the expected CUC2.<payload>.<signature> shape.'),
				Cuc2Codec::ERROR_INVALID_SIGNATURE => $this->l10n->t('The signature does not match — the key was altered or not issued for CustomerCheck.'),
				default => $this->l10n->t('The key payload failed validation for CustomerCheck.'),
			};
			throw new LicenseException('LICENSE_INVALID', $message);
		}

		/** @var array{payload: array<string, mixed>, payloadB64: string, signatureB64: string} $verified */
		$verified = Cuc2Codec::parseAndVerify($wireKey);
		$payload = $verified['payload'];

		return $this->withExclusiveLock(
			self::LICENSE_LOCK,
			'LICENSE_BUSY',
			$this->l10n->t('Another license update is in progress. Try again in a moment.'),
			409,
			function () use ($uid, $payload, $verified): array {
				$state = new LicenseState();
				$state->setCustomerId((string)$payload['customerId']);
				$state->setIssuedAt((string)$payload['issuedAt']);
				$state->setValidUntil((string)$payload['validUntil']);
				$state->setMobileSeats((int)$payload['mobileSeats']);
				$state->setPayloadB64($verified['payloadB64']);
				$state->setSignatureB64($verified['signatureB64']);
				$state->setAppliedAt($this->timeFactory->getTime());
				$state->setAppliedBy($uid);

				$this->db->beginTransaction();
				try {
					$this->licenseState->deleteAll();
					$this->licenseState->insert($state);
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}

				return $this->status();
			},
		);
	}

	/** @return array<string, mixed> */
	public function remove(): array
	{
		return $this->withExclusiveLock(
			self::LICENSE_LOCK,
			'LICENSE_BUSY',
			$this->l10n->t('Another license update is in progress. Try again in a moment.'),
			409,
			function (): array {
				$this->db->beginTransaction();
				try {
					$this->seats->deleteAll();
					$this->licenseState->deleteAll();
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}

				return $this->status();
			},
		);
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function listSeats(int $limit = 50, int $offset = 0): array
	{
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$ranked = $this->seats->findAllRanked();
		$seatLimit = $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;
		$rankInput = array_map(
			static fn (MobileSeat $s): array => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
			$ranked,
		);
		$rows = [];
		foreach ($ranked as $seat) {
			$rows[] = [
				'uid' => $seat->getUid(),
				'displayName' => $this->userManager->get($seat->getUid())?->getDisplayName() ?? $seat->getUid(),
				'assignedAt' => $seat->getAssignedAt(),
				'assignedBy' => $seat->getAssignedBy(),
				'withinLimit' => SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $seatLimit),
			];
		}

		return [
			'data' => array_slice($rows, $offset, $limit),
			'total' => count($rows),
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * @return array{created: bool, seat: array<string, mixed>}
	 */
	public function assignSeat(string $adminUid, mixed $userId): array
	{
		if (!is_string($userId) || trim($userId) === '') {
			throw new LicenseException('UNKNOWN_USER', $this->l10n->t('This Nextcloud user does not exist.'));
		}
		$userId = trim($userId);
		if (!$this->userManager->userExists($userId)) {
			throw new LicenseException('UNKNOWN_USER', $this->l10n->t('This Nextcloud user does not exist.'));
		}
		$existing = $this->seats->findByUid($userId);
		if ($existing !== null) {
			return ['created' => false, 'seat' => $this->seatRow($existing)];
		}

		return $this->withExclusiveLock(
			self::SEAT_LOCK,
			'SEAT_BUSY',
			$this->l10n->t('Another seat update is in progress. Try again in a moment.'),
			409,
			function () use ($adminUid, $userId): array {
				$existing = $this->seats->findByUid($userId);
				if ($existing !== null) {
					return ['created' => false, 'seat' => $this->seatRow($existing)];
				}
				$state = $this->licenseState->findSingleton();
				if ($state === null) {
					throw new LicenseException(
						'NO_LICENSE',
						$this->l10n->t('Apply a CustomerCheck Mobile license before assigning seats.'),
						409,
					);
				}
				$limit = (int)$state->getMobileSeats();
				if ($limit <= 0 || $this->seats->countAll() >= $limit) {
					throw new LicenseException(
						'SEAT_LIMIT_REACHED',
						$this->l10n->t('All licensed seats are assigned. Remove a seat or upgrade the license.'),
						409,
					);
				}
				$seat = new MobileSeat();
				$seat->setUid($userId);
				$seat->setAssignedAt($this->timeFactory->getTime());
				$seat->setAssignedBy($adminUid);
				try {
					$seat = $this->seats->insert($seat);
				} catch (\OCP\DB\Exception) {
					// Unique index on uid absorbed a concurrent assign — treat as idempotent.
					$existing = $this->seats->findByUid($userId);
					if ($existing !== null) {
						return ['created' => false, 'seat' => $this->seatRow($existing)];
					}
					throw new LicenseException('SEAT_ASSIGN_FAILED', $this->l10n->t('Could not assign the seat.'));
				}

				return ['created' => true, 'seat' => $this->seatRow($seat)];
			},
		);
	}

	public function removeSeat(string $uid): void
	{
		$seat = $this->seats->findByUid($uid);
		if ($seat === null) {
			throw new LicenseException('SEAT_NOT_FOUND', $this->l10n->t('Seat not found.'), 404);
		}
		$this->seats->delete($seat);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function seatRow(MobileSeat $seat): array
	{
		$limit = $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;
		$ranked = $this->seats->findAllRanked();
		$rankInput = array_map(
			static fn (MobileSeat $s): array => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
			$ranked,
		);

		return [
			'uid' => $seat->getUid(),
			'displayName' => $this->userManager->get($seat->getUid())?->getDisplayName() ?? $seat->getUid(),
			'assignedAt' => $seat->getAssignedAt(),
			'assignedBy' => $seat->getAssignedBy(),
			'withinLimit' => SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $limit),
		];
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withExclusiveLock(
		string $key,
		string $busyCode,
		string $busyMessage,
		int $busyStatus,
		callable $fn,
	): mixed {
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (++$attempts >= 40) {
					throw new LicenseException($busyCode, $busyMessage, $busyStatus);
				}
				usleep(25_000);
			}
		}
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
