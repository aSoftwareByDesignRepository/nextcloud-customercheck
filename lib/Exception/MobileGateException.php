<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Exception;

/**
 * Mobile gate ladder failure — HTTP 402 with one of the COMPANION-APP §9.2
 * codes: `LICENSE_REQUIRED`, `LICENSE_EXPIRED`, `NO_MOBILE_SEAT`.
 */
class MobileGateException extends \Exception
{
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
	) {
		parent::__construct($message !== '' ? $message : $errorCode, 402);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
