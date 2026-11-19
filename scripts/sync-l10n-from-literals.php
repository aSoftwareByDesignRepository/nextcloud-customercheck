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
		// Shared-pack JS-visible msgids are declared as 'key' => 'msgid'
		// inside $i18nMsgids — live literals, not wrapped in ->t().
		if (preg_match_all('/\$i18nMsgids\s*=\s*\[(.*?)\];/s', $src, $blocks)) {
			foreach ($blocks[1] as $block) {
				if (preg_match_all("/=>\\s*'((?:\\\\'|[^'])*)'\\s*,/", $block, $m)) {
					foreach ($m[1] as $lit) {
						$found[] = stripcslashes($lit);
					}
				}
				if (preg_match_all('/=>\\s*"((?:\\\\"|[^"])*)"\\s*,/', $block, $m)) {
					foreach ($m[1] as $lit) {
						$found[] = stripcslashes($lit);
					}
				}
			}
		}
	}

	return array_values(array_unique($found));
}

function jsGlobs(string $root): array
{
	return array_merge(
		glob($root . '/js/*.js') ?: [],
		glob($root . '/js/pages/*.js') ?: [],
		glob($root . '/js/common/*.js') ?: [],
		glob($root . '/js/utils/*.js') ?: [],
	);
}

function extractJs(string $root): array
{
	$found = [];
	$globs = jsGlobs($root);
	foreach ($globs as $path) {
		$src = (string)file_get_contents($path);
		// fillOne(template, v) is the single-arg t() interpolation helper in
		// js/pages/import.js — its first arg is a live msgid.
		if (preg_match_all("/(?<![\\w.])(?:t|tr|fillOne)\\(\\s*'((?:\\\\'|[^'])*)'\\s*[,)]/", $src, $m)) {
			foreach ($m[1] as $lit) {
				$found[] = stripcslashes($lit);
			}
		}
		if (preg_match_all('/(?<![\\w.])(?:t|tr|fillOne)\\(\\s*"((?:\\\\"|[^\"])*)"\\s*[,)]/', $src, $m)) {
			foreach ($m[1] as $lit) {
				$found[] = stripcslashes($lit);
			}
		}
		// Dynamic-map pass: bare-string values inside lookup maps whose
		// subscripted result is rendered through t() — e.g. js/transfer.js
		// `const phase = PHASES[job.phase]` then `t(phase)` — invisible to the
		// call-shape patterns above but still runtime-translated literals.
		// Maps never reaching t() (icon/class/enum tables) stay invisible.
		if (preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*\{([^{}]*)\}/s', $src, $mm, PREG_SET_ORDER)) {
			foreach ($mm as $block) {
				$ident = $block[1];
				$body = $block[2];
				$direct = preg_match('/t\(\s*' . preg_quote($ident, '/') . '\s*\[/', $src) === 1;
				$aliased = !$direct
					&& preg_match('/(\w+)\s*=\s*' . preg_quote($ident, '/') . '\s*\[[^]]*\][\s\S]{0,400}?t\(\s*\1\b/', $src) === 1;
				if (!$direct && !$aliased) {
					continue;
				}
				if (preg_match_all('/[\'"]((?:[^\'"\\\\]|\\\\.)*)[\'"]/', $body, $vm)) {
					foreach ($vm[1] as $lit) {
						$found[] = stripcslashes($lit);
					}
				}
			}
		}
	}

	return array_values(array_unique($found));
}

/**
 * Bare n('singular', 'plural', count) JS calls — js/transfer.js wraps
 * window.n() so the literals sit on a local helper. Runtime resolves the
 * _sg_::_pl_ combined catalog key, so each pair is emitted as that key
 * with a [singular, plural] value (NC catalog convention).
 *
 * @return array<array{0: string, 1: string}>
 */
