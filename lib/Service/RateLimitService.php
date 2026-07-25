<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

/**
 * Simple sliding-window counter stored in app config (v1 scale ≤10 users / webhook).
 */
class RateLimitService
{
	public function __construct(
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	public function allow(string $bucket, int $limit, int $windowSeconds): bool
	{
		// AppConfig keys are capped at 64 chars on Nextcloud — keep a short digest.
		$key = 'rl_' . substr(hash('sha256', $bucket), 0, 40);
		$now = $this->timeFactory->getTime();
		$raw = $this->config->getAppValue(Application::APP_ID, $key, '');
		$decoded = $raw !== '' ? json_decode($raw, true) : null;
		$windowStart = is_array($decoded) ? (int)($decoded['start'] ?? 0) : 0;
		$count = is_array($decoded) ? (int)($decoded['count'] ?? 0) : 0;

		if ($windowStart === 0 || ($now - $windowStart) >= $windowSeconds) {
			$windowStart = $now;
			$count = 0;
		}
		$count++;
		$this->config->setAppValue(Application::APP_ID, $key, json_encode([
			'start' => $windowStart,
			'count' => $count,
		], JSON_THROW_ON_ERROR));

		return $count <= $limit;
	}
}
