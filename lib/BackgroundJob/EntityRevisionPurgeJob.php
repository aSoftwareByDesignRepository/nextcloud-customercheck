<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\EntityRevisionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Purge crm_entity_rev snapshots older than 90 days — GNQ §6.3.
 */
class EntityRevisionPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly EntityRevisionService $revisions,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		// Drain in batches until a short pass returns empty (bounded wall time).
		for ($i = 0; $i < 20; $i++) {
			$deleted = $this->revisions->purgeOlderThanDays(90, 500);
			if ($deleted < 1) {
				break;
			}
		}
	}
}
