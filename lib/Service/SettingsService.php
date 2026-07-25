<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCP\IConfig;
use OCP\IURLGenerator;
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
				throw new \OCA\CustomerCheck\Exception\ValidationException(
					Constants::ERR_VALIDATION,
					'Turning off access restriction requires an explicit confirmation checkbox.',
					['confirmOpenAccess' => 'Confirmation required'],
				);
			}
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
		$key = $which === 'lead' ? Constants::CONFIG_LEAD_TOKEN : Constants::CONFIG_ICS_TOKEN;
		$token = $this->generateSecretToken();
		$this->config->setAppValue(Application::APP_ID, $key, $token);
		return $token;
	}

	public function ensureTokensExist(): void
	{
		foreach ([Constants::CONFIG_ICS_TOKEN, Constants::CONFIG_LEAD_TOKEN] as $key) {
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