function extractJsPlurals(string $root): array
{
	$found = [];
	foreach (jsGlobs($root) as $path) {
		$src = (string)file_get_contents($path);
		if (preg_match_all("/(?<![\\w.])n\\(\\s*'((?:\\\\'|[^'])*)'\\s*,\\s*'((?:\\\\'|[^'])*)'\\s*,/", $src, $m)) {
			foreach ($m[1] as $i => $sg) {
				$found[] = [stripcslashes($sg), stripcslashes($m[2][$i])];
			}
		}
		if (preg_match_all('/(?<![\\w.])n\\(\\s*"((?:\\\\"|[^"])*)"\\s*,\\s*"((?:\\\\"|[^"])*)"\\s*,/', $src, $m)) {
			foreach ($m[1] as $i => $sg) {
				$found[] = [stripcslashes($sg), stripcslashes($m[2][$i])];
			}
		}
	}
	return $found;
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
	// Family-canonical tab indent — the license-page pack sync writes tab
	// (json.dumps indent="\t"), so 4-space PRETTY_PRINT churns whole files
	// on every emit. Fold each leading 4-space run back to tabs.
	$json = preg_replace_callback('/^ +/m', static fn ($m) => str_repeat("\t", intdiv(strlen($m[0]), 4)), $json);
	file_put_contents($root . '/l10n/' . $lang . '.json', $json);

	$entries = [];
	foreach ($translations as $k => $v) {
		// Family-canonical "key" : "value" — the shared license-page pack's
		// inject_js detects existing keys via that exact spacing.
		if (is_array($v)) {
			$entries[] = "\t\t\"" . jsEscape($k) . "\" : ["
				. implode(',', array_map(static fn ($cell) => '"' . jsEscape((string)$cell) . '"', $v))
				. "]";
		} else {
			$entries[] = "\t\t\"" . jsEscape($k) . "\" : \"" . jsEscape($v) . "\"";
		}
	}
	// CSP / Nextcloud ≥26: OC.L10N.register takes (appId, translations) only —
	// a third gettext pluralForm arg is eval'd via new Function() by legacy
	// shims and violates strict script-src. pluralForm stays in .json only.
	$lines = ["OC.L10N.register(", "\t\"customercheck\",", "\t{"];
	$lines[] = implode(",\n", $entries);
	$lines[] = "\t}";
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

// Plural pairs live under the _sg_::_pl_ combined key; identity value is
// the [singular, plural] pair, not the key string.
$pluralKeys = [];
foreach (extractJsPlurals($root) as [$sg, $pl]) {
	if ($sg === '' || $pl === '') {
		continue;
	}
	$key = '_' . $sg . '_::_' . $pl . '_';
	$pluralKeys[$key] = [$sg, $pl];
	$live[$key] = true;
}

$enTr = [];
$deTr = [];
$added = 0;
$pruned = 0;
foreach (array_keys($live) as $lit) {
	$identity = $pluralKeys[$lit] ?? $lit;
	$enTr[$lit] = $identity;
	if (!array_key_exists($lit, $enOld)) {
		$added++;
	}
	if (isset($deMap[$lit])) {
		$deTr[$lit] = $deMap[$lit];
	} elseif (array_key_exists($lit, $deOld) && $deOld[$lit] !== $identity) {
		$deTr[$lit] = $deOld[$lit];
	} else {
		$deTr[$lit] = $identity;
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

// Every maintained base catalog — pt/tr/en_GB/zh_CN/zh_TW were silently
// skipped before (gate_key_blindness roster gap); generated variants
// (de_DE/es_EC/es_MX) stay excluded — sync-l10n-variants.php rebuilds them.
$secondary = ['da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv', 'pt', 'pt_BR', 'tr', 'en_GB', 'zh_CN', 'zh_TW', 'ru'];
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
		$identity = $pluralKeys[$lit] ?? $lit;
		if (array_key_exists($lit, $old) && $old[$lit] !== $identity) {
			$out[$lit] = $old[$lit];
		} else {
			$out[$lit] = $identity;
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
