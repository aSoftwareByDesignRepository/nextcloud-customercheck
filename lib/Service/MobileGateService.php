<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Config\VendorPublicKey;
use OCA\CustomerCheck\Exception\MobileGateException;
use OCA\CustomerCheck\License\Cuc2Codec;

/**
 * License/seat gate for `/api/mobile/*` (COMPANION-APP §5.3, §9).
 *
 * Rungs 1 (NC auth) and 2 (canUseApp) are enforced by Nextcloud +
 * AppAccessMiddleware. This service enforces rungs 3–6:
 *   3 license row exists           → 402 LICENSE_REQUIRED
 *   4 today ≤ valid_until          → 402 LICENSE_EXPIRED
 *   5 caller has a seat            → 402 NO_MOBILE_SEAT
 *   6 seat within limit            → 402 NO_MOBILE_SEAT
 *
 * `bootstrap` skips 3–6 and reports state so the official app can render its
 * LicenseGate with honest copy instead of a blind 402.
 */
class MobileGateService
{
	public const COMPANION_API_VERSION = 2;
	public const MIN_APP_VERSION = '1.0.1';

	public function __construct(
		private readonly LicenseService $license,
		private readonly FacadeProbeService $facades,
	) {
	}

	/**
	 * @throws MobileGateException
	 */
	public function assertGatePassed(string $uid): void
	{
		$this->license->assertMobileAccess($uid);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function bootstrapPayload(string $uid, string $displayName, string $appVersion): array
	{
		$state = $this->license->gateState($uid);
		$enabledForUser = $state['seatAssigned'] && $state['seatWithinLimit'] && $state['licenseValid'];
		$status = $this->license->status();
		$expiresAt = is_array($status['state'] ?? null) ? ($status['state']['validUntil'] ?? null) : null;
		$licensing = null;
		if ($state['hasLicense'] && $state['payloadB64'] !== null && $state['signatureB64'] !== null) {
			$licensing = [
				'format' => Cuc2Codec::FORMAT,
				'payloadB64' => $state['payloadB64'],
				'signatureB64' => $state['signatureB64'],
				'envelope' => [
					'format' => Cuc2Codec::FORMAT,
					'payloadB64' => $state['payloadB64'],
					'signatureB64' => $state['signatureB64'],
				],
				// Lets the official app distinguish corrupt envelopes from app↔server key mismatch.
				'vendorPublicKeyB64' => VendorPublicKey::publicKeyB64(),
				'mobile' => [
					'enabledForUser' => $enabledForUser,
					'expiresAt' => is_string($expiresAt) ? $expiresAt : null,
				],
			];
		}

		return [
			'appId' => 'customercheck',
			'serverVersion' => $appVersion,
			'companionApi' => self::COMPANION_API_VERSION,
			'minAppVersion' => self::MIN_APP_VERSION,
			'minApp' => self::MIN_APP_VERSION,
			'capabilities' => [
				'today' => true,
				'companies' => true,
				'activities' => true,
				'deals' => true,
				'agreements' => true,
				'push' => true,
				'workspaces' => true,
				'projectcheck' => $this->facades->projectcheckAvailable(),
				'invoicecheck' => $this->facades->invoicecheckAvailable(),
				'ticketcheck' => $this->facades->ticketcheckAvailable(),
				// Companion API floor — clients fail closed to app_outdated when missing/mismatched.
				'customercheck.companion.min' => 2,
			],
			'licensing' => $licensing,
			// Raw seat membership (not the enabled composite). Clients must also honour
			// seatWithinLimit + licensing.mobile.enabledForUser for the full ladder.
			'seatAssigned' => $state['seatAssigned'],
			'seatWithinLimit' => $state['seatWithinLimit'],
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
			'user' => [
				'userId' => $uid,
				'uid' => $uid,
				'displayName' => $displayName,
			],
		];
	}
}
