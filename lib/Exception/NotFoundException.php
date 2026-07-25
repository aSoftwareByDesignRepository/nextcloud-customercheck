<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Exception;

use OCA\CustomerCheck\Constants;

/**
 * A referenced CRM record does not exist (or is not visible to this request).
 */
class NotFoundException extends \RuntimeException
{
	private string $errorCode;

	public function __construct(string $message = '', string $errorCode = Constants::ERR_NOT_FOUND)
	{
		parent::__construct($message !== '' ? $message : $errorCode);
		$this->errorCode = $errorCode;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
