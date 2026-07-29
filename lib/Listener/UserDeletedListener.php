<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Listener;

use OCA\CustomerCheck\Db\MobileSeatMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Frees the deleted user's companion seat so it never counts against the
 * CUC2 seat limit as an orphan (GDPR + licensing hygiene).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
	public function __construct(
		private readonly MobileSeatMapper $seats,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		try {
			$this->seats->deleteByUserId($event->getUser()->getUID());
		} catch (\Throwable $e) {
			// Never block user deletion on CRM cleanup.
			$this->logger->warning('CustomerCheck: could not free mobile seat of deleted user', [
				'app' => 'customercheck',
				'exception' => $e,
			]);
		}
	}
}
