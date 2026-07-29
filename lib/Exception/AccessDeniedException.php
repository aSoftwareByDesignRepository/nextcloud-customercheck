<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Exception;

/**
 * The user may use the app but is not an app administrator, so this particular
 * operation (access policy, token rotation, job configuration) is refused.
 */
class AccessDeniedException extends \RuntimeException
{
	public function __construct(string $message = 'admin_required')
	{
		parent::__construct($message);
	}
}
