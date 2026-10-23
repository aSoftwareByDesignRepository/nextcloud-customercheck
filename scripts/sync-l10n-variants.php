<?php

declare(strict_types=1);

/**
 * Generate regional variant catalogs (l10n/{variant}.json) from base catalogs.
 *
 * Why: Nextcloud resolves the UI language **per app** (`Factory::findLanguage($appId)`).
 * When a user picks e.g. "Deutsch (Deutschland)" (`de_DE`) and this app only ships
 * `de.json`, the server does **not** fall back to `de` — it falls through to
 * Accept-Language / English. Core Nextcloud stays German while CustomerCheck
 * renders in English. Shipping a real catalog for every core-selectable variant
 * of a language we support makes the account language always win.
 *
 * Variants are value-identical copies of their base catalog (keys unchanged).
 * Matching .js files are emitted by scripts/rebuild-l10n-js.php.
 *
 * Usage:
 *   php scripts/sync-l10n-variants.php           # (re)write variant json files
 *   php scripts/sync-l10n-variants.php --check   # exit 1 when files are missing or stale
 */

$base = dirname(__DIR__) . '/l10n';
$check = in_array('--check', $argv ?? [], true);

/** @var array<string,string> variant code => base catalog code */
$variants = [
	// en_GB is NOT generated: it is a maintained catalog with real British
	// forms (licence, recognise, …). L10nCoverageContractTest keeps its key
	// set in parity with en.json.
	'de_DE' => 'de',
	'es_EC' => 'es',
	'es_MX' => 'es',
];

$drift = [];

foreach ($variants as $variant => $baseLang) {
	$basePath = $base . '/' . $baseLang . '.json';
	if (!is_file($basePath)) {
		fwrite(STDERR, "Missing base catalog: {$basePath}\n");
		exit(1);
	}
	$baseCatalog = json_decode((string) file_get_contents($basePath), true, 512, JSON_THROW_ON_ERROR);
	$variantCatalog = $baseCatalog;

	$encoded = json_encode($variantCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		fwrite(STDERR, "Failed to encode {$variant}.json\n");
		exit(1);
	}
	// Catalog convention is tab indentation; json_encode pretty-prints with 4 spaces per level.
	$encoded = preg_replace_callback(
		'/^ +/m',
		static fn (array $m): string => str_repeat("\t", intdiv(strlen($m[0]), 4)),
		$encoded
	);
	$expected = $encoded . "\n";
	$variantPath = $base . '/' . $variant . '.json';

	if ($check) {
		if (!is_file($variantPath)) {
			$drift[] = "{$variant}.json is missing";
		} elseif ((string) file_get_contents($variantPath) !== $expected) {
			$drift[] = "{$variant}.json is stale (regenerate with scripts/sync-l10n-variants.php)";
		}
		if (!is_file($base . '/' . $variant . '.js')) {
			$drift[] = "{$variant}.js is missing (regenerate with scripts/rebuild-l10n-js.php)";
		}
		continue;
	}

	file_put_contents($variantPath, $expected);
	$keyCount = count($variantCatalog['translations'] ?? []);
	echo "Wrote l10n/{$variant}.json ({$keyCount} keys, from {$baseLang}.json)\n";
}

if ($check) {
	if ($drift !== []) {
		fwrite(STDERR, "l10n variant check FAILED:\n");
		foreach ($drift as $line) {
			fwrite(STDERR, "  - {$line}\n");
		}
		exit(1);
	}
	echo 'l10n variants OK (' . count($variants) . " variant catalogs match their base).\n";
	exit(0);
}

echo "l10n variant generation OK. Now run: php scripts/rebuild-l10n-js.php\n";
