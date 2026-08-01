<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\SettingsService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

final class SettingsServiceAppAdminValidationTest extends TestCase
{
	public function testUnknownAppAdminRejected(): void
	{
		$svc = $this->service(
			canManage: true,
			isSystemAdmin: true,
			isAppAdmin: true,
			users: [],
		);
		$this->expectException(ValidationException::class);
		$svc->update('admin', [
			Constants::CONFIG_APP_ADMIN_USER_IDS => ['ghost'],
		]);
	}

	public function testDisabledAppAdminRejected(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$svc = $this->service(
			canManage: true,
			isSystemAdmin: true,
			isAppAdmin: true,
			users: ['bob' => $user],
		);
		$this->expectException(ValidationException::class);
		$svc->update('admin', [
			Constants::CONFIG_APP_ADMIN_USER_IDS => ['bob'],
		]);
	}

	public function testDelegatedAdminCannotClearListWhileRemovingSelf(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$svc = $this->service(
			canManage: true,
			isSystemAdmin: false,
			isAppAdmin: true,
			users: ['colleague' => $user],
			actor: 'colleague',
		);
		$this->expectException(ValidationException::class);
		$svc->update('colleague', [
			Constants::CONFIG_APP_ADMIN_USER_IDS => [],
		]);
	}

	public function testValidDedicatedAdminPersists(): void
	{
		$stored = [];
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$svc = $this->service(
			canManage: true,
			isSystemAdmin: true,
			isAppAdmin: true,
			users: ['colleague' => $user],
			stored: $stored,
		);
		$svc->update('admin', [
			Constants::CONFIG_APP_ADMIN_USER_IDS => ['colleague'],
		]);
		$this->assertSame(
			'["colleague"]',
			$stored[Constants::CONFIG_APP_ADMIN_USER_IDS] ?? null,
		);
	}

	/**
	 * @param array<string, IUser> $users
	 * @param array<string, string> $stored
	 */
	private function service(
		bool $canManage,
		bool $isSystemAdmin,
		bool $isAppAdmin,
		array $users,
		string $actor = 'admin',
		array &$stored = [],
	): SettingsService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, ?string $default = null) use (&$stored): string {
				if (isset($stored[$key])) {
					return $stored[$key];
				}
				return match ($key) {
					Constants::CONFIG_ICS_TOKEN, Constants::CONFIG_LEAD_TOKEN, Constants::CONFIG_ORDER_TOKEN => str_repeat('a', 64),
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
		$access->method('canManageAppConfiguration')->with($actor)->willReturn($canManage);
		$access->method('isSystemAdmin')->willReturn($isSystemAdmin);
		$access->method('isAppAdmin')->willReturn($isAppAdmin);
		$access->method('isFailClosedWithEmptyAllowLists')->willReturn(false);

		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturnCallback(
			static function (string $uid) use ($users): ?IUser {
				return $users[$uid] ?? null;
			}
		);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/x');

		return new SettingsService(
			$config,
			$access,
			$this->createMock(ISecureRandom::class),
			$url,
			$um,
		);
	}
}
