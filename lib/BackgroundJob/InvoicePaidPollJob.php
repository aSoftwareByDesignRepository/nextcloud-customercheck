<?php
declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\InvoicePaidSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Nightly invoice-paid watermark poll — PSD §14.3.
 */
class InvoicePaidPollJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly InvoicePaidSyncService $sync,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$this->sync->poll();
	}
}
