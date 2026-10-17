<?php

declare(strict_types=1);

/**
 * Rebuild l10n/{lang}.js from JSON using PHP json_encode (slash escaping)
 * so L10nCoverageContractTest::testL10nJsRegistersMatchJson stays green.
 *
 * Locales: en, de, da, es, fr, it, nb, nl, pl, sv, pt, pt_BR.
 *
 * Usage: php scripts/rebuild-l10n-js.php
 */
$base = dirname(__DIR__) . '/l10n';
$locales = ['en', 'de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv', 'pt', 'pt_BR'];
// Regional variants (see scripts/sync-l10n-variants.php) — required so e.g. de_DE
// does not silently fall back to English while the rest of Nextcloud stays German.
$locales = array_merge($locales, ['en_GB', 'de_DE', 'es_EC', 'es_MX']);

foreach ($locales as $lang) {
	$jsonPath = $base . '/' . $lang . '.json';
	$jsPath = $base . '/' . $lang . '.js';
	if (!is_file($jsonPath)) {
		fwrite(STDERR, "Missing: {$jsonPath}\n");
		exit(1);
	}
	$json = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
	$translations = $json['translations'] ?? [];
	if (!is_array($translations)) {
		fwrite(STDERR, "Invalid translations in {$lang}.json\n");
		exit(1);
	}
	$keys = array_keys($translations);
	$last = count($keys) - 1;
	$body = "{\n";
	foreach ($keys as $i => $k) {
		$line = "\t\t" . json_encode($k, JSON_UNESCAPED_UNICODE)
			. ': ' . json_encode($translations[$k], JSON_UNESCAPED_UNICODE);
		if ($i !== $last) {
			$line .= ',';
		}
		$body .= $line . "\n";
	}
	$body .= "\t}";
	// CSP / Nextcloud ≥26: OC.L10N.register takes (appId, translations) only.
	// A third gettext pluralForm argument is compiled via new Function() by
	// legacy shims and violates strict script-src (no 'unsafe-eval'). Plural
	// rules come from getPlural() inside @nextcloud/l10n; the JSON catalogs
	// keep `pluralForm` as metadata only.
	$out = "OC.L10N.register(\n\t\"customercheck\",\n\t" . $body . "\n);\n";
	file_put_contents($jsPath, $out);
	fwrite(STDOUT, "{$lang}.js rewritten keys=" . count($translations) . "\n");
}

echo "l10n JS regeneration OK.\n";
