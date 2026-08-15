<?php

declare(strict_types=1);

/**
 * Rebuild l10n/{lang}.js from JSON using PHP json_encode (slash escaping)
 * so L10nCoverageContractTest::testL10nJsRegistersMatchJson stays green.
 *
 * Locales match arbeitszeitcheck: en, de, da, es, fr, it, nb, nl, pl, sv.
 *
 * Usage: php scripts/rebuild-l10n-js.php
 */
$base = dirname(__DIR__) . '/l10n';
$locales = ['en', 'de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv', 'pt_BR'];
// Regional variants (see scripts/sync-l10n-variants.php) — required so e.g. de_DE
// does not silently fall back to English while the rest of Nextcloud stays German.
$locales = array_merge($locales, ['en_GB', 'de_DE', 'es_EC', 'es_MX']);

$defaultPlurals = [
	'en' => 'nplurals=2; plural=(n != 1);',
	'en_GB' => 'nplurals=2; plural=(n != 1);',
	'de' => 'nplurals=2; plural=(n != 1);',
	'de_DE' => 'nplurals=2; plural=(n != 1);',
	'da' => 'nplurals=2; plural=(n != 1);',
	'es' => 'nplurals=2; plural=(n != 1);',
	'es_EC' => 'nplurals=2; plural=(n != 1);',
	'es_MX' => 'nplurals=2; plural=(n != 1);',
	'fr' => 'nplurals=2; plural=(n > 1);',
	'it' => 'nplurals=2; plural=(n != 1);',
	'nb' => 'nplurals=2; plural=(n != 1);',
	'nl' => 'nplurals=2; plural=(n != 1);',
	'pl' => 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
	'sv' => 'nplurals=2; plural=(n != 1);',
];

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
	$plural = $json['pluralForm'] ?? $defaultPlurals[$lang] ?? null;
	if ($plural === null || $plural === '') {
		fwrite(STDERR, "no pluralForm for {$lang}\n");
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
	$out = "OC.L10N.register(\n\t\"customercheck\",\n\t" . $body . ",\n\t"
		. json_encode($plural, JSON_UNESCAPED_UNICODE) . "\n);\n";
	file_put_contents($jsPath, $out);
	fwrite(STDOUT, "{$lang}.js rewritten keys=" . count($translations) . "\n");
}

echo "l10n JS regeneration OK.\n";
