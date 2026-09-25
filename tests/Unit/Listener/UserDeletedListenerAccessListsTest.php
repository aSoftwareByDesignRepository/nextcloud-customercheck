<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Listener;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\MobileSeatMapper;
use OCA\CustomerCheck\Db\WorkspaceMemberMapper;
use OCA\CustomerCheck\Listener\UserDeletedListener;
use OCP\IConfig;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class UserDeletedListenerAccessListsTest extends TestCase
{
	public function testRemovesUidFromAppAdminAndAllowedUserLists(): void
	{
		$stored = [
			Constants::CONFIG_APP_ADMIN_USER_IDS => '["alice","bob"]',
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => '["bob","carol"]',
		];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '[]') use (&$stored): string {
				return $stored[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): void {
				self::assertSame(Application::APP_ID, $app);
				$stored[$key] = $value;
			}
		);

		$seats = $this->createMock(MobileSeatMapper::class);
		$seats->expects($this->once())->method('deleteByUserId')->with('bob');

		$members = $this->createMock(WorkspaceMemberMapper::class);
		$members->expects($this->once())->method('deleteByUser')->with('bob');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($user);

		$listener = new UserDeletedListener($seats, $config, $this->createMock(LoggerInterface::class), $members);
		$listener->handle($event);

		$this->assertSame('["alice"]', $stored[Constants::CONFIG_APP_ADMIN_USER_IDS]);
		$this->assertSame('["carol"]', $stored[Constants::CONFIG_ACCESS_ALLOWED_USER_IDS]);
	}
}
