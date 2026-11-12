<?php

declare(strict_types=1);

/**
 * Ensures translated strings keep the same printf-style placeholders as their msgid.
 * Named placeholders like {project} are also checked.
 *
 * Maintained base catalogs: en, de, da, es, fr, it, nb, nl, pl, sv, pt, pt_BR,
 * tr, zh_CN, zh_TW, en_GB. Generated variants (de_DE/es_EC/es_MX) are verified
 * by scripts/sync-l10n-variants.php --check.
 *
 * Exit 0 = OK, 1 = mismatch printed to STDERR.
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['en', 'de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv', 'pt', 'pt_BR', 'tr', 'zh_CN', 'zh_TW', 'en_GB'];

// Guard the roster's input domain both ways: a shipped base catalog
// l10n/<locale>.json that is not rostered must FAIL (silent-skip blindness —
// zh_CN/zh_TW shipped unrostered, gate_key_blindness), and a rostered locale
// whose catalog is missing FAILS in the load loop below. Generated regional
// variants (sync-l10n-variants.php) are derived copies verified by that
// script's own --check, so they are exempted here — a newly shipped catalog
// still FATALs until it is classified base-or-variant. en_GB is a maintained
// catalog (real British forms), not a generated variant — it stays rostered.
$generatedVariants = [
	'de_DE', 'es_EC', 'es_MX',
];
$shipped = [];
foreach (glob($base . '/*.json') ?: [] as $jsonFile) {
	$loc = basename($jsonFile, '.json');
	if (!in_array($loc, $generatedVariants, true)) {
		$shipped[] = $loc;
	}
}
$unrostered = array_values(array_diff($shipped, $localeFiles));
if ($unrostered !== []) {
	sort($unrostered);
	fwrite(STDERR, 'Shipped catalogs missing from placeholder-check roster: ' . implode(', ', $unrostered) . "\n");
	exit(1);
}

/**
 * Placeholder tokens understood by the app's t()/n() helpers:
 *  - `%%` literal percent escape
 *  - `%n` Nextcloud plural counter (replaced before vsprintf runs)
 *  - `%s` / `%d` / `%1$s` … positional printf placeholders
 *
 * @return list<string>
 */
function cucPrintfPlaceholders(string $s): array {
	preg_match_all('/%%|%n|%(?:\d+\$)?[sd]/', $s, $m);

	return $m[0];
}

/**
 * True when the string still contains a bare `%` after every valid token is
 * stripped. PHP's vsprintf treats e.g. `% d` as a (space-padded) conversion and
 * throws on trailing `%`, so a stray percent in a translation is a fatal 500
 * waiting to happen (see 2026-09-26: `it` "%n days" => "% dei giorni" 500).
 */
function cucHasStrayPercent(string $s): bool {
	$stripped = preg_replace('/%%|%n|%(?:\d+\$)?[sd]/', '', $s);

	return $stripped !== null && str_contains($stripped, '%');
}

/**
 * @return list<string>
 */
function cucNamedPlaceholders(string $s): array {
	preg_match_all('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', $s, $m);

	return $m[0];
}

$catalogs = [];
foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$catalogs[$lang] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$enT = $catalogs['en']['translations'] ?? [];
$failed = false;

foreach ($enT as $key => $enVal) {
	$keyPrintf = cucPrintfPlaceholders((string) $key);
	$keyNamed = cucNamedPlaceholders((string) $key);

	foreach ($localeFiles as $lang) {
		$langT = $catalogs[$lang]['translations'] ?? [];
		if (!isset($langT[$key])) {
			continue;
		}
		$val = (string) $langT[$key];

		$valPrintf = cucPrintfPlaceholders($val);
		// Multiset compare: positional %N$s tokens exist so a locale may reorder
		// arguments (zh renders "%2$s 的 %1$s"); order is not a defect. Missing
		// or added tokens still fail.
		if (array_count_values($keyPrintf) != array_count_values($valPrintf)) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json printf placeholder mismatch for key: $key\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $keyPrintf) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valPrintf) . "\n");
		}

		if (cucHasStrayPercent($val)) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json stray '%' (would fatal in vsprintf) for key: $key\n");
			fwrite(STDERR, "  value: $val\n");
		}

		$valNamed = cucNamedPlaceholders($val);
		if (array_count_values($keyNamed) != array_count_values($valNamed)) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json named placeholder mismatch for key: $key\n");
			fwrite(STDERR, '  expected: ' . implode(', ', $keyNamed) . "\n");
			fwrite(STDERR, '  got:      ' . implode(', ', $valNamed) . "\n");
		}
	}
}

if ($failed) {
	fwrite(STDERR, "\nl10n placeholder check FAILED.\n");
	exit(1);
}

echo 'l10n placeholder check OK (' . implode('/', $localeFiles) . " printf and named placeholders match msgids).\n";
exit(0);
