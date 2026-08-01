<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Listener;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\MobileSeatMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Frees the deleted user's companion seat and strips them from access /
 * app-admin allow lists so orphans never linger (GDPR + licensing hygiene).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
	public function __construct(
		private readonly MobileSeatMapper $seats,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		try {
			$this->seats->deleteByUserId($uid);
		} catch (\Throwable $e) {
			// Never block user deletion on CRM cleanup.
			$this->logger->warning('CustomerCheck: could not free mobile seat of deleted user', [
				'app' => 'customercheck',
				'exception' => $e,
			]);
		}
		try {
			$this->removeUidFromJsonList(Constants::CONFIG_APP_ADMIN_USER_IDS, $uid);
			$this->removeUidFromJsonList(Constants::CONFIG_ACCESS_ALLOWED_USER_IDS, $uid);
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck: could not scrub access lists for deleted user', [
				'app' => 'customercheck',
				'exception' => $e,
			]);
		}
	}

	private function removeUidFromJsonList(string $key, string $uid): void
	{
		$raw = $this->config->getAppValue(Application::APP_ID, $key, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return;
		}
		$filtered = [];
		foreach ($decoded as $entry) {
			if (!is_string($entry) && !is_int($entry)) {
				continue;
			}
			$value = trim((string)$entry);
			if ($value === '' || $value === $uid) {
				continue;
			}
			$filtered[] = $value;
		}
		$filtered = array_values(array_unique($filtered));
		$encoded = json_encode($filtered, JSON_THROW_ON_ERROR);
		if ($encoded !== $raw) {
			$this->config->setAppValue(Application::APP_ID, $key, $encoded);
		}
	}
}
