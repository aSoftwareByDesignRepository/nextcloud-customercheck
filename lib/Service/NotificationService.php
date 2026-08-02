<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Fan-out of CustomerCheck notifications — product §3.3 / §6.2.
 *
 * Prefer {@see notifyWorkspaceUsers} whenever the object belongs to a book.
 * Instance-wide {@see notifyCrmUsers} remains for rare app-admin events without
 * a workspace (never for company names).
 *
 * Companion P2 bridges selected subjects to Expo push via {@see MobilePushService}
 * after the in-app fan-out. Push failures never affect the return value.
 */
class NotificationService
{
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly AccessControlService $accessControl,
		private readonly WorkspaceAccessService $workspaces,
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
	 * Fan-out to members of one workspace only — never cross-book company names (§3.3).
	 *
	 * @param array<string, string|int|bool> $subjectParameters
	 * @return int number of users notified
	 */
	public function notifyWorkspaceUsers(
		int $workspaceId,
		string $subject,
		string $objectType,
		string $objectId,
		array $subjectParameters = [],
	): int {
		if (!in_array($subject, Constants::NOTIFICATION_SUBJECTS, true)) {
			throw new \InvalidArgumentException('Unknown CustomerCheck notification subject: ' . $subject);
		}
		$recipients = $this->workspaces->listWorkspaceMemberUserIds($workspaceId);
		if (!isset($subjectParameters['workspaceId']) && $workspaceId > 0) {
			$subjectParameters = array_merge($subjectParameters, ['workspaceId' => $workspaceId]);
		}
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
