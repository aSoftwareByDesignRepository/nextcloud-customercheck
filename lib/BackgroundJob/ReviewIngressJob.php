<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\BackgroundJob;

use OCA\CustomerCheck\Service\ReviewIngressService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Opt-in Assign source ingress — GNQ §5.2. Interval 20 minutes. */
class ReviewIngressJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ReviewIngressService $ingress,
	) {
		parent::__construct($time);
		$this->setInterval(1200);
	}

	protected function run($argument): void
	{
		$this->ingress->syncAll();
	}
}
