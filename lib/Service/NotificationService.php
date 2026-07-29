<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Fan-out of CustomerCheck notifications to every CRM user (§6.2, §15).
 *
 * Notifications are the CRM inbox: the app never emails the operator, and it
 * never emails a customer without a human pressing compose (§6.2).
 *
 * Companion P2 bridges selected subjects to Expo push via {@see MobilePushService}
 * after the in-app fan-out. Push failures never affect the return value.
 */
class NotificationService
{
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly AccessControlService $accessControl,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly ?MobilePushService $mobilePush = null,
	) {
	}

	/**
	 * @param array<string, string|int|bool> $subjectParameters
	 * @return int number of users notified
	 */
	public function notifyCrmUsers(string $subject, string $objectType, string $objectId, array $subjectParameters = []): int
	{
		if (!in_array($subject, Constants::NOTIFICATION_SUBJECTS, true)) {
			throw new \InvalidArgumentException('Unknown CustomerCheck notification subject: ' . $subject);
		}

		$recipients = $this->accessControl->listNotifiableUserIds();
		return $this->notifyUsers($recipients, $subject, $objectType, $objectId, $subjectParameters);
	}

	/**
	 * Notify a single user (Digest v2 per-user Brief links — product §10.5).
	 *
	 * @param array<string, string|int|bool> $subjectParameters
	 */
	public function notifyUser(string $userId, string $subject, string $objectType, string $objectId, array $subjectParameters = []): bool
	{
		return $this->notifyUsers([$userId], $subject, $objectType, $objectId, $subjectParameters) === 1;
	}

	/**
	 * @param list<string> $recipients
	 * @param array<string, string|int|bool> $subjectParameters
	 */
	private function notifyUsers(array $recipients, string $subject, string $objectType, string $objectId, array $subjectParameters): int
	{
		if (!in_array($subject, Constants::NOTIFICATION_SUBJECTS, true)) {
			throw new \InvalidArgumentException('Unknown CustomerCheck notification subject: ' . $subject);
		}

		$sent = 0;

		foreach ($recipients as $uid) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($uid)
					->setDateTime($this->timeFactory->getDateTime())
					->setObject($objectType, $objectId)
					->setSubject($subject, $subjectParameters);
				$this->notificationManager->notify($notification);
				$sent++;
			} catch (\Throwable $e) {
				// One bad recipient must not abort the fan-out.
				$this->logger->warning('CustomerCheck notification failed', [
					'app' => Application::APP_ID,
					'subject' => $subject,
					'exception' => $e,
				]);
			}
		}

		if ($this->mobilePush !== null && MobilePushService::isPushSubject($subject)) {
			$this->mobilePush->dispatch($recipients, $subject, $subjectParameters);
		}

		return $sent;
	}

	/**
	 * Withdraw notifications for an object that no longer needs attention.
	 */
	public function withdraw(string $objectType, string $objectId): void
	{
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setObject($objectType, $objectId);
			$this->notificationManager->markProcessed($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck notification withdrawal failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
