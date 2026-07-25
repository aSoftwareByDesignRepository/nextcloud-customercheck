<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Exception;

/**
 * No Nextcloud session is attached to the current request.
 */
class NotAuthenticatedException extends \RuntimeException
{
	public function __construct(string $message = 'not_authenticated')
	{
		parent::__construct($message);
	}
}
