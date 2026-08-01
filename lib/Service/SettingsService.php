<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * AppConfig facade — PSD §4.9.3 / §10.3.
 */
class SettingsService
{
	public function __construct(
		private readonly IConfig $config,
		private readonly AccessControlService $accessControl,
		private readonly ISecureRandom $secureRandom,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getForUser(string $userId): array
	{
		$this->ensureTokensExist();
		$canManage = $this->accessControl->canManageAppConfiguration($userId);
		$data = [];
		foreach (Constants::CONFIG_DEFAULTS as $key => $default) {
			$value = $this->config->getAppValue(Application::APP_ID, $key, $default);
			if (in_array($key, Constants::SECRET_CONFIG_KEYS, true) && !$canManage) {
				$data[$key] = null;
				continue;
			}
			$data[$key] = $this->decodeValue($key, $value);
		}
		$data['tokensVisible'] = $canManage;
		$data['pipelineView'] = $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			Constants::USER_CONFIG_PIPELINE_VIEW,
			'table',
		);
		$data['failClosedEmptyAllowlists'] = $this->accessControl->isFailClosedWithEmptyAllowLists();
		$data['icsFeedUrl'] = $canManage
			? $this->urlGenerator->linkToRouteAbsolute(
				'customercheck.feed.renewals',
				['token' => (string)$data[Constants::CONFIG_ICS_TOKEN]],
			)
			: null;
		$data['leadWebhookUrl'] = $canManage
			? $this->urlGenerator->linkToRouteAbsolute(
				'customercheck.lead_webhook.receive',
				['token' => (string)$data[Constants::CONFIG_LEAD_TOKEN]],
			)
			: null;
		$data['orderWebhookUrl'] = $canManage
			? $this->urlGenerator->linkToRouteAbsolute(
				'customercheck.website_order_webhook.receive',
				['token' => (string)$data[Constants::CONFIG_ORDER_TOKEN]],
			)
			: null;

		return $data;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function update(string $userId, array $payload): array
	{
		if (!$this->accessControl->canManageAppConfiguration($userId)) {
			throw new AccessDeniedException();
		}

		// Turning restriction off is a high-risk sales-data decision (§2.3).
		if (array_key_exists(Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, $payload)) {
			$wantRestriction = $this->truthy($payload[Constants::CONFIG_ACCESS_RESTRICTION_ENABLED]);
			if (!$wantRestriction && empty($payload['confirmOpenAccess'])) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					'Turning off access restriction requires an explicit confirmation checkbox.',
					['confirmOpenAccess' => 'Confirmation required'],
				);
			}
		}

		if (array_key_exists(Constants::CONFIG_APP_ADMIN_USER_IDS, $payload)) {
			$payload[Constants::CONFIG_APP_ADMIN_USER_IDS] = $this->normalizeAppAdminUserIds(
				$userId,
				$payload[Constants::CONFIG_APP_ADMIN_USER_IDS],
			);
		}

		$writable = array_keys(Constants::CONFIG_DEFAULTS);
		foreach ($writable as $key) {
			if (!array_key_exists($key, $payload)) {
				continue;
			}
			if (in_array($key, Constants::SECRET_CONFIG_KEYS, true)) {
				continue; // rotate endpoints only
			}
			$this->config->setAppValue(Application::APP_ID, $key, $this->encodeValue($key, $payload[$key]));
		}

