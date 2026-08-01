<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Config\VendorPublicKey;
use OCP\IUserManager;

/**
 * Local / CI dogfood: install golden CUC2 + assign a named seat.
 *
 * Never safe for production PHP-FPM — requires the test vendor-key override.
 */
class PlayReviewerSeedService
{
	public function __construct(
		private readonly LicenseService $license,
		private readonly IUserManager $users,
	) {
	}

	/**
	 * @return array{
	 *   uid: string,
	 *   customerId: string,
	 *   mobileSeats: int,
	 *   createdUser: bool,
	 *   generatedPassword: ?string
	 * }
	 */
	public function seed(
		string $uid,
		string $displayName,
		?string $password,
	): array {
		if (getenv('CUC_ALLOW_VENDOR_KEY_OVERRIDE') !== '1') {
			throw new \RuntimeException(
				'CUC_ALLOW_VENDOR_KEY_OVERRIDE=1 is required. Refusing to weaken production verification.',
			);
		}
		if (VendorPublicKey::publicKeyB64() !== VendorPublicKey::TEST_PUBLIC_KEY_B64) {
			throw new \RuntimeException('CUC_VENDOR_PUBLIC_KEY_B64 must equal the golden test public key.');
		}

		$uid = trim($uid);
		if ($uid === '' || !preg_match('/^[a-zA-Z0-9_.@-]{1,64}$/', $uid)) {
			throw new \InvalidArgumentException('Invalid uid.');
		}

		$fixturePath = dirname(__DIR__, 2) . '/tests/fixtures/license_cuc2_golden.json';
		$raw = file_get_contents($fixturePath);
		if ($raw === false) {
			throw new \RuntimeException('Missing golden fixture: ' . $fixturePath);
		}
		/** @var array{wireKey?: string} $fixture */
		$fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		$wireKey = (string)($fixture['wireKey'] ?? '');
		if ($wireKey === '' || !str_starts_with($wireKey, 'CUC2.')) {
			throw new \RuntimeException('Golden fixture wireKey is missing or malformed.');
		}

		$createdUser = false;
		$generatedPassword = null;
		$user = $this->users->get($uid);
		if ($user === null) {
			$pass = $password ?? '';
			if ($pass === '') {
				$pass = bin2hex(random_bytes(12));
				$generatedPassword = $pass;
			}
			$created = $this->users->createUser($uid, $pass);
			if ($created === false) {
				throw new \RuntimeException('Could not create user ' . $uid);
			}
			$created->setDisplayName($displayName);
			$createdUser = true;
		}

		$adminUid = $this->users->get('admin') !== null ? 'admin' : $uid;
		$status = $this->license->apply($adminUid, $wireKey);
		$this->license->assignSeat($adminUid, $uid);
		$state = is_array($status['state'] ?? null) ? $status['state'] : [];

		return [
			'uid' => $uid,
			'customerId' => (string)($state['customerId'] ?? ''),
			'mobileSeats' => (int)($state['mobileSeats'] ?? 0),
			'createdUser' => $createdUser,
			'generatedPassword' => $generatedPassword,
		];
	}
}
