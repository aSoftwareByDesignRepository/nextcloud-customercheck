<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\App\IAppManager;
use OCP\Contacts\IManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Thin Contacts → person suggest (CORE-APP-PLAN §2.1 #7).
 *
 * Read-only; never syncs CardDAV. Degrades to [] when Contacts is missing
 * or the address book search fails.
 */
class ContactsSuggestService
{
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ?IManager $contacts = null,
		private readonly ?IUserSession $userSession = null,
		private readonly ?IUserManager $userManager = null,
	) {
	}

	/**
	 * @return list<array{name: string, email: string|null, phone: string|null, uid: string|null}>
	 */
	public function suggest(string $query, int $limit = 8): array
	{
		$q = trim($query);
		if (mb_strlen($q) < 2) {
			return [];
		}
		if ($this->contacts === null || !$this->appManager->isEnabledForUser('contacts')) {
			return [];
		}

		$limit = max(1, min(40, $limit));
		try {
			/** @var list<array<string, mixed>> $raw */
			$raw = $this->contacts->search($q, ['FN', 'EMAIL', 'TEL', 'ORG'], [
				'limit' => $limit,
				'types' => false,
			]);
		} catch (\Throwable) {
			return [];
		}

		return $this->mapRows($raw, $limit);
	}

	/**
	 * Search Contacts as a workspace member (background Assign ingress — GNQ §5.2).
	 *
	 * @return list<array{name: string, email: string|null, phone: string|null, uid: string|null, org: string|null}>
	 */
	public function suggestAs(string $userId, string $query, int $limit = 40): array
	{
		$userId = trim($userId);
		if ($userId === '' || $this->userSession === null || $this->userManager === null) {
			return $this->suggest($query, $limit);
		}
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [];
		}
		$previous = $this->userSession->getUser();
		$this->userSession->setUser($user);
		try {
			if (!$this->appManager->isEnabledForUser('contacts', $user)) {
				return [];
			}

			return $this->suggestWithOrg($query, $limit);
		} finally {
			$this->userSession->setUser($previous);
		}
	}

	/**
	 * Periodic Assign scan seeds — email-ish + common letters (bounded).
	 *
	 * @return list<array{name: string, email: string|null, phone: string|null, uid: string|null, org: string|null}>
	 */
	public function scanCandidatesAs(string $userId, int $limit = 40): array
	{
		$limit = max(1, min(40, $limit));
		$out = [];
		$seen = [];
		foreach (['@', 'a', 'e', 'm', 's'] as $seed) {
			foreach ($this->suggestAs($userId, $seed, $limit) as $row) {
				$key = strtolower(($row['email'] ?? '') . '|' . ($row['uid'] ?? '') . '|' . $row['name']);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$out[] = $row;
				if (count($out) >= $limit) {
					return $out;
				}
			}
		}

		return $out;
	}

	public function isAvailable(): bool
	{
		return $this->contacts !== null && $this->appManager->isEnabledForUser('contacts');
	}

	/**
	 * @return list<array{name: string, email: string|null, phone: string|null, uid: string|null, org: string|null}>
	 */
	private function suggestWithOrg(string $query, int $limit): array
	{
		$q = trim($query);
		if (mb_strlen($q) < 1 || $this->contacts === null) {
			return [];
		}
		$limit = max(1, min(40, $limit));
		try {
			/** @var list<array<string, mixed>> $raw */
			$raw = $this->contacts->search($q, ['FN', 'EMAIL', 'TEL', 'ORG'], [
				'limit' => $limit,
				'types' => false,
			]);
		} catch (\Throwable) {
			return [];
		}

		return $this->mapRows($raw, $limit, true);
	}

	/**
	 * @param list<array<string, mixed>> $raw
	 * @return list<array{name: string, email: string|null, phone: string|null, uid: string|null, org?: string|null}>
	 */
	private function mapRows(array $raw, int $limit, bool $withOrg = false): array
	{
		$out = [];
		$seen = [];
		foreach ($raw as $row) {
			$name = trim((string)($row['FN'] ?? $row['fn'] ?? ''));
			$email = $this->firstString($row['EMAIL'] ?? $row['email'] ?? null);
			$phone = $this->firstString($row['TEL'] ?? $row['tel'] ?? null);
			$uid = $this->firstString($row['UID'] ?? $row['uid'] ?? null);
			$org = $this->firstString($row['ORG'] ?? $row['org'] ?? null);
			if ($name === '' && $email === null) {
				continue;
			}
			if ($name === '' && $email !== null) {
				$name = $email;
			}
			$key = strtolower(($email ?? '') . '|' . $name);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$item = [
				'name' => $name,
				'email' => $email,
				'phone' => $phone,
				'uid' => $uid,
			];
			if ($withOrg) {
				$item['org'] = $org;
			}
			$out[] = $item;
			if (count($out) >= $limit) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param mixed $value
	 */
	private function firstString(mixed $value): ?string
	{
		if (is_string($value)) {
			$v = trim($value);

			return $v !== '' ? $v : null;
		}
		if (is_array($value)) {
			foreach ($value as $item) {
				if (is_string($item) && trim($item) !== '') {
					return trim($item);
				}
				if (is_array($item)) {
					$nested = $item['value'] ?? $item[0] ?? null;
					if (is_string($nested) && trim($nested) !== '') {
						return trim($nested);
					}
				}
			}
		}

		return null;
	}
}
