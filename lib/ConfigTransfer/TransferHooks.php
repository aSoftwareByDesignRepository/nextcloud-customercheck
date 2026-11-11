<?php

declare(strict_types=1);

/**
 * CustomerCheck wiring for the shared transfer engine. Access door is
 * app-local appconfig (no platform restriction mirror → no mirrored
 * keys; the door fails closed on empty allowlists, so there is no
 * cross-key invariant needing an atomic writer).
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (AccessControlService::isAppAdmin — system admins + configured
 *    app admins, portfolio §2.1).
 *  - validateValue/normalizeValue delegate to the SAME normaliser the
 *    settings API uses — SettingsService::normalizeForWrite (NN-08).
 *  - createSafetySnapshot delegates to UpgradeBackupService (FS-2).
 */

namespace OCA\CustomerCheck\ConfigTransfer;

use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\ProductCodeRegistry;
use OCA\CustomerCheck\Service\SettingsService;
use OCA\CustomerCheck\Service\UpgradeBackupService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly AccessControlService $access,
		private readonly UpgradeBackupService $backups,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		if (!$this->access->isAppAdmin($uid)) {
			throw new AccessDeniedException();
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (ValidationException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		if ($key === 'product_codes_custom') {
			return $this->normalizeProductCodes($value);
		}
		try {
			return $this->settings->normalizeForWrite($key, $value);
		} catch (\InvalidArgumentException $e) {
			if ($e->getMessage() !== 'UNKNOWN_SETTING') {
				throw $e;
			}
			// Not a settings key — size guard only.
			return strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG');
		}
	}

	/**
	 * Same closed-pattern rule ProductCodeRegistry::setCustom enforces —
	 * a JSON list of codes matching CUSTOM_PATTERN, deduped.
	 */
	private function normalizeProductCodes(string $value): string
	{
		$list = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_PRODUCT_CODES');
		}
		$clean = [];
		foreach ($list as $code) {
			if (!is_string($code) || preg_match(ProductCodeRegistry::CUSTOM_PATTERN, $code) !== 1) {
				throw new \InvalidArgumentException('INVALID_PRODUCT_CODE');
			}
			if (!in_array($code, $clean, true)) {
				$clean[] = $code;
			}
		}
		return json_encode(array_values($clean), JSON_THROW_ON_ERROR);
	}

	public function validateMirrored(array $keyValues): ?string
	{
		return $keyValues === [] ? null : 'NO_MIRRORED_KEYS';
	}

	public function writeMirrored(array $keyValues): void
	{
		if ($keyValues !== []) {
			throw new \InvalidArgumentException('NO_MIRRORED_KEYS');
		}
	}

	public function securityRelevantKeys(): array
	{
		return [
			'access_restriction_enabled',
			'access_allowed_user_ids',
			'access_allowed_group_ids',
			'app_admin_user_ids',
			'identity_rename_push',
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// No data to back up yet (fresh instance) — not an error, but no
			// snapshot id either; the apply must not be silently unprotected
			// when tables DO exist, so rethrow real failures on non-empty DBs.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match (true) {
			str_starts_with($key, 'access_'), $key === 'app_admin_user_ids' => 'access',
			str_starts_with($key, 'ics_') || str_ends_with($key, '_token') || $key === 'vtodo_calendar_uri' => 'tokens',
			$key === 'product_codes_custom' => 'product-codes',
			str_starts_with($key, 'oauth_') => 'personal-mail',
			default => 'jobs',
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		$this->logger->info('customercheck: config transfer ' . $action, [
			'app' => 'customercheck',
			'actor' => $actor === '' ? 'cli' : $actor,
			'details' => $details,
		]);
	}
}
