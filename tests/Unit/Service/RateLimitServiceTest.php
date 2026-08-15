<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Service\RateLimitService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rate-limit window arithmetic + exclusive lock acquisition.
 */
final class RateLimitServiceTest extends TestCase
{
	public function testAllowsUpToLimitThenBlocks(): void
	{
		$config = $this->createMock(IConfig::class);
		$store = [];
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): void {
				self::assertSame(Application::APP_ID, $app);
				$store[$key] = $value;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->exactly(3))->method('acquireLock');
		$locking->expects($this->exactly(3))->method('releaseLock');

		$svc = new RateLimitService($config, $time, $locking);
		$this->assertTrue($svc->allow('lead:1.2.3.4', 2, 600));
		$this->assertTrue($svc->allow('lead:1.2.3.4', 2, 600));
		$this->assertFalse($svc->allow('lead:1.2.3.4', 2, 600));
	}

	public function testPeekDoesNotConsumeToken(): void
	{
		$config = $this->createMock(IConfig::class);
		$store = [];
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			}
		);
		$config->expects($this->never())->method('setAppValue');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock');
		$locking->expects($this->once())->method('releaseLock');

		$svc = new RateLimitService($config, $time, $locking);
		$this->assertTrue($svc->allow('mail_preview_u:alex', 5, 60, false));
	}

	public function testPeekBlocksAtCeilingWithoutWrite(): void
	{
		$config = $this->createMock(IConfig::class);
		$key = 'rl_' . substr(hash('sha256', 'mail_preview_u:alex'), 0, 40);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $k, string $default = '') use ($key): string {
				if ($k === $key) {
					return json_encode(['start' => 1_700_000_000, 'count' => 5], JSON_THROW_ON_ERROR);
				}

				return $default;
			}
		);
		$config->expects($this->never())->method('setAppValue');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$locking = $this->createMock(ILockingProvider::class);

		$svc = new RateLimitService($config, $time, $locking);
		$this->assertFalse($svc->allow('mail_preview_u:alex', 5, 60, false));
	}

	public function testExpiredPeekDeletesStaleKeyWithoutRewrite(): void
	{
		$config = $this->createMock(IConfig::class);
		$key = 'rl_' . substr(hash('sha256', 'mail_preview_u:alex'), 0, 40);
		$store = [$key => json_encode(['start' => 1_700_000_000 - 120, 'count' => 5], JSON_THROW_ON_ERROR)];
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $k, string $default = '') use (&$store): string {
				return $store[$k] ?? $default;
			}
		);
		$config->expects($this->once())->method('deleteAppValue')->with(Application::APP_ID, $key);
		$config->expects($this->never())->method('setAppValue');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$locking = $this->createMock(ILockingProvider::class);

		$svc = new RateLimitService($config, $time, $locking);
		$this->assertTrue($svc->allow('mail_preview_u:alex', 5, 60, false));
	}

	public function testPurgeExpiredKeysRemovesStaleRlEntries(): void
	{
		$config = $this->createMock(IConfig::class);
		$stale = 'rl_' . substr(hash('sha256', 'old'), 0, 40);
		$fresh = 'rl_' . substr(hash('sha256', 'new'), 0, 40);
		$store = [
			$stale => json_encode(['start' => 1_000_000_000, 'count' => 1], JSON_THROW_ON_ERROR),
			$fresh => json_encode(['start' => 1_700_000_000, 'count' => 1], JSON_THROW_ON_ERROR),
			'other' => 'x',
		];
		$config->method('getAppKeys')->willReturn(array_keys($store));
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $k, string $default = '') use (&$store): string {
				return $store[$k] ?? $default;
			}
		);
		$config->expects($this->once())->method('deleteAppValue')->with(Application::APP_ID, $stale);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$locking = $this->createMock(ILockingProvider::class);

		$svc = new RateLimitService($config, $time, $locking);
		$this->assertSame(1, $svc->purgeExpiredKeys(3600));
	}

	public function testContestedLockFailsClosed(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('getAppValue');

		$time = $this->createMock(ITimeFactory::class);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willThrowException(new \OCP\Lock\LockedException('busy'));

		$svc = new RateLimitService($config, $time, $locking);
		$this->assertFalse($svc->allow('lead:9.9.9.9', 30, 600));
	}
}
