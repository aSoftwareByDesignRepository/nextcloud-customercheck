<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Product-code registry (CUC M1 S4 / WP-S1-CUC-CODES).
 *
 * Built-ins stay in {@see Constants::PRODUCT_CODES}. Custom codes live in
 * AppConfig JSON {@see self::CONFIG_CUSTOM} — prefer AppConfig while catalogues
 * stay small (CORE §8.1).
 */
class ProductCodeRegistry
{
	public const CONFIG_CUSTOM = 'product_codes_custom';

	/** Custom code pattern: lowercase alnum + hyphen, 2–32 chars. */
	public const CUSTOM_PATTERN = '/^[a-z0-9-]{2,32}$/';

	public function __construct(
		private readonly IConfig $config,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return list<array{code: string, label: string, kind: string, active: bool, builtin: bool}>
	 */
	public function listAll(): array
	{
		$out = [];
		foreach (Constants::PRODUCT_CODES as $code) {
			$out[] = [
				'code' => $code,
				'label' => $code,
				'kind' => Constants::PRODUCT_CODE_KINDS[$code] ?? 'onetime',
				'active' => true,
				'builtin' => true,
			];
		}
		foreach ($this->customRows() as $row) {
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Active codes allowed on agreements (built-in ∪ active custom).
	 *
	 * @return list<string>
	 */
	public function allowedCodes(): array
	{
		$codes = Constants::PRODUCT_CODES;
		foreach ($this->customRows() as $row) {
			if ($row['active']) {
				$codes[] = $row['code'];
			}
		}

		return array_values(array_unique($codes));
	}

	public function isAllowed(string $code): bool
	{
		return in_array($code, $this->allowedCodes(), true);
	}

	/**
	 * Kind for a code (built-in map or custom catalogue). Unknown → onetime.
	 */
	public function kindFor(string $code): string
	{
		$code = strtolower(trim($code));
		if ($code !== '' && isset(Constants::PRODUCT_CODE_KINDS[$code])) {
			return Constants::PRODUCT_CODE_KINDS[$code];
		}
		foreach ($this->customRows() as $row) {
			if ($row['code'] === $code) {
				return $row['kind'];
			}
		}

		return 'onetime';
	}

	/**
	 * Replace the custom catalogue (app-admin). Built-ins are never stored here.
	 *
	 * @param list<array{code?: mixed, label?: mixed, kind?: mixed, active?: mixed}> $rows
	 * @return list<array{code: string, label: string, kind: string, active: bool, builtin: bool}>
	 */
	public function saveCustom(array $rows): array
	{
		$clean = [];
		$seen = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$code = strtolower(trim((string)($row['code'] ?? '')));
			if ($code === '' || in_array($code, Constants::PRODUCT_CODES, true)) {
				continue;
			}
			if (!preg_match(self::CUSTOM_PATTERN, $code)) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'code',
					$this->l10n->t('Custom codes use lowercase letters, digits and hyphens (2–32 characters).'),
				);
			}
			if (isset($seen[$code])) {
				throw ValidationException::field(
					Constants::ERR_VALIDATION,
					'code',
					$this->l10n->t('Duplicate custom product code "%s".', [$code]),
				);
			}
			$seen[$code] = true;
			$kind = (string)($row['kind'] ?? 'onetime');
			if (!in_array($kind, Constants::AGREEMENT_KINDS, true)) {
				$kind = 'onetime';
			}
			$label = trim((string)($row['label'] ?? $code));
			if ($label === '') {
				$label = $code;
			}
			if (mb_strlen($label) > 80) {
				$label = mb_substr($label, 0, 80);
			}
			$clean[] = [
				'code' => $code,
				'label' => $label,
				'kind' => $kind,
				'active' => !array_key_exists('active', $row) || (bool)$row['active'],
			];
		}
		if (count($clean) > 50) {
			throw ValidationException::field(
				Constants::ERR_VALIDATION,
				'codes',
				$this->l10n->t('At most 50 custom product codes are allowed.'),
			);
		}

		$this->config->setAppValue(
			Application::APP_ID,
			self::CONFIG_CUSTOM,
			json_encode($clean, JSON_THROW_ON_ERROR),
		);

		return $this->listAll();
	}

	/**
	 * @return list<array{code: string, label: string, kind: string, active: bool, builtin: bool}>
	 */
	private function customRows(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::CONFIG_CUSTOM, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $row) {
			if (!is_array($row)) {
				continue;
			}
			$code = strtolower(trim((string)($row['code'] ?? '')));
			if ($code === '' || !preg_match(self::CUSTOM_PATTERN, $code)) {
				continue;
			}
			if (in_array($code, Constants::PRODUCT_CODES, true)) {
				continue;
			}
			$kind = (string)($row['kind'] ?? 'onetime');
			if (!in_array($kind, Constants::AGREEMENT_KINDS, true)) {
				$kind = 'onetime';
			}
			$out[] = [
				'code' => $code,
				'label' => (string)($row['label'] ?? $code),
				'kind' => $kind,
				'active' => (bool)($row['active'] ?? true),
				'builtin' => false,
			];
		}

		return $out;
	}
}
