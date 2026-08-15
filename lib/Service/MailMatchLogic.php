<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;

/**
 * Shared-mail matching — product §14.2 / §18.3.
 *
 * Order:
 * 1. manual
 * 2. Forward hints (when present) — team envelope From must not beat embedded customer
 * 3. From → exact person (unique) → domain (non-freemail, unique, not team domain)
 * 4. If From is team/unknown: To+CC recipients → exact person → domain
 * 5. unmatched / ambiguous
 *
 * One indexed mail row → at most one company. Multiple customer companies
 * in the recipient list never fan out — they go to Assign.
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
		$email = self::normalizeFromAddress($email);
		$at = strrpos($email, '@');
		if ($at === false || $at === 0 || $at === strlen($email) - 1) {
			return null;
		}
		return substr($email, $at + 1);
	}

	/**
	 * Bare email from a From header / display name (`Ada <ada@acme.test>`).
	 */
	public static function normalizeFromAddress(string $fromAddress): string
	{
		$fromAddress = trim(str_replace("\xc2\xa0", ' ', $fromAddress));
		if ($fromAddress === '') {
			return '';
		}
		if (preg_match('/<([^<>@\s]+@[^<>@\s]+)>/', $fromAddress, $m) === 1) {
			return strtolower(trim($m[1]));
		}
		if (str_contains($fromAddress, '@') && !str_contains($fromAddress, ' ')) {
			return strtolower($fromAddress);
		}
		if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $fromAddress, $m) === 1) {
			return strtolower(trim($m[0]));
		}

		return strtolower($fromAddress);
	}

	/**
	 * Normalize To/CC/hint lists: trim, lowercase bare mailbox, drop empties, dedupe.
	 *
	 * @param list<string> $addresses
	 * @return list<string>
	 */
	public static function normalizeAddressList(array $addresses): array
	{
		$out = [];
		foreach ($addresses as $raw) {
			$email = self::normalizeFromAddress((string)$raw);
			if ($email !== '' && str_contains($email, '@')) {
				$out[$email] = true;
			}
		}

		return array_keys($out);
	}

	/**
	 * @param list<string> $emails
	 * @return array<string, true>
	 */
	public static function emailLookup(array $emails): array
	{
		$out = [];
		foreach ($emails as $raw) {
			$email = strtolower(trim((string)$raw));
			if ($email === '' || !str_contains($email, '@')) {
				continue;
			}
			$out[$email] = true;
		}

		return $out;
	}

	/**
	 * @param array<string, true> $excludeLookup
	 * @return array<string, true>
	 */
	public static function teamDomainsFromLookup(array $excludeLookup): array
	{
		$out = [];
		foreach (array_keys($excludeLookup) as $email) {
			$domain = self::domainOf($email);
			if ($domain === null || self::isFreemail($domain)) {
				continue;
			}
			$out[$domain] = true;
		}

		return $out;
	}

	/**
	 * @param list<array{companyId: int, email: string}> $personsInWorkspace
	 * @param list<string> $excludeEmails Shared-mailbox + workspace-member addresses
	 * @param list<string> $recipientAddresses To + CC (and optional BCC if known)
	 * @param list<string> $originalFromHints Resent-From / forwarded body From (already filtered)
	 * @return array{companyId: ?int, how: 'manual'|'person_email'|'domain'|'recipient_email'|'recipient_domain'|'forward_from'|'forward_domain'|'ambiguous'|'unmatched'}
	 */
	public static function match(
		?int $manualCompanyId,
		string $fromAddress,
		array $personsInWorkspace,
		array $excludeEmails = [],
		array $recipientAddresses = [],
		array $originalFromHints = [],
		bool $allowDomainMatch = true,
	): array {
		if ($manualCompanyId !== null && $manualCompanyId > 0) {
			return ['companyId' => $manualCompanyId, 'how' => 'manual'];
		}

		$excludeLookup = self::emailLookup($excludeEmails);
		$teamDomains = self::teamDomainsFromLookup($excludeLookup);
		$from = self::normalizeFromAddress($fromAddress);
		$fromDomain = self::domainOf($from);

		// Forward hints first when present — e.g. Thunderbird Fwd: with team envelope From
		// must not file onto the internal company where the forwarder is listed as a person.
		if ($originalFromHints !== []) {
			$forwardHit = self::matchOriginalFromHints(
				$originalFromHints,
				$personsInWorkspace,
				$excludeLookup,
				$teamDomains,
				$allowDomainMatch,
			);
			if ($forwardHit !== null) {
				return $forwardHit;
			}
		}

		// 1) Customer wrote TO the shared mailbox — From is the customer.
		// Skip team mailboxes, workspace members, and anyone on a known team domain.
		if ($from !== '' && str_contains($from, '@') && !isset($excludeLookup[$from])
			&& ($fromDomain === null || !isset($teamDomains[$fromDomain]))) {
			$exact = self::exactPersonCompanies($from, $personsInWorkspace);
			if (count($exact) === 1) {
				return ['companyId' => (int)array_key_first($exact), 'how' => 'person_email'];
			}
			if (count($exact) > 1) {
				return ['companyId' => null, 'how' => 'ambiguous'];
			}
			if ($allowDomainMatch) {
				$domainCompany = self::uniqueDomainCompany($from, $personsInWorkspace, $teamDomains);
				if ($domainCompany === 'ambiguous') {
					return ['companyId' => null, 'how' => 'unmatched'];
				}
				if (is_int($domainCompany)) {
					return ['companyId' => $domainCompany, 'how' => 'domain'];
				}
			}
		}

		// 2) You / a colleague mailed the customer and CC’d the shared box —
		//    From is team; match a unique customer on To/CC.
		$recipientHit = self::matchRecipients(
			$recipientAddresses,
			$personsInWorkspace,
			$excludeLookup,
			$teamDomains,
			$allowDomainMatch,
		);
		if ($recipientHit !== null) {
			return $recipientHit;
		}

		return ['companyId' => null, 'how' => 'unmatched'];
	}

	/**
	 * @param list<string> $hints
	 * @param list<array{companyId: int, email: string}> $persons
	 * @param array<string, true> $excludeLookup
	 * @param array<string, true> $teamDomains
	 * @return array{companyId: ?int, how: string}|null
	 */
	private static function matchOriginalFromHints(
		array $hints,
		array $persons,
		array $excludeLookup,
		array $teamDomains,
		bool $allowDomainMatch = true,
	): ?array {
		$exactCompanies = [];
		foreach ($hints as $raw) {
			$email = strtolower(trim((string)$raw));
			if ($email === '' || !str_contains($email, '@') || isset($excludeLookup[$email])) {
				continue;
			}
			foreach (self::exactPersonCompanies($email, $persons) as $cid => $_) {
				$exactCompanies[$cid] = true;
			}
		}
		if (count($exactCompanies) === 1) {
			return ['companyId' => (int)array_key_first($exactCompanies), 'how' => 'forward_from'];
		}
		if (count($exactCompanies) > 1) {
			return ['companyId' => null, 'how' => 'ambiguous'];
		}

		if (!$allowDomainMatch) {
			return null;
		}

		$domainCompanies = [];
		foreach ($hints as $raw) {
			$email = strtolower(trim((string)$raw));
			if ($email === '' || !str_contains($email, '@') || isset($excludeLookup[$email])) {
				continue;
			}
			$hit = self::uniqueDomainCompany($email, $persons, $teamDomains);
			if (is_int($hit)) {
				$domainCompanies[$hit] = true;
			}
		}
		if (count($domainCompanies) === 1) {
			return ['companyId' => (int)array_key_first($domainCompanies), 'how' => 'forward_domain'];
		}
		if (count($domainCompanies) > 1) {
			return ['companyId' => null, 'how' => 'ambiguous'];
		}

		return null;
	}

	/**
	 * @param list<string> $recipientAddresses
	 * @param list<array{companyId: int, email: string}> $persons
	 * @param array<string, true> $excludeLookup
	 * @param array<string, true> $teamDomains
	 * @return array{companyId: ?int, how: string}|null
	 */
	private static function matchRecipients(
		array $recipientAddresses,
		array $persons,
		array $excludeLookup,
		array $teamDomains,
		bool $allowDomainMatch = true,
	): ?array {
		$exactCompanies = [];
		foreach ($recipientAddresses as $raw) {
			$email = self::normalizeFromAddress((string)$raw);
			if ($email === '' || !str_contains($email, '@') || isset($excludeLookup[$email])) {
				continue;
			}
			foreach (self::exactPersonCompanies($email, $persons) as $cid => $_) {
				$exactCompanies[$cid] = true;
			}
		}
		if (count($exactCompanies) === 1) {
			return ['companyId' => (int)array_key_first($exactCompanies), 'how' => 'recipient_email'];
		}
		if (count($exactCompanies) > 1) {
			return ['companyId' => null, 'how' => 'ambiguous'];
		}

		if (!$allowDomainMatch) {
			return null;
		}

		$domainCompanies = [];
		foreach ($recipientAddresses as $raw) {
			$email = self::normalizeFromAddress((string)$raw);
			if ($email === '' || !str_contains($email, '@') || isset($excludeLookup[$email])) {
				continue;
			}
			$hit = self::uniqueDomainCompany($email, $persons, $teamDomains);
			if (is_int($hit)) {
				$domainCompanies[$hit] = true;
			}
		}
		if (count($domainCompanies) === 1) {
			return ['companyId' => (int)array_key_first($domainCompanies), 'how' => 'recipient_domain'];
		}
		if (count($domainCompanies) > 1) {
			return ['companyId' => null, 'how' => 'ambiguous'];
		}

		return null;
	}

	/**
	 * @param list<array{companyId: int, email: string}> $persons
	 * @return array<int, true>
	 */
	private static function exactPersonCompanies(string $email, array $persons): array
	{
		$hits = [];
		foreach ($persons as $p) {
			$personEmail = strtolower(trim((string)($p['email'] ?? '')));
			if ($personEmail !== '' && hash_equals($personEmail, $email)) {
				$hits[(int)$p['companyId']] = true;
			}
		}

		return $hits;
	}

	/**
	 * @param list<array{companyId: int, email: string}> $persons
	 * @param array<string, true> $teamDomains
	 * @return int|'ambiguous'|null
	 */
	private static function uniqueDomainCompany(string $email, array $persons, array $teamDomains): int|string|null
	{
		$domain = self::domainOf($email);
		if ($domain === null || self::isFreemail($domain) || isset($teamDomains[$domain])) {
			return null;
		}
		$hits = [];
		foreach ($persons as $p) {
			$personEmail = strtolower(trim((string)($p['email'] ?? '')));
			$d = self::domainOf($personEmail);
			if ($d !== null && hash_equals($d, $domain)) {
				$hits[(int)$p['companyId']] = true;
			}
		}
		if (count($hits) === 1) {
			return (int)array_key_first($hits);
		}
		if (count($hits) > 1) {
			return 'ambiguous';
		}

		return null;
	}

	public static function nextAuthStatus(int $failCountAfterIncrement): string
	{
		return $failCountAfterIncrement >= 3 ? 'error' : 'ok';
	}
}
