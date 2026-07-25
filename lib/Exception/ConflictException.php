<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Exception;

use OCA\CustomerCheck\Constants;

/**
 * Optimistic-lock failure: the record changed since the client loaded it
 * (§4.8). Answered as HTTP 409 with `CONFLICT_STALE` so the UI can reload.
 */
class ConflictException extends \RuntimeException
{
	private string $errorCode;

	public function __construct(string $message = '', string $errorCode = Constants::ERR_CONFLICT_STALE)
	{
		parent::__construct($message !== '' ? $message : $errorCode);
		$this->errorCode = $errorCode;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
