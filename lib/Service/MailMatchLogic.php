<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;

/**
 * Shared-mail matching — product §14.2 / §18.3.
 *
 * Order: manual → exact person email → domain match (non-freemail, unique) → unmatched.
 */
final class MailMatchLogic
{
	public static function isFreemail(string $domain): bool
	{
		$domain = strtolower(trim($domain));
		return in_array($domain, Constants::FREEMAIL_DOMAINS, true);
	}

	public static function domainOf(string $email): ?string
	{
		$email = strtolower(trim($email));
		$at = strrpos($email, '@');
		if ($at === false || $at === 0 || $at === strlen($email) - 1) {
			return null;
		}
		return substr($email, $at + 1);
	}

	/**
	 * @param list<array{companyId: int, email: string}> $personsInWorkspace
	 * @return array{companyId: ?int, how: 'manual'|'person_email'|'domain'|'unmatched'}
	 */
	public static function match(
		?int $manualCompanyId,
		string $fromAddress,
		array $personsInWorkspace,
	): array {
		if ($manualCompanyId !== null && $manualCompanyId > 0) {
			return ['companyId' => $manualCompanyId, 'how' => 'manual'];
		}

		$from = strtolower(trim($fromAddress));
		if ($from === '' || !str_contains($from, '@')) {
			return ['companyId' => null, 'how' => 'unmatched'];
		}

		foreach ($personsInWorkspace as $p) {
			$email = strtolower(trim((string)($p['email'] ?? '')));
			if ($email !== '' && hash_equals($email, $from)) {
				return ['companyId' => (int)$p['companyId'], 'how' => 'person_email'];
			}
		}

		$domain = self::domainOf($from);
		if ($domain === null || self::isFreemail($domain)) {
			return ['companyId' => null, 'how' => 'unmatched'];
		}

		$hits = [];
		foreach ($personsInWorkspace as $p) {
			$email = strtolower(trim((string)($p['email'] ?? '')));
			$d = self::domainOf($email);
			if ($d !== null && hash_equals($d, $domain)) {
				$hits[(int)$p['companyId']] = true;
			}
		}
		if (count($hits) === 1) {
			return ['companyId' => (int)array_key_first($hits), 'how' => 'domain'];
		}

		return ['companyId' => null, 'how' => 'unmatched'];
	}

	/**
	 * Auth fail ×3 → mailbox error state.
	 */
	public static function nextAuthStatus(int $failCountAfterIncrement): string
	{
		return $failCountAfterIncrement >= 3 ? 'error' : 'ok';
	}
}
