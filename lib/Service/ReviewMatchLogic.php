<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Deterministic Review match suggestions — GNQ §5.5.
 * Scores are integers 0–100. Auto-link never runs in v1.
 */
final class ReviewMatchLogic
{
	/** @var list<string> */
	private const FREEMAIL = [
		'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.de', 'hotmail.com', 'outlook.com',
		'live.com', 'icloud.com', 'me.com', 'aol.com', 'gmx.de', 'gmx.net', 'web.de',
		'mail.de', 't-online.de', 'proton.me', 'protonmail.com',
	];

	/** @var list<string> */
	private const LEGAL_SUFFIXES = [
		'gmbh', 'ag', 'kg', 'ohg', 'ug', 'e.v.', 'ev', 'inc', 'ltd', 'llc', 'corp', 'co', 'se',
	];

	/**
	 * @param list<array{id: int, name: string, emails: list<string>, domains: list<string>, archived?: bool}> $companies
	 * @return list<array{companyId: int, companyName: string, score: int, reasonCode: string}>
	 */
	public static function suggest(?string $email, ?string $orgName, array $companies, bool $includeArchived = false): array
	{
		$out = [];
		$email = strtolower(trim((string)$email));
		$domain = '';
		if ($email !== '' && str_contains($email, '@')) {
			$domain = substr($email, (int)strrpos($email, '@') + 1);
		}
		$freemail = $domain !== '' && in_array($domain, self::FREEMAIL, true);
		$orgNorm = self::normalizeOrgName((string)$orgName);
		$namesById = [];
		foreach ($companies as $c) {
			$id = (int)($c['id'] ?? 0);
			if ($id > 0) {
				$namesById[$id] = (string)($c['name'] ?? '');
			}
		}

		foreach ($companies as $c) {
			if (!$includeArchived && !empty($c['archived'])) {
				continue;
			}
			$id = (int)$c['id'];
			if ($id <= 0) {
				continue;
			}
			$emails = array_map('strtolower', $c['emails'] ?? []);
			if ($email !== '' && in_array($email, $emails, true)) {
				$out[] = self::hit($id, $namesById, 100, 'person_email_exact');
				continue;
			}
		}

		if ($domain !== '' && !$freemail) {
			$domainHits = [];
			foreach ($companies as $c) {
				if (!$includeArchived && !empty($c['archived'])) {
					continue;
				}
				$id = (int)$c['id'];
				$domains = array_map('strtolower', $c['domains'] ?? []);
				if (in_array($domain, $domains, true)) {
					$domainHits[] = $id;
				}
			}
			if (count($domainHits) === 1) {
				$out[] = self::hit($domainHits[0], $namesById, 95, 'domain_unique');
			}
		}

		if ($orgNorm !== '') {
			$exact = [];
			$fuzzy = [];
			foreach ($companies as $c) {
				if (!$includeArchived && !empty($c['archived'])) {
					continue;
				}
				$id = (int)$c['id'];
				$cn = self::normalizeOrgName((string)($c['name'] ?? ''));
				if ($cn === '') {
					continue;
				}
				if ($cn === $orgNorm) {
					$exact[] = $id;
				} elseif (self::fuzzyOrgMatch($orgNorm, $cn)) {
					$fuzzy[] = $id;
				}
			}
			if (count($exact) === 1) {
				$out[] = self::hit($exact[0], $namesById, 70, 'org_name_unique');
			} elseif (count($exact) === 0 && count($fuzzy) === 1) {
				$out[] = self::hit($fuzzy[0], $namesById, 55, 'org_name_fuzzy');
			}
		}

		usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
		$seen = [];
		$deduped = [];
		foreach ($out as $row) {
			$cid = $row['companyId'];
			if (isset($seen[$cid])) {
				continue;
			}
			$seen[$cid] = true;
			$deduped[] = $row;
			if (count($deduped) >= 3) {
				break;
			}
		}

		return $deduped;
	}

	/**
	 * @param array<int, string> $namesById
	 * @return array{companyId: int, companyName: string, score: int, reasonCode: string}
	 */
	private static function hit(int $id, array $namesById, int $score, string $reasonCode): array
	{
		return [
			'companyId' => $id,
			'companyName' => $namesById[$id] ?? '',
			'score' => $score,
			'reasonCode' => $reasonCode,
		];
	}

	public static function normalizeOrgName(string $name): string
	{
		$name = mb_strtolower(trim($name));
		$name = preg_replace('/\s+/u', ' ', $name) ?? $name;
		$name = str_replace(['.', ',', ';'], ' ', $name);
		$tokens = preg_split('/\s+/u', $name) ?: [];
		$kept = [];
		foreach ($tokens as $t) {
			$t = trim($t);
			if ($t === '' || in_array($t, self::LEGAL_SUFFIXES, true)) {
				continue;
			}
			$kept[] = $t;
		}

		return implode(' ', $kept);
	}

	public static function fuzzyOrgMatch(string $a, string $b): bool
	{
		$ta = array_values(array_filter(explode(' ', $a), static fn (string $t): bool => mb_strlen($t) >= 4));
		$tb = array_values(array_filter(explode(' ', $b), static fn (string $t): bool => mb_strlen($t) >= 4));
		if ($ta === [] || $tb === []) {
			return false;
		}
		foreach ($ta as $x) {
			foreach ($tb as $y) {
				if (levenshtein($x, $y) <= 2) {
					return true;
				}
			}
		}

		return false;
	}
}