		return $this->getForUser($userId);
	}

	/**
	 * Dedicated App Admins may be any enabled Nextcloud user (portfolio §2.1).
	 * Delegated (non–system-admin) operators cannot clear the list while removing themselves.
	 *
	 * @return list<string>
	 */
	private function normalizeAppAdminUserIds(string $actorUserId, mixed $raw): array
	{
		if (!is_array($raw)) {
			throw new ValidationException(
				Constants::ERR_VALIDATION,
				'App administrators must be a list of users.',
				[Constants::CONFIG_APP_ADMIN_USER_IDS => 'Invalid list'],
			);
		}

		$normalised = [];
		foreach ($raw as $candidate) {
			if (!is_string($candidate) && !is_int($candidate)) {
				continue;
			}
			$uid = trim((string)$candidate);
			if ($uid === '' || strlen($uid) > 64) {
				continue;
			}
			$normalised[$uid] = true;
		}
		$adminIds = array_keys($normalised);

		foreach ($adminIds as $adminId) {
			$user = $this->userManager->get($adminId);
			if ($user === null) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					'One or more app administrators do not exist. Search the directory and pick again.',
					[Constants::CONFIG_APP_ADMIN_USER_IDS => 'Unknown user'],
				);
			}
			if (!$user->isEnabled()) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					'One or more app administrators are disabled accounts.',
					[Constants::CONFIG_APP_ADMIN_USER_IDS => 'Disabled user'],
				);
			}
		}

		// Delegated app admin must not wipe the dedicated list and lock themselves out.
		if (
			$actorUserId !== ''
			&& !$this->accessControl->isSystemAdmin($actorUserId)
			&& $this->accessControl->isAppAdmin($actorUserId)
		) {
			$removingSelf = !in_array($actorUserId, $adminIds, true);
			if ($removingSelf && $adminIds === []) {
				throw new ValidationException(
					Constants::ERR_VALIDATION,
					'You cannot remove your own app administrator access without assigning another administrator first.',
					[Constants::CONFIG_APP_ADMIN_USER_IDS => 'Cannot remove yourself'],
				);
			}
		}

		return $adminIds;
	}

	private function truthy(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (int)$value === 1;
		}
		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
	}

	public function updatePipelineView(string $userId, string $view): string
	{
		$view = $view === 'board' ? 'board' : 'table';
		$this->config->setUserValue($userId, Application::APP_ID, Constants::USER_CONFIG_PIPELINE_VIEW, $view);
		return $view;
	}

	public function rotateToken(string $userId, string $which): string
	{
		if (!$this->accessControl->canManageAppConfiguration($userId)) {
			throw new AccessDeniedException();
		}
		$key = match ($which) {
			'lead' => Constants::CONFIG_LEAD_TOKEN,
			'order' => Constants::CONFIG_ORDER_TOKEN,
			default => Constants::CONFIG_ICS_TOKEN,
		};
		$token = $this->generateSecretToken();
		$this->config->setAppValue(Application::APP_ID, $key, $token);
		return $token;
	}

	public function ensureTokensExist(): void
	{
		foreach ([Constants::CONFIG_ICS_TOKEN, Constants::CONFIG_LEAD_TOKEN, Constants::CONFIG_ORDER_TOKEN] as $key) {
			$existing = $this->config->getAppValue(Application::APP_ID, $key, '');
			if ($existing === '') {
				$this->config->setAppValue(
					Application::APP_ID,
					$key,
					$this->generateSecretToken(),
				);
			}
		}
	}

	/**
	 * 64-char hex token (32 CSPRNG bytes). Nextcloud's ISecureRandom has no CHAR_HEX
	 * constant on NC 32–34, so we use an explicit hex alphabet.
	 */
	private function generateSecretToken(): string
	{
		return $this->secureRandom->generate(64, ISecureRandom::CHAR_DIGITS . 'abcdef');
	}

	public function getToken(string $key): string
	{
		$this->ensureTokensExist();
		return $this->config->getAppValue(Application::APP_ID, $key, '');
	}

	public function tokenMatches(string $key, string $candidate): bool
	{
		$expected = $this->getToken($key);
		return $expected !== '' && hash_equals($expected, $candidate);
	}

	/**
	 * Absolute ICS URL for one workspace book (§15.2 multi-book).
	 * Token is independent of the Default-workspace global feed token.
	 */
	public function workspaceIcsFeedUrl(int $workspaceId): string
	{
		$token = $this->ensureWorkspaceIcsToken($workspaceId);
		if ($token === '') {
			return '';
		}

		return $this->urlGenerator->linkToRouteAbsolute(
			'customercheck.feed.workspaceRenewals',
			['workspaceId' => $workspaceId, 'token' => $token],
		);
	}

	public function ensureWorkspaceIcsToken(int $workspaceId): string
	{
		if ($workspaceId <= 0) {
			return '';
		}
		$map = $this->readWorkspaceIcsMap();
		$key = (string)$workspaceId;
		if (($map[$key] ?? '') === '') {
			$map[$key] = $this->generateSecretToken();
			$this->writeWorkspaceIcsMap($map);
		}

		return $map[$key];
	}

	public function rotateWorkspaceIcsToken(int $workspaceId): string
	{
		if ($workspaceId <= 0) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'workspaceId',
				'Choose a workspace before rotating the ICS feed.',
			);
		}
		$map = $this->readWorkspaceIcsMap();
		$token = $this->generateSecretToken();
		$map[(string)$workspaceId] = $token;
		$this->writeWorkspaceIcsMap($map);

		return $token;
	}

	public function workspaceIcsTokenMatches(int $workspaceId, string $candidate): bool
	{
		if ($workspaceId <= 0 || $candidate === '') {
			return false;
		}
		$map = $this->readWorkspaceIcsMap();
		$expected = (string)($map[(string)$workspaceId] ?? '');

		return $expected !== '' && hash_equals($expected, $candidate);
	}

	/** @return array<string, string> */
	private function readWorkspaceIcsMap(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, Constants::CONFIG_ICS_WORKSPACE_TOKENS, '{}');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $wsId => $token) {
			if (!is_string($token) && !is_int($token)) {
				continue;
			}
			$token = trim((string)$token);
			if ($token === '') {
				continue;
			}
			$out[(string)(int)$wsId] = $token;
		}

		return $out;
	}

	/** @param array<string, string> $map */
	private function writeWorkspaceIcsMap(array $map): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			Constants::CONFIG_ICS_WORKSPACE_TOKENS,
			json_encode($map, JSON_THROW_ON_ERROR),
		);
	}

	private function decodeValue(string $key, string $value): mixed
	{
		if (str_ends_with($key, '_user_ids') || str_ends_with($key, '_group_ids')) {
			$decoded = json_decode($value, true);
			return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
		}
		if (in_array($key, [
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED,
			Constants::CONFIG_DIGEST_ENABLED,
			Constants::CONFIG_CADENCE_ENABLED,
			Constants::CONFIG_MAIL_RELATED_ENABLED,
		], true)) {
			return $value === '1';
		}
		if (in_array($key, [
			Constants::CONFIG_STALE_DEAL_DAYS,
			Constants::CONFIG_DIGEST_WEEKDAY,
			Constants::CONFIG_DIGEST_HOUR,
			Constants::CONFIG_REMINDER_HOUR,
		], true)) {
			return (int)$value;
		}
		return $value;
	}

	private function encodeValue(string $key, mixed $value): string
	{
		if (str_ends_with($key, '_user_ids') || str_ends_with($key, '_group_ids')) {
			$list = is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
			return json_encode($list, JSON_THROW_ON_ERROR);
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		return (string)$value;
	}
}
