<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Mother-tongue gate must stay wired and must reject known English leftovers.
 */
final class L10nNativeQualityContractTest extends TestCase
{
	public function testNativeQualityScriptIsExecutableAndPasses(): void
	{
		$script = dirname(__DIR__, 2) . '/scripts/check-l10n-native-quality.php';
		self::assertFileExists($script);
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
		exec($cmd . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
	}

	public function testGlossaryPinsCrossLocaleMailTerms(): void
	{
		// Ships in docs/ — the l10n/ dir is catalog-only (hygiene gate bans docs there).
		$glossary = dirname(__DIR__, 2) . '/docs/l10n-glossary.md';
		self::assertFileExists($glossary);
		$body = (string) file_get_contents($glossary);
		foreach (['My email', 'Sales Inbox', 'Assign', 'Churned', 'Manager override', 'workspace', 'the invoicing app'] as $term) {
			self::assertStringContainsString($term, $body, "l10n-glossary.md must pin {$term}");
		}
	}

	/**
	 * Regression pin (l10n_parity_gap_after_copy class): a brand->generic msgid
	 * rename must carry its msgstrs. Every 'invoicing app' sibling-reference
	 * msgid must exist with a non-identity, canonical-term msgstr in each
	 * maintained non-en base catalog — identity/missing means English renders.
	 */
	public function testGenericSiblingRefsCarryNativeMsgstrs(): void
	{
		$root = dirname(__DIR__, 2) . '/l10n';
		$en = json_decode((string) file_get_contents($root . '/en.json'), true, 512, JSON_THROW_ON_ERROR);
		$family = array_values(array_filter(
			array_keys($en['translations'] ?? []),
			static fn (string $k): bool => str_contains(strtolower($k), 'invoicing app')
		));
		self::assertNotEmpty($family, 'expected the generic sibling-ref msgid family in en.json');

		$canonicalTerm = [
			'de' => 'Rechnungs-App', 'da' => 'fakturering', 'es' => 'facturación',
			'fr' => 'facturation', 'it' => 'fatturazione', 'nb' => 'fakturering',
			'nl' => 'facturatie', 'pl' => 'faktur', 'sv' => 'fakturering',
			'pt' => 'fatura', 'pt_BR' => 'fatura', 'tr' => 'faturalama',
			'zh_CN' => '开票', 'zh_TW' => '開票', 'ru' => 'счет',
		];
		foreach ($canonicalTerm as $lang => $term) {
			$cat = json_decode((string) file_get_contents($root . '/' . $lang . '.json'), true, 512, JSON_THROW_ON_ERROR)['translations'] ?? [];
			foreach ($family as $key) {
				self::assertArrayHasKey($key, $cat, "{$lang}.json dropped msgid: {$key}");
				self::assertNotSame('', $cat[$key], "{$lang}.json empty msgstr for {$key}");
				if ($key !== 'The invoicing app') {
					self::assertNotSame($key, $cat[$key], "{$lang}.json renders English for {$key}");
				}
				self::assertTrue(
					mb_stripos($cat[$key], $term) !== false,
					"{$lang}.json must use the pinned sibling-ref term '{$term}' for {$key}"
				);
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function locales(): array
	{
		return ['de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv'];
	}

	public function testBannedEnglishJargonAbsentInSecondaryLocales(): void
	{
		$root = dirname(__DIR__, 2) . '/l10n';
		$banned = [
			'Team & mailbox',
			'Shared Mailbox',
			'Shared-Mailbox',
			'opt-in',
			'Manager override',
			'Manager-Override',
			'Workspace',
			'Semmelbrösel',
		];
		foreach ($this->locales() as $lang) {
			$data = json_decode((string) file_get_contents($root . '/' . $lang . '.json'), true, 512, JSON_THROW_ON_ERROR);
			self::assertIsArray($data['translations'] ?? null);
			$blob = implode("\n", array_filter($data['translations'], 'is_string'));
			foreach ($banned as $bad) {
				self::assertStringNotContainsString(
					$bad,
					$blob,
					"{$lang}.json still contains banned jargon: {$bad}"
				);
			}
		}
	}

	public function testGermanUsesInformalDuNotFormalSieInMailShareCopy(): void
	{
		$data = json_decode(
			(string) file_get_contents(dirname(__DIR__, 2) . '/l10n/de.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$key = 'Everyone who can open a linked customer in this workspace can read these emails. Type SHARE to confirm Inbox or Sent sharing.';
		$got = $data['translations'][$key] ?? null;
		self::assertIsString($got);
		self::assertStringContainsString('Arbeitsbereich', $got);
		self::assertDoesNotMatchRegularExpression('/\b(Sie|Ihnen|Ihr)\b/u', $got);
	}
}
