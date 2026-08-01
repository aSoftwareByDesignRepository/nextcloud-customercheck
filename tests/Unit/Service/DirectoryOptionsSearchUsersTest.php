<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Service\DirectoryOptionsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class DirectoryOptionsSearchUsersTest extends TestCase
{
	private function user(string $uid, string $display, bool $enabled = true): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($display);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	public function testShortQueryReturnsEmptyWithoutCallingDirectory(): void
	{
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('search');
		$users->expects($this->never())->method('searchDisplayName');
		$svc = new DirectoryOptionsService($this->createMock(IGroupManager::class), $users);
		self::assertSame([], $svc->searchUsers('a'));
		self::assertSame([], $svc->searchUsers(''));
	}

	public function testMergesUidAndDisplayNameHitsAndSkipsDisabled(): void
	{
		$alice = $this->user('alice', 'Alice Admin');
		$bob = $this->user('bob', 'Bob Builder');
		$ghost = $this->user('ghost', 'Ghost', false);

		$users = $this->createMock(IUserManager::class);
		$users->method('search')->with('al', 20, 0)->willReturn([$alice, $ghost]);
		$users->method('searchDisplayName')->with('al', 20, 0)->willReturn([$bob, $alice]);

		$svc = new DirectoryOptionsService($this->createMock(IGroupManager::class), $users);
		$out = $svc->searchUsers('al', 20);

		self::assertCount(2, $out);
		$ids = array_column($out, 'id');
		self::assertContains('alice', $ids);
		self::assertContains('bob', $ids);
		self::assertNotContains('ghost', $ids);
	}

	public function testRespectsLimitAcrossMergedSources(): void
	{
		$u1 = $this->user('u1', 'One');
		$u2 = $this->user('u2', 'Two');
		$u3 = $this->user('u3', 'Three');
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->willReturn([$u1, $u2, $u3]);
		$users->method('searchDisplayName')->willReturn([]);

		$out = (new DirectoryOptionsService($this->createMock(IGroupManager::class), $users))
			->searchUsers('user', 2);
		self::assertCount(2, $out);
	}
}
