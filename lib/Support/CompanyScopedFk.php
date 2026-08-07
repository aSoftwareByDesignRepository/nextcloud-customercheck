<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\IL10N;

/**
 * Same-company FK binding for mutate paths (fail closed on cross-company links).
 *
 * Workspace ACL alone is not enough: a writer with access to company A must not
 * attach company B's deal/person/agreement onto A's rows.
 */
final class CompanyScopedFk
{
	/**
	 * @throws ValidationException when the related row is not on `$ownerCompanyId`
	 */
	public static function assertSameCompany(
		int $ownerCompanyId,
		int $relatedCompanyId,
		string $field,
		IL10N $l10n,
		?string $message = null,
	): void {
		if ($ownerCompanyId === $relatedCompanyId) {
			return;
		}

		throw ValidationException::field(
			Constants::ERR_VALIDATION,
			$field,
			$message ?? $l10n->t('Pick a record that belongs to this company.'),
		);
	}

	private function __construct()
	{
	}
}
