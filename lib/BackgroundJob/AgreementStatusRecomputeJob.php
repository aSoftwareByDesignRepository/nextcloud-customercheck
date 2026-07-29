<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\ReminderScheduler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Daily status derivation without firing renewal notifications (§4.7-B / §15). */
class AgreementStatusRecomputeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ReminderScheduler $service,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$this->service->runStatusOnly();
	}
}
