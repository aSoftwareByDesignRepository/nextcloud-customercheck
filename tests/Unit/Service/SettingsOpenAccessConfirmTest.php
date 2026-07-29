<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class SettingsOpenAccessConfirmTest extends TestCase
{
	public function testDisablingRestrictionWithoutConfirmThrows(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, ?string $default = null): string {
				return match ($key) {
					Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '1',
					Constants::CONFIG_ICS_TOKEN, Constants::CONFIG_LEAD_TOKEN => str_repeat('a', 64),
					default => (string)($default ?? Constants::CONFIG_DEFAULTS[$key] ?? ''),
				};
			}
		);
		$access = $this->createMock(AccessControlService::class);
		$access->method('canManageAppConfiguration')->with('admin')->willReturn(true);
		$svc = new SettingsService(
			$config,
			$access,
			$this->createMock(ISecureRandom::class),
			$this->createMock(IURLGenerator::class),
		);

		$this->expectException(ValidationException::class);
		$svc->update('admin', [Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => false]);
	}

	public function testDisablingRestrictionWithConfirmPersists(): void
	{
		$stored = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, ?string $default = null) use (&$stored): string {
				if (isset($stored[$key])) {
					return $stored[$key];
				}
				return match ($key) {
					Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '1',
					Constants::CONFIG_ICS_TOKEN, Constants::CONFIG_LEAD_TOKEN => str_repeat('c', 64),
					default => (string)($default ?? Constants::CONFIG_DEFAULTS[$key] ?? '[]'),
				};
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);
		$config->method('getUserValue')->willReturn('table');

		$access = $this->createMock(AccessControlService::class);
		$access->method('canManageAppConfiguration')->with('admin')->willReturn(true);
		$access->method('isFailClosedWithEmptyAllowLists')->willReturn(false);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/feed');

		$svc = new SettingsService($config, $access, $this->createMock(ISecureRandom::class), $url);
		$result = $svc->update('admin', [
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => false,
			'confirmOpenAccess' => true,
		]);

		$this->assertSame('0', $stored[Constants::CONFIG_ACCESS_RESTRICTION_ENABLED] ?? null);
		$this->assertFalse($result[Constants::CONFIG_ACCESS_RESTRICTION_ENABLED]);
		$this->assertSame(Application::APP_ID, Application::APP_ID);
	}
}
