<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Exception;

/**
 * The authenticated user fails `canUseApp` (PSD §2.2) — the whole app is closed
 * to them, including every page and every session API route.
 */
class AppAccessDeniedException extends \RuntimeException
{
	public function __construct(string $message = 'access_denied')
	{
		parent::__construct($message);
	}
}
