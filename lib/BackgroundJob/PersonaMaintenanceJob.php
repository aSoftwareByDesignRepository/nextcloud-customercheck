<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\BackgroundJob;
use OCA\CustomerCheck\Service\PersonaMaintenanceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** OOO activate/end + escalation auto-resolve + commitment auto-verify. */
class PersonaMaintenanceJob extends TimedJob
{
	public function __construct(ITimeFactory $time, private readonly PersonaMaintenanceService $service)
	{
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void
	{
		$this->service->run();
	}
}
