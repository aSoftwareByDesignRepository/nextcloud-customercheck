<?php

declare(strict_types=1);

/**
 * Ensures translated strings keep the same printf-style placeholders as their msgid.
 * Named placeholders like {project} are also checked.
 *
 * Locales match arbeitszeitcheck: en, de, da, es, fr, it, nb, nl, pl, sv.
 *
 * Exit 0 = OK, 1 = mismatch printed to STDERR.
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['en', 'de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv'];

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
		if ($keyPrintf !== $valPrintf) {
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
		if ($keyNamed !== $valNamed) {
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
