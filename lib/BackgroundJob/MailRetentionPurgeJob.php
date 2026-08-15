<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\MailDraftService;
use OCA\CustomerCheck\Service\MailboxSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Dedicated mail-index retention drain — product §5.10 / §14 + SF-04 draft TTL.
 *
 * Runs more often than IMAP sync so aged CRM mail snippets are purged even
 * when sync is idle. Fair round-robin lives in MailboxSyncService.
 */
class MailRetentionPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly MailboxSyncService $sync,
		private readonly MailDraftService $drafts,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		$this->sync->purgeExpiredAcrossWorkspaces();
		$this->drafts->purgeStale();
	}
}
