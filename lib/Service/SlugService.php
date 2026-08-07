<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Db\CompanyMapper;
use OCA\CustomerCheck\Exception\ValidationException;
use OCP\IL10N;

/**
 * Company slug generation and validation — PSD §4.9.1.
 *
 * The slug is the canonical join key across the license generator, the sales
 * log and the retired `customers.csv`, so it uses the same charset as the
 * license `customerId` (D1) and is immutable once a company exists.
 *
 * German umlauts are transliterated (ä→ae, ö→oe, ü→ue, ß→ss) *before* Unicode
 * decomposition, because the DACH customer base expects "Müller GmbH" to become
 * `mueller-gmbh` rather than the `muller-gmbh` that bare mark-stripping yields.
 */
class SlugService
{
	public const MIN_LENGTH = 3;
	public const MAX_LENGTH = 64;
	public const MAX_COLLISION_ATTEMPTS = 100;

	private const PATTERN = '/^[a-z0-9-]{3,64}$/';

	/** @var array<string, string> Applied before NFKD so DACH names read naturally. */
	private const TRANSLITERATIONS = [
		'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
		'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
		'ß' => 'ss', 'ẞ' => 'ss',
		'æ' => 'ae', 'Æ' => 'ae',
		'ø' => 'oe', 'Ø' => 'oe',
		'å' => 'aa', 'Å' => 'aa',
		'œ' => 'oe', 'Œ' => 'oe',
		'&' => ' und ',
	];

	public function __construct(
		private readonly CompanyMapper $companyMapper,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Deterministic name → slug transform. Pure: no DB, no configuration.
	 */
	public static function slugify(string $companyName): string
	{
		$value = trim($companyName);
		$value = strtr($value, self::TRANSLITERATIONS);

		$decomposed = self::decompose($value);
		$lower = mb_strtolower($decomposed, 'UTF-8');

		// Every run of non-slug characters collapses to exactly one hyphen.
		$slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';
		$slug = trim($slug, '-');

		if ($slug === '') {
			$slug = 'co';
		}

		// Pad short slugs with '-co' until the 3-character floor is met.
		while (mb_strlen($slug) < self::MIN_LENGTH) {
			$slug .= '-co';
		}

		if (mb_strlen($slug) > self::MAX_LENGTH) {
			$slug = rtrim(mb_substr($slug, 0, self::MAX_LENGTH), '-');
		}

		// Truncation may drop below the floor again (e.g. "aa-------…").
		while (mb_strlen($slug) < self::MIN_LENGTH) {
			$slug .= '-co';
		}

		return $slug;
	}

	public static function isValid(string $slug): bool
	{
		return preg_match(self::PATTERN, $slug) === 1;
	}

	/**
	 * Slug for a new company: normalise, then walk `-2`, `-3`, … (base36) until
	 * the name is free. Client previews are advisory; this is authoritative.
	 *
	 * @throws ValidationException when 100 candidates are all taken
	 */
	public function suggestUnique(string $companyName): string
	{
		$base = self::slugify($companyName);
		if (!$this->companyMapper->slugExists($base)) {
			return $base;
		}

		for ($n = 2; $n < 2 + self::MAX_COLLISION_ATTEMPTS; $n++) {
			$candidate = $this->withSuffix($base, base_convert((string)$n, 10, 36));
			if (!$this->companyMapper->slugExists($candidate)) {
				return $candidate;
			}
		}

		throw ValidationException::field(
			Constants::ERR_SLUG_TAKEN,
			'slug',
			$this->l10n->t('Too many companies share this name. Enter a distinct slug manually.'),
		);
	}

	/**
	 * Validate a user-supplied slug and confirm it is still free.
	 *
	 * @throws ValidationException `SLUG_INVALID` on charset/length, `SLUG_TAKEN` on collision
	 */
	public function validateForCreate(string $slug): string
	{
		$slug = mb_strtolower(trim($slug));

		if (!self::isValid($slug)) {
			throw ValidationException::field(
				Constants::ERR_SLUG_INVALID,
				'slug',
				$this->l10n->t('Use 3 to 64 characters: lowercase letters, digits and hyphens only.'),
			);
		}

		if ($this->companyMapper->slugExists($slug)) {
			throw ValidationException::field(
				Constants::ERR_SLUG_TAKEN,
				'slug',
				$this->l10n->t('A company with this short address already exists. Open it instead of creating a duplicate.'),
			);
		}

		return $slug;
	}

	/**
	 * Append a collision suffix while respecting the 64-character ceiling.
	 */
	private function withSuffix(string $base, string $suffix): string
	{
		$maxBase = self::MAX_LENGTH - mb_strlen($suffix) - 1;
		$trimmed = rtrim(mb_substr($base, 0, max(1, $maxBase)), '-');
		if ($trimmed === '') {
			$trimmed = 'co';
		}

		return $trimmed . '-' . $suffix;
	}

	/**
	 * NFKD decomposition with combining marks removed, degrading gracefully when
	 * ext-intl is absent (it is not a hard requirement of this app).
	 */
	private static function decompose(string $value): string
	{
		if (class_exists(\Normalizer::class)) {
			$normalized = \Normalizer::normalize($value, \Normalizer::FORM_KD);
			if (is_string($normalized)) {
				$value = $normalized;
			}
		}

		$stripped = preg_replace('/\p{Mn}+/u', '', $value);

		return is_string($stripped) ? $stripped : $value;
	}
}
