#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sync L10n catalogs from PHP ->t()/->n() and js/pages|common|utils t()/tr() literals.
 *
 * Catalogs are rebuilt from live literals only (orphans pruned).
 * German: DE_MAP wins, then existing non-identity DE, else identity.
 * The DE map itself is pruned to live keys so it cannot reintroduce dead strings.
 * Secondary locales (da/es/fr/it/nb/nl/pl/sv): keep prior non-identity
 * translations for live keys; new keys fall back to English until MT refresh.
 */

$root = dirname(__DIR__);

function extractPhp(string $root): array
{
	$found = [];
	$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
	foreach (new RegexIterator($rii, '/\.php$/') as $file) {
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

function extractJs(string $root): array
{
	$found = [];
	$globs = array_merge(
		glob($root . '/js/pages/*.js') ?: [],
		glob($root . '/js/common/*.js') ?: [],
		glob($root . '/js/utils/*.js') ?: [],
	);
	foreach ($globs as $path) {
		$src = (string)file_get_contents($path);
		if (preg_match_all("/(?<![\\w.])(?:t|tr)\\(\\s*'((?:\\\\'|[^'])*)'\\s*[,)]/", $src, $m)) {
			foreach ($m[1] as $lit) {
				$found[] = stripcslashes($lit);
			}
		}
		if (preg_match_all('/(?<![\\w.])(?:t|tr)\\(\\s*"((?:\\\\"|[^\"])*)"\\s*[,)]/', $src, $m)) {
			foreach ($m[1] as $lit) {
				$found[] = stripcslashes($lit);
			}
		}
	}

	return array_values(array_unique($found));
}

function jsEscape(string $s): string
{
	return str_replace(
		['\\', '"', "\n", "\r", "\t", '/'],
		['\\\\', '\\"', '\\n', '\\r', '\\t', '\\/'],
		$s,
	);
}

function writeL10n(string $root, string $lang, array $translations, ?string $pluralForm): void
{
	ksort($translations, SORT_STRING);
	$payload = ['translations' => $translations];
	if ($pluralForm !== null && $pluralForm !== '') {
		$payload['pluralForm'] = $pluralForm;
	}
	$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
	file_put_contents($root . '/l10n/' . $lang . '.json', $json);

	$entries = [];
	foreach ($translations as $k => $v) {
		$entries[] = "\t\t\"" . jsEscape($k) . "\": \"" . jsEscape($v) . "\"";
	}
	$lines = ["OC.L10N.register(", "\t\"customercheck\",", "\t{"];
	$lines[] = implode(",\n", $entries);
	if ($pluralForm !== null && $pluralForm !== '') {
		$lines[] = "\t},";
		$lines[] = "\t\"" . jsEscape($pluralForm) . "\"";
	} else {
		$lines[] = "\t}";
	}
	$lines[] = ");";
	file_put_contents($root . '/l10n/' . $lang . '.js', implode("\n", $lines) . "\n");
}

$deMapPath = $root . '/tests/fixtures/l10n-de-map.json';
if (!is_file($deMapPath)) {
	fwrite(STDERR, "Missing $deMapPath\n");
	exit(1);
}
/** @var array<string, string> $deMap */
$deMap = json_decode((string)file_get_contents($deMapPath), true, 512, JSON_THROW_ON_ERROR);

$enPrev = json_decode((string)file_get_contents($root . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
$dePrev = json_decode((string)file_get_contents($root . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
$enOld = is_array($enPrev['translations'] ?? null) ? $enPrev['translations'] : [];
$deOld = is_array($dePrev['translations'] ?? null) ? $dePrev['translations'] : [];

$live = [];
foreach (array_merge(extractPhp($root), extractJs($root)) as $lit) {
	if ($lit === '') {
		continue;
	}
	$live[$lit] = true;
}

$enTr = [];
$deTr = [];
$added = 0;
$pruned = 0;
foreach (array_keys($live) as $lit) {
	$enTr[$lit] = $lit;
	if (!array_key_exists($lit, $enOld)) {
		$added++;
	}
	if (isset($deMap[$lit])) {
		$deTr[$lit] = $deMap[$lit];
	} elseif (array_key_exists($lit, $deOld) && $deOld[$lit] !== $lit) {
		$deTr[$lit] = $deOld[$lit];
	} else {
		$deTr[$lit] = $lit;
	}
}
foreach (array_keys($enOld) as $oldKey) {
	if (!isset($live[$oldKey])) {
		$pruned++;
	}
}

// Keep DE map limited to live keys so orphans cannot re-enter on the next sync.
$mapLive = [];
$mapPruned = 0;
foreach ($deMap as $k => $v) {
	if (isset($live[$k])) {
		$mapLive[$k] = $v;
	} else {
		$mapPruned++;
	}
}
ksort($mapLive, SORT_STRING);
file_put_contents(
	$deMapPath,
	json_encode($mapLive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

writeL10n($root, 'en', $enTr, $enPrev['pluralForm'] ?? null);
writeL10n($root, 'de', $deTr, $dePrev['pluralForm'] ?? null);

$secondary = ['da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv', 'pt_BR'];
$secondaryCounts = [];
foreach ($secondary as $lang) {
	$path = $root . '/l10n/' . $lang . '.json';
	if (!is_file($path)) {
		continue;
	}
	$prev = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$old = is_array($prev['translations'] ?? null) ? $prev['translations'] : [];
	$out = [];
	foreach (array_keys($live) as $lit) {
		if (array_key_exists($lit, $old) && $old[$lit] !== $lit) {
			$out[$lit] = $old[$lit];
		} else {
			$out[$lit] = $lit;
		}
	}
	writeL10n($root, $lang, $out, $prev['pluralForm'] ?? null);
	$secondaryCounts[$lang] = count($out);
}

fwrite(
	STDERR,
	'L10n sync: added ' . $added
	. ' EN keys; pruned ' . $pruned
	. ' orphans; map pruned ' . $mapPruned
	. '; en=' . count($enTr)
	. ' de=' . count($deTr)
	. ' map=' . count($mapLive)
	. ( $secondaryCounts !== [] ? '; secondary=' . implode(',', array_map(
		static fn (string $l, int $n): string => $l . '=' . $n,
		array_keys($secondaryCounts),
		array_values($secondaryCounts),
	)) : '')
	. "\n"
);

// Keep regional variants (de_DE, en_GB, …) in lockstep with base catalogs.
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/sync-l10n-variants.php'), $variantExit);
if ($variantExit !== 0) {
	exit($variantExit === 0 ? 1 : $variantExit);
}
