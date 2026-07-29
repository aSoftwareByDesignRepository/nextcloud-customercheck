<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Service\AccessControlService;
use OCA\CustomerCheck\Service\MobilePushService;
use OCA\CustomerCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NotificationServicePushBridgeTest extends TestCase
{
	public function testNotifyCrmUsersBridgesPushSubjects(): void
	{
		$manager = $this->createMock(IManager::class);
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->once())->method('notify');

		$access = $this->createMock(AccessControlService::class);
		$access->method('listNotifiableUserIds')->willReturn(['alice']);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('@0'));

		$push = $this->createMock(MobilePushService::class);
		$push->expects($this->once())
			->method('dispatch')
			->with(['alice'], Constants::NOTIFY_REMINDER, ['companyId' => 3]);

		$svc = new NotificationService(
			$manager,
			$access,
			$time,
			$this->createMock(LoggerInterface::class),
			$push,
		);
		self::assertSame(1, $svc->notifyCrmUsers(
			Constants::NOTIFY_REMINDER,
			'agreement',
			'9',
			['companyId' => 3],
		));
	}

	public function testNotifyCrmUsersSkipsPushForNonCompanionSubjects(): void
	{
		$manager = $this->createMock(IManager::class);
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$manager->method('createNotification')->willReturn($notification);
		$manager->method('notify');

		$access = $this->createMock(AccessControlService::class);
		$access->method('listNotifiableUserIds')->willReturn(['alice']);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('@0'));

		$push = $this->createMock(MobilePushService::class);
		$push->expects($this->never())->method('dispatch');

		$svc = new NotificationService(
			$manager,
			$access,
			$time,
			$this->createMock(LoggerInterface::class),
			$push,
		);
		$svc->notifyCrmUsers(Constants::NOTIFY_LEAD, 'deal', '1', ['name' => 'Lead']);
	}
}
