<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Per-workspace ICS tokens — independent of Default feed (§15.2).
 */
final class SettingsWorkspaceIcsTest extends TestCase
{
	public function testWorkspaceTokensAreIndependentPerBook(): void
	{
		$store = [Constants::CONFIG_ICS_WORKSPACE_TOKENS => '{}'];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			},
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): void {
				$store[$key] = $value;
			},
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnOnConsecutiveCalls(
			str_repeat('a', 64),
			str_repeat('b', 64),
			str_repeat('c', 64),
		);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'https://nc.test/feed/w/'
				. ($params['workspaceId'] ?? 0) . '/' . ($params['token'] ?? '') . '/renewals.ics',
		);

		$svc = new SettingsService(
			$config,
			$this->createMock(AccessControlService::class),
			$random,
			$url,
			$this->createMock(IUserManager::class),
		);

		$url1 = $svc->workspaceIcsFeedUrl(1);
		$url2 = $svc->workspaceIcsFeedUrl(2);
		$this->assertStringContainsString('/w/1/' . str_repeat('a', 64), $url1);
		$this->assertStringContainsString('/w/2/' . str_repeat('b', 64), $url2);
		$this->assertTrue($svc->workspaceIcsTokenMatches(1, str_repeat('a', 64)));
		$this->assertFalse($svc->workspaceIcsTokenMatches(1, str_repeat('b', 64)));

		$rotated = $svc->rotateWorkspaceIcsToken(1);
		$this->assertSame(str_repeat('c', 64), $rotated);
		$this->assertFalse($svc->workspaceIcsTokenMatches(1, str_repeat('a', 64)));
		$this->assertTrue($svc->workspaceIcsTokenMatches(1, str_repeat('c', 64)));
		// Book 2 unchanged
		$this->assertTrue($svc->workspaceIcsTokenMatches(2, str_repeat('b', 64)));
	}
}
