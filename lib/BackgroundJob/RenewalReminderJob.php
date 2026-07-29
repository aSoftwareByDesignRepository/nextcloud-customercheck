<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\ActivityDueReminderService;
use OCA\CustomerCheck\Service\ReminderScheduler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Hourly TimedJob that fires the renewal cadence only at `reminder_hour` (§15).
 * Same hour also bridges activity-due NC notifications + companion push (P2).
 * Status recompute for off-hour ticks lives in AgreementStatusRecomputeJob.
 */
class RenewalReminderJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ReminderScheduler $service,
		private readonly ActivityDueReminderService $activityDue,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		if ($this->service->shouldRunNow()) {
			$this->service->run();
		}
		if ($this->activityDue->shouldRunNow()) {
			$this->activityDue->run();
		}
	}
}
