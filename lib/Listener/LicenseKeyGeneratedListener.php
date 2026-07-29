<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Listener;

use OCA\CustomerCheck\Service\LicenseKeyEventService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<\OCA\SbdLicenseOps\Event\LicenseKeyGeneratedEvent>
 */
class LicenseKeyGeneratedListener implements IEventListener
{
	public function __construct(
		private readonly LicenseKeyEventService $licenseKeyEventService,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!is_a($event, 'OCA\\SbdLicenseOps\\Event\\LicenseKeyGeneratedEvent')) {
			return;
		}

		try {
			/** @var object $event */
			$this->licenseKeyEventService->handle([
				'salesLogId' => method_exists($event, 'getSalesLogId') ? $event->getSalesLogId() : null,
				'product' => method_exists($event, 'getProduct') ? (string)$event->getProduct() : 'azc',
				'customerId' => method_exists($event, 'getCustomerId') ? (string)$event->getCustomerId() : '',
				'companyName' => method_exists($event, 'getCompanyName') ? (string)$event->getCompanyName() : '',
				'contactEmail' => method_exists($event, 'getContactEmail') ? (string)$event->getContactEmail() : '',
				'limits' => method_exists($event, 'getLimits') ? (array)$event->getLimits() : [],
				'validUntil' => method_exists($event, 'getValidUntil') ? (string)$event->getValidUntil() : '',
				'amountEurCents' => method_exists($event, 'getAmountEurCents') ? (int)$event->getAmountEurCents() : 0,
				'fingerprint' => method_exists($event, 'getFingerprint') ? (string)$event->getFingerprint() : '',
				'generatedBy' => method_exists($event, 'getGeneratedBy') ? (string)$event->getGeneratedBy() : 'system',
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('CustomerCheck failed to handle LicenseKeyGeneratedEvent', [
				'exception' => $e,
			]);
		}
	}
}
