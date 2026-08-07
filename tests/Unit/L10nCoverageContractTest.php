<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * AC10: every literal passed to PHP $l->t() / ->n() and page JS t()/tr() must exist in en.json and de.json.
 */
final class L10nCoverageContractTest extends TestCase
{
	/** @return array{translations: array<string, string>, pluralForm?: string} */
	private function loadJson(string $lang): array
	{
		$path = dirname(__DIR__, 2) . '/l10n/' . $lang . '.json';
		$this->assertFileExists($path);
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($data);
		$this->assertArrayHasKey('translations', $data);
		$this->assertIsArray($data['translations']);

		return $data;
	}

	/** @return list<string> */
	private function extractPhpLiterals(): array
	{
		$root = dirname(__DIR__, 2);
		$found = [];
		$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		/** @var RegexIterator<SplFileInfo> $files */
		$files = new RegexIterator($rii, '/\.php$/');
		foreach ($files as $file) {
			/** @var SplFileInfo $file */
			$path = $file->getPathname();
			if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/node_modules/')) {
				continue;
			}
			$src = (string)file_get_contents($path);
			if (preg_match_all("/->t\\(\\s*'((?:\\\\'|[^'])*)'\\s*[,)]/", $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[] = stripcslashes($lit);
				}
			}
			if (preg_match_all("/->t\\(\\s*\"((?:\\\\\"|[^\"])*)\"\\s*[,)]/", $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[] = stripcslashes($lit);
				}
			}
			if (preg_match_all("/->n\\(\\s*'((?:\\\\'|[^'])*)'\\s*,\\s*'((?:\\\\'|[^'])*)'\\s*,/", $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[] = stripcslashes($lit);
				}
				foreach ($m[2] as $lit) {
					$found[] = stripcslashes($lit);
				}
			}
		}

		return array_values(array_unique($found));
	}

	/** @return list<string> */
	private function extractJsLiterals(): array
	{
		$dir = dirname(__DIR__, 2) . '/js/pages';
		$found = [];
		foreach (glob($dir . '/*.js') ?: [] as $path) {
			$src = (string)file_get_contents($path);
			// t('…') / tr('…') / t("…")
			// Local helpers are t()/tr(); exclude window.t('customercheck', …).
			if (preg_match_all("/(?<![\\w.])(?:t|tr)\\(\\s*'((?:\\\\'|[^'])*)'\\s*[,)]/", $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[] = stripcslashes($lit);
				}
			}
			if (preg_match_all("/(?<![\\w.])(?:t|tr)\\(\\s*\"((?:\\\\\"|[^\"])*)\"\\s*[,)]/", $src, $m)) {
				foreach ($m[1] as $lit) {
					$found[] = stripcslashes($lit);
				}
			}
		}

		return array_values(array_unique($found));
	}

	public function testEnAndDeHaveIdenticalTranslationKeys(): void
	{
		$en = $this->loadJson('en');
		$de = $this->loadJson('de');
		$enKeys = array_keys($en['translations']);
		$deKeys = array_keys($de['translations']);
		sort($enKeys);
		sort($deKeys);
		$this->assertSame($enKeys, $deKeys, 'en.json and de.json must have identical translation keys');
	}

	public function testPhpTemplateLiteralsExistInBothLocales(): void
	{
		$en = $this->loadJson('en')['translations'];
		$de = $this->loadJson('de')['translations'];
		$missingEn = [];
		$missingDe = [];
		$identityDe = [];
		foreach ($this->extractPhpLiterals() as $key) {
			if ($key === '') {
				continue;
			}
			if (!array_key_exists($key, $en)) {
				$missingEn[] = $key;
			}
			if (!array_key_exists($key, $de)) {
				$missingDe[] = $key;
			} elseif ($de[$key] === $key && str_contains($key, ' ') && preg_match('/[A-Za-z]{4,}/', $key)) {
				// Brand/single tokens may stay identical; multi-word English prose must be translated.
				$identityDe[] = $key;
			}
		}
		$this->assertSame([], $missingEn, 'Missing en.json keys for PHP ->t()/->n() literals');
		$this->assertSame([], $missingDe, 'Missing de.json keys for PHP ->t()/->n() literals');
		$this->assertSame(
			[],
			$identityDe,
			'Untranslated German multi-word strings (identity with EN): ' . implode(' | ', array_slice($identityDe, 0, 20))
		);
	}

	public function testPageJsLiteralsExistInBothLocales(): void
	{
		$en = $this->loadJson('en')['translations'];
		$de = $this->loadJson('de')['translations'];
		$missingEn = [];
		$missingDe = [];
		foreach ($this->extractJsLiterals() as $key) {
			if ($key === '') {
				continue;
			}
			if (!array_key_exists($key, $en)) {
				$missingEn[] = $key;
			}
			if (!array_key_exists($key, $de)) {
				$missingDe[] = $key;
			}
		}
		$this->assertSame([], $missingEn, 'Missing en.json keys for JS t()/tr() literals');
		$this->assertSame([], $missingDe, 'Missing de.json keys for JS t()/tr() literals');
	}

	public function testL10nJsRegistersMatchJson(): void
	{
		foreach (['en', 'de'] as $lang) {
			$json = $this->loadJson($lang)['translations'];
			$jsPath = dirname(__DIR__, 2) . '/l10n/' . $lang . '.js';
			$this->assertFileExists($jsPath);
			$js = (string)file_get_contents($jsPath);
			$this->assertStringContainsString('OC.L10N.register(', $js);
			$this->assertStringContainsString('"customercheck"', $js);
			foreach (array_slice(array_keys($json), 0, 25) as $key) {
				$this->assertStringContainsString(json_encode($key, JSON_UNESCAPED_UNICODE), $js);
			}
		}
	}

	public function testCatalogsContainOnlyLiveLiterals(): void
	{
		$live = array_fill_keys(
			array_filter(array_merge($this->extractPhpLiterals(), $this->extractJsLiterals())),
			true
		);
		$enKeys = array_keys($this->loadJson('en')['translations']);
		$orphans = array_values(array_filter($enKeys, static fn (string $k): bool => !isset($live[$k])));
		$this->assertSame(
			[],
			$orphans,
			'Orphan l10n keys (run php scripts/sync-l10n-from-literals.php): '
			. implode(' | ', array_slice($orphans, 0, 15))
		);

		$mapPath = dirname(__DIR__, 2) . '/tests/fixtures/l10n-de-map.json';
		$this->assertFileExists($mapPath);
		/** @var array<string, string> $map */
		$map = json_decode((string)file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);
		$mapOrphans = array_values(array_filter(array_keys($map), static fn (string $k): bool => !isset($live[$k])));
		$this->assertSame(
			[],
			$mapOrphans,
			'Orphan DE map keys: ' . implode(' | ', array_slice($mapOrphans, 0, 15))
		);
		$this->assertStringNotContainsString('"Job:', (string)file_get_contents($mapPath));
		$this->assertStringNotContainsString('"Job:', (string)file_get_contents(dirname(__DIR__, 2) . '/l10n/en.json'));
	}
}
