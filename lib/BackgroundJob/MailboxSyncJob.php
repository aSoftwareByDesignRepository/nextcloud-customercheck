<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\BackgroundJob;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Shared mailbox IMAP sync — product §14 / §17.1. Interval 15 minutes. */
class MailboxSyncJob extends TimedJob
{
	public function __construct(ITimeFactory $time, private readonly MailboxSyncService $sync)
	{
		parent::__construct($time);
		$this->setInterval(900);
	}

	protected function run($argument): void
	{
		$this->sync->syncAll();
	}
}
