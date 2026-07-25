<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Exception;

use OCA\CustomerCheck\Constants;

/**
 * Server-side validation failure carrying the §10.2 error code and the
 * field-keyed messages rendered next to the offending form controls.
 */
class ValidationException extends \RuntimeException
{
	/** @var array<string, string> */
	private array $fields;

	private string $errorCode;

	/**
	 * @param array<string, string> $fields
	 */
	public function __construct(
		string $errorCode = Constants::ERR_VALIDATION,
		string $message = '',
		array $fields = [],
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
		$this->errorCode = $errorCode;
		$this->fields = $fields;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	/** @return array<string, string> */
	public function getFields(): array
	{
		return $this->fields;
	}

	/**
	 * Convenience constructor for the common "one bad field" case.
	 */
	public static function field(string $errorCode, string $field, string $message): self
	{
		return new self($errorCode, $message, [$field => $message]);
	}
}
