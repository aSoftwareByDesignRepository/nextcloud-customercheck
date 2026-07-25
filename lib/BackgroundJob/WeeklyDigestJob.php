<?php
declare(strict_types=1);
namespace OCA\CustomerCheck\BackgroundJob;
use OCA\CustomerCheck\Service\WeeklyDigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class WeeklyDigestJob extends TimedJob
{
	public function __construct(ITimeFactory $time, private readonly WeeklyDigestService $service)
	{
		parent::__construct($time);
		$this->setInterval(3600);
	}
	protected function run($argument): void
	{
		if ($this->service->shouldRunNow()) {
			$this->service->run();
		}
	}
}
