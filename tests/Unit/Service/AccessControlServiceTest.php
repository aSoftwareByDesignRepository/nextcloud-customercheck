<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\AccessControlService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * PSD §2 / S9 — access matrix must stay fail-closed for sales data.
 */
class AccessControlServiceTest extends TestCase
{
	public function testEmptyUidCannotUseApp(): void
	{
		$service = $this->makeService();
		$this->assertFalse($service->canUseApp(''));
	}

	public function testSystemAdminIsAppAdminWhenListEmpty(): void
	{
		$service = $this->makeService(systemAdmins: ['admin'], appAdmins: []);
		$this->assertTrue($service->isAppAdmin('admin'));
		$this->assertTrue($service->canUseApp('admin'));
		$this->assertTrue($service->canManageAppConfiguration('admin'));
	}

	public function testNonListedSystemAdminIsNotAppAdminWhenListNonEmpty(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin', 'other-admin'],
			appAdmins: ['admin'],
			restriction: true,
			allowedUsers: [],
		);
		$this->assertTrue($service->isAppAdmin('admin'));
		$this->assertFalse($service->isAppAdmin('other-admin'));
		$this->assertFalse($service->canManageAppConfiguration('other-admin'));
		// Non-listed NC admin is also denied canUseApp when restriction is on
		// and allowlists are empty (fail-closed) — AZC app-admin rule + CRM gate.
		$this->assertFalse($service->canUseApp('other-admin'));
	}

	public function testNonSystemAdminOnAppAdminListIsNotAppAdmin(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			appAdmins: ['colleague'],
		);
		$this->assertFalse($service->isAppAdmin('colleague'));
	}

	public function testAllowlistedColleagueCanUseButNotManage(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			appAdmins: [],
			restriction: true,
			allowedUsers: ['colleague'],
		);
		$this->assertTrue($service->canUseApp('colleague'));
		$this->assertFalse($service->canManageAppConfiguration('colleague'));
	}

	public function testGroupAllowlistGrantsAccess(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			restriction: true,
			allowedGroups: ['sales'],
			groupMemberships: ['colleague' => ['sales']],
		);
		$this->assertTrue($service->canUseApp('colleague'));
	}

	public function testRestrictionOnEmptyAllowlistsFailClosed(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			restriction: true,
			allowedUsers: [],
			allowedGroups: [],
		);
		$this->assertTrue($service->isFailClosedWithEmptyAllowLists());
		$this->assertFalse($service->canUseApp('random-user'));
		$this->assertTrue($service->canUseApp('admin')); // app admin bypass
	}

	public function testRestrictionOffOpensToAnyLoggedInUser(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			restriction: false,
			allowedUsers: [],
		);
		$this->assertTrue($service->canUseApp('anyone'));
		$this->assertFalse($service->isFailClosedWithEmptyAllowLists());
	}

	public function testDeniedUserNotOnAllowlist(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			restriction: true,
			allowedUsers: ['other'],
		);
		$this->assertFalse($service->canUseApp('colleague'));
	}

	/**
	 * @param list<string> $systemAdmins
	 * @param list<string> $appAdmins
	 * @param list<string> $allowedUsers
	 * @param list<string> $allowedGroups
	 * @param array<string, list<string>> $groupMemberships
	 */
	private function makeService(
		array $systemAdmins = ['admin'],
		array $appAdmins = [],
		bool $restriction = true,
		array $allowedUsers = [],
		array $allowedGroups = [],
		array $groupMemberships = [],
	): AccessControlService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			function (string $app, string $key, string $default) use ($restriction, $appAdmins, $allowedUsers, $allowedGroups): string {
				return match ($key) {
					AccessControlService::KEY_ACCESS_RESTRICTION => $restriction ? '1' : '0',
					AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode($allowedUsers, JSON_THROW_ON_ERROR),
					AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => json_encode($allowedGroups, JSON_THROW_ON_ERROR),
					AccessControlService::KEY_APP_ADMINS => json_encode($appAdmins, JSON_THROW_ON_ERROR),
					default => $default,
				};
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, $systemAdmins, true)
		);
		$groups->method('isInGroup')->willReturnCallback(
			static function (string $uid, string $gid) use ($groupMemberships): bool {
				return in_array($gid, $groupMemberships[$uid] ?? [], true);
			}
		);

		$session = $this->createMock(IUserSession::class);

		return new AccessControlService($config, $groups, $session);
	}
}
