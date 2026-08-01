<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\AccessControlService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Portfolio §2.1 — dedicated App Admin (BudgetCheck OR-semantics).
 */
class AccessControlServiceTest extends TestCase
{
	public function testEmptyUidCannotUseApp(): void
	{
		$service = $this->makeService();
		$this->assertFalse($service->canUseApp(''));
		$this->assertFalse($service->isAppAdmin(''));
	}

	public function testSystemAdminIsAppAdminWhenListEmpty(): void
	{
		$service = $this->makeService(systemAdmins: ['admin'], appAdmins: []);
		$this->assertTrue($service->isAppAdmin('admin'));
		$this->assertTrue($service->canUseApp('admin'));
		$this->assertTrue($service->canManageAppConfiguration('admin'));
	}

	public function testSystemAdminRemainsAppAdminEvenWhenNotOnDedicatedList(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin', 'other-admin'],
			appAdmins: ['colleague'],
			restriction: true,
			allowedUsers: [],
		);
		$this->assertTrue($service->isAppAdmin('admin'));
		$this->assertTrue($service->isAppAdmin('other-admin'));
		$this->assertTrue($service->canManageAppConfiguration('other-admin'));
		$this->assertTrue($service->canUseApp('other-admin'));
	}

	public function testDedicatedNonSystemAdminOnListIsAppAdmin(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			appAdmins: ['colleague'],
			restriction: true,
			allowedUsers: [],
		);
		$this->assertTrue($service->isAppAdmin('colleague'));
		$this->assertTrue($service->canManageAppConfiguration('colleague'));
		$this->assertTrue($service->canUseApp('colleague'));
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
		$this->assertFalse($service->isAppAdmin('colleague'));
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
		$this->assertTrue($service->canUseApp('colleague'));
		$this->assertFalse($service->isFailClosedWithEmptyAllowLists());
	}

	public function testDenialReasonForRestrictedUser(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			restriction: true,
			allowedUsers: ['other'],
		);
		$this->assertSame(
			AccessControlService::DENIAL_RESTRICTION,
			$service->denialReasonWhenCannotUseApp('colleague'),
		);
	}

	public function testDenialReasonForEmptyUser(): void
	{
		$service = $this->makeService(restriction: false);
		$this->assertSame(
			AccessControlService::DENIAL_EMPTY,
			$service->denialReasonWhenCannotUseApp(''),
		);
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

	public function testListNotifiableIncludesDedicatedAdminsAndSystemAdmins(): void
	{
		$adminUser = $this->createMock(IUser::class);
		$adminUser->method('getUID')->willReturn('admin');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$adminUser]);

		$service = $this->makeService(
			systemAdmins: ['admin'],
			appAdmins: ['colleague'],
			restriction: true,
			allowedUsers: ['sales-rep'],
			adminGroup: $adminGroup,
		);
		$uids = $service->listNotifiableUserIds();
		$this->assertContains('admin', $uids);
		$this->assertContains('colleague', $uids);
		$this->assertContains('sales-rep', $uids);
	}

	public function testListNotifiableExpandsAllowedGroupsAndDedupesSorted(): void
	{
		$adminUser = $this->createMock(IUser::class);
		$adminUser->method('getUID')->willReturn('admin');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$adminUser]);

		$salesA = $this->createMock(IUser::class);
		$salesA->method('getUID')->willReturn('sales-a');
		$salesB = $this->createMock(IUser::class);
		$salesB->method('getUID')->willReturn('sales-b');
		$salesEmpty = $this->createMock(IUser::class);
		$salesEmpty->method('getUID')->willReturn('');
		$salesGroup = $this->createMock(IGroup::class);
		$salesGroup->method('getUsers')->willReturn([$salesA, $salesB, $salesEmpty]);

		$service = $this->makeService(
			systemAdmins: ['admin'],
			appAdmins: ['sales-a'], // also in group → must dedupe
			restriction: true,
			allowedUsers: ['sales-b', ''], // empty uid filtered
			allowedGroups: ['sales', 'missing-group'],
			adminGroup: $adminGroup,
			extraGroups: ['sales' => $salesGroup],
		);
		$uids = $service->listNotifiableUserIds();
		$this->assertSame(['admin', 'sales-a', 'sales-b'], $uids);
	}

	public function testJsonAllowListTrimsWhitespaceAndDedupes(): void
	{
		$service = $this->makeService(
			systemAdmins: ['admin'],
			restriction: true,
			allowedUsers: ['  colleague  ', 'colleague', 42],
		);
		$this->assertTrue($service->canUseApp('colleague'));
		$this->assertTrue($service->canUseApp('42'));
		$this->assertSame(['colleague', '42'], $service->getAllowedUserIds());
	}

	public function testJsonAllowListIgnoresNonScalarEntries(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				return match ($key) {
					AccessControlService::KEY_ACCESS_RESTRICTION => '1',
					AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS => json_encode(
						[['nested'], true, null, 'ok-user'],
						JSON_THROW_ON_ERROR,
					),
					AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS => '[]',
					AccessControlService::KEY_APP_ADMINS => '[]',
					default => $default,
				};
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$session = $this->createMock(IUserSession::class);
		$service = new AccessControlService($config, $groups, $session);
		$this->assertSame(['ok-user'], $service->getAllowedUserIds());
		$this->assertTrue($service->canUseApp('ok-user'));
		$this->assertFalse($service->canUseApp('nested'));
	}

	/**
	 * @param list<string|int> $systemAdmins
	 * @param list<string|int> $appAdmins
	 * @param list<string|int> $allowedUsers
	 * @param list<string|int> $allowedGroups
	 * @param array<string, list<string>> $groupMemberships
	 * @param array<string, IGroup> $extraGroups
	 */
	private function makeService(
		array $systemAdmins = ['admin'],
		array $appAdmins = [],
		bool $restriction = true,
		array $allowedUsers = [],
		array $allowedGroups = [],
		array $groupMemberships = [],
		?IGroup $adminGroup = null,
		array $extraGroups = [],
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
		$groups->method('get')->willReturnCallback(
			static function (string $gid) use ($adminGroup, $extraGroups): ?IGroup {
				if ($gid === 'admin') {
					return $adminGroup;
				}

				return $extraGroups[$gid] ?? null;
			}
		);

		$session = $this->createMock(IUserSession::class);

		return new AccessControlService($config, $groups, $session);
	}
}
