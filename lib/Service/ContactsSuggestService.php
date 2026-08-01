<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\App\IAppManager;
use OCP\Contacts\IManager;

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

		$limit = max(1, min(20, $limit));
		try {
			/** @var list<array<string, mixed>> $raw */
			$raw = $this->contacts->search($q, ['FN', 'EMAIL', 'TEL'], [
				'limit' => $limit,
				'types' => false,
			]);
		} catch (\Throwable) {
			return [];
		}

		$out = [];
		$seen = [];
		foreach ($raw as $row) {
			$name = trim((string)($row['FN'] ?? $row['fn'] ?? ''));
			$email = $this->firstString($row['EMAIL'] ?? $row['email'] ?? null);
			$phone = $this->firstString($row['TEL'] ?? $row['tel'] ?? null);
			$uid = $this->firstString($row['UID'] ?? $row['uid'] ?? null);
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
			$out[] = [
				'name' => $name,
				'email' => $email,
				'phone' => $phone,
				'uid' => $uid,
			];
			if (count($out) >= $limit) {
				break;
			}
		}

		return $out;
	}

	public function isAvailable(): bool
	{
		return $this->contacts !== null && $this->appManager->isEnabledForUser('contacts');
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
