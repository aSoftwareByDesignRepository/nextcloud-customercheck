<?php

declare(strict_types=1);

/**
 * Rebuild l10n/{en,de}.js from JSON using PHP json_encode (slash escaping)
 * so L10nCoverageContractTest::testL10nJsRegistersMatchJson stays green.
 */
$base = dirname(__DIR__) . '/l10n';
foreach (['en', 'de'] as $lang) {
	$jsonPath = $base . '/' . $lang . '.json';
	$jsPath = $base . '/' . $lang . '.js';
	$json = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
	$translations = $json['translations'];
	$old = (string)file_get_contents($jsPath);
	if (!preg_match('/("nplurals=.*?")\s*\)\s*;\s*$/s', $old, $m)) {
		fwrite(STDERR, "no pluralForm in {$lang}.js\n");
		exit(1);
	}
	$plural = $m[1];
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
	$out = "OC.L10N.register(\n\t\"customercheck\",\n\t" . $body . ",\n\t" . $plural . "\n);\n";
	file_put_contents($jsPath, $out);
	fwrite(STDOUT, "{$lang}.js rewritten keys=" . count($translations) . "\n");
}
