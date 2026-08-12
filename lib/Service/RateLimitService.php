<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Sliding-window counter stored in app config (v1 scale ≤10 users / webhook).
 *
 * Exclusive lock makes the read-modify-write race-safe across PHP-FPM workers
 * (same pattern as InvoiceCheck). Without the lock, concurrent workers can all
 * observe the same count and bypass the limit.
 */
class RateLimitService
{
	private const LOCK_PREFIX = 'customercheck-rate-';

	public function __construct(
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @param bool $charge When true (default), consume one token. When false, only check whether
	 *                     a further charge would still be within the limit (no write).
	 */
	public function allow(string $bucket, int $limit, int $windowSeconds, bool $charge = true): bool
	{
		$limit = max(1, $limit);
		$windowSeconds = max(1, $windowSeconds);
		// AppConfig keys are capped at 64 chars on Nextcloud — keep a short digest.
		$key = 'rl_' . substr(hash('sha256', $bucket), 0, 40);
		$lockKey = self::LOCK_PREFIX . substr(hash('sha256', $bucket), 0, 32);

		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			usleep(50_000);
			try {
				$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (LockedException) {
				// Contested lock under load: fail closed for public hooks / session flood.
				return false;
			}
		}

		try {
			$now = $this->timeFactory->getTime();
			$raw = $this->config->getAppValue(Application::APP_ID, $key, '');
			$decoded = $raw !== '' ? json_decode($raw, true) : null;
			$windowStart = is_array($decoded) ? (int)($decoded['start'] ?? 0) : 0;
			$count = is_array($decoded) ? (int)($decoded['count'] ?? 0) : 0;

			if ($windowStart === 0 || ($now - $windowStart) >= $windowSeconds) {
				$windowStart = $now;
				$count = 0;
			}

			if (!$charge) {
				return $count < $limit;
			}

			$count++;
			$this->config->setAppValue(Application::APP_ID, $key, json_encode([
				'start' => $windowStart,
				'count' => $count,
			], JSON_THROW_ON_ERROR));

			return $count <= $limit;
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
