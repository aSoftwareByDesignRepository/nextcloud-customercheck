<?php

declare(strict_types=1);

/**
 * WCAG 2.5.3 label-in-name gate for CustomerCheck locales (same-class sweep).
 *
 * When a control's visible label is renamed in a locale, every string that
 * *references* that control must use the same term, or the translation breaks
 * twice at once:
 *
 *  - accessible-name strings (aria-label built from the same feature name) no
 *    longer contain the visible label text -> WCAG 2.5.3 violation;
 *  - help/toast copy tells the user to tap a label that does not exist.
 *
 * Sweep: every label actually declared in code (t()/p() calls in templates,
 * lib and js) whose msgstr was renamed (msgstr !== EN term) is cross-checked
 * against every dependent msgid whose English text mentions the label term.
 * A dependent is a violation when its msgstr still contains the EN label term
 * as a word while the localized label term is absent — i.e. the UI still
 * tells the user to look for a label that no longer exists.
 *
 * Legitimate retainers are allow-listed below with reasons: Nextcloud app
 * names (Mail/Files/Contacts/Tasks), product names (Check Partner), noun/verb
 * splits (Import/Export statuses), feature labels that keep the EN term
 * themselves (Manager tools, Account-Pläne), and grammar inflections.
 *
 * History: 2026-10-18 — nl 'Checklist'->'Controlelijst' left dependents stale;
 * same class then swept across all catalogs (Assign/Coach/Today/Money/Inbox/
 * Access mode/deal/record/… terminology forks fixed in 16 locales + mobile).
 *
 * Usage: php scripts/check-l10n-label-in-name.php
 */

$base = dirname(__DIR__);
$l10n = $base . '/l10n';

/** Labels whose EN term may legitimately appear in dependents (whole-label allow). */
$allowLabels = [
	// Dependents name the Nextcloud *app*, not this control.
	'Mail' => 'dependents reference the Nextcloud Mail app (or "mail" = e-mail in da/sv)',
	'Files' => 'dependents reference the Nextcloud Files app',
	'Contacts' => 'dependents reference the Nextcloud Contacts app',
	'Tasks' => 'dependents reference the Nextcloud Tasks app',
	'Calendar' => 'dependents reference the Nextcloud Calendar app',
	'Talk' => 'dependents may name the Nextcloud Talk app',
	// Product names that stay literal in every locale.
	'Partner' => 'dependents name the Check Partner product',
	// The referenced features keep the EN term in their own labels.
	'Manager' => 'dependents name Manager tools / Manager override, whose own labels keep "Manager"',
	'Account' => 'dependents name Account plan(s), whose own labels keep "Account-…" (de/da/nb)',
	'License' => '"License Ops" is retained as a name in every locale',
	// Noun-form reuse: "Import failed" is a status noun, not the verb control.
	'Import' => 'dependents use the noun "Import" (status), not the verb label',
	'Export' => 'dependents use the noun "Export" (status), not the verb label',
	// Grammar: "To" inside "To-dos" (own label) and Polish pronoun "To".
	'To' => 'hits are the "To-dos" feature name and the Polish pronoun "To"',
	// Grammar: Scandinavian "all e-post" is correct for a mass noun.
	'All' => 'nb/sv "All e-post" is the correct inflection of the same term',
	// Word-order variant: fr badge label is "Messages %s"; "messages" is French.
	'%s messages' => 'fr dependents keep the natural "%s messages …" word order',
];

/** Exact (locale, label, dependent) triples that are legit retainers. */
$allowPairs = [
	// "der Link" is the German hyperlink noun, unrelated to the "Verknüpfen" verb control.
	['de', 'Link', 'The ProjectCheck customer was created, but CustomerCheck could not save the link. Link the existing ProjectCheck customer on retry.'],
	['de', 'Link', 'The TicketCheck customer was created, but CustomerCheck could not save the link. Link the existing TicketCheck customer on retry.'],
	['de_DE', 'Link', 'The ProjectCheck customer was created, but CustomerCheck could not save the link. Link the existing ProjectCheck customer on retry.'],
	['de_DE', 'Link', 'The TicketCheck customer was created, but CustomerCheck could not save the link. Link the existing TicketCheck customer on retry.'],
	// "Open source" — not the "Open" action.
	['*', 'Open', 'Open-source care on GitHub stays free'],
	// Dutch "help" is the verb (imperative), not the "Hulp" label.
	['nl', 'Help', 'Help teammate'],
];

/** @var array<string, list<string>> label msgid => msgids whose msgstr must embed the label msgstr */
$explicitRules = [
	'Checklist' => [
		'Checklist for %s',
		'Make a step list once. On any deal, tap Checklist → From template — no typing the same handoffs again.',
		'Template saved. Use it from any deal → Checklist → From template.',
	],
];

/** Collect msgids declared in t()/p() calls across templates, lib and js. */
$declared = [];
$scanDirs = [$base . '/templates', $base . '/lib', $base . '/js'];
foreach ($scanDirs as $dir) {
	if (!is_dir($dir)) {
		continue;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		if (!preg_match('/\.(php|js)$/', $file->getFilename())) {
			continue;
		}
		$src = (string) file_get_contents($file->getPathname());
		preg_match_all("/[tp]\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*(?:,\\s*'((?:[^'\\\\]|\\\\.)*)')?/", $src, $m);
		foreach ($m[1] as $i => $first) {
			$msgid = ($m[2][$i] !== '') ? $m[2][$i] : $first;
			$declared[str_replace("\\'", "'", $msgid)] = true;
		}
	}
}

function wordHit(string $term, string $haystack, bool $ci = false): bool {
	if ($term === '') {
		return false;
	}
	$flags = $ci ? 'iu' : 'u';
	return (bool) preg_match('/(?<![\\w])' . preg_quote($term, '/') . '(?![\\w])/' . $flags, $haystack);
}

$en = json_decode((string) file_get_contents($l10n . '/en.json'), true, 512, JSON_THROW_ON_ERROR)['translations'] ?? [];

// Build label => dependents map from the EN catalog.
$labelDeps = [];
foreach ($declared as $msgid => $_) {
	$term = $en[$msgid] ?? $msgid;
	if (!is_string($term) || strlen($term) > 40 || isset($allowLabels[$msgid])) {
		continue;
	}
	$deps = [];
	foreach ($en as $k => $v) {
		if ($k !== $msgid && is_string($v) && wordHit($term, $v)) {
			$deps[] = $k;
		}
	}
	if ($deps !== []) {
		$labelDeps[$msgid] = [$term, $deps];
	}
}

$allowed = static function (string $locale, string $label, string $dep) use ($allowPairs): bool {
	foreach ($allowPairs as [$l, $lb, $d]) {
		if (($l === '*' || $l === $locale) && $lb === $label && $d === $dep) {
			return true;
		}
	}
	return false;
};

$failures = [];

foreach (glob($l10n . '/*.json') ?: [] as $path) {
	$locale = basename($path, '.json');
	if (str_starts_with($locale, 'en')) {
		continue;
	}
	$catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$translations = $catalog['translations'] ?? [];

	// Explicit containment rules (label msgstr must appear in dependent msgstr).
	foreach ($explicitRules as $labelKey => $dependentKeys) {
		$label = $translations[$labelKey] ?? null;
		if (!is_string($label) || $label === '') {
			continue;
		}
		foreach ($dependentKeys as $depKey) {
			$dep = $translations[$depKey] ?? null;
			if ($dep === null) {
				continue;
			}
			if (!is_string($dep) || !str_contains($dep, $label)) {
				$failures[] = "{$locale}: \"{$depKey}\" => \"{$dep}\" does not contain the visible label \"{$label}\"";
			}
		}
	}

	// Same-class sweep: renamed label => no dependent may retain the EN term.
	foreach ($labelDeps as $msgid => [$term, $deps]) {
		$label = $translations[$msgid] ?? null;
		if (!is_string($label) || $label === '' || $label === $term) {
			continue; // label not renamed in this locale
		}
		foreach ($deps as $depKey) {
			if ($allowed($locale, $msgid, $depKey)) {
				continue;
			}
			$dep = $translations[$depKey] ?? null;
			if (!is_string($dep) || $dep === '' || !wordHit($term, $dep)) {
				continue;
			}
			if (wordHit($label, $dep, true)) {
				continue; // dependent already uses the localized term (inflections OK)
			}
			$failures[] = "{$locale}: dependent of \"{$msgid}\" (label \"{$label}\") still says \"{$term}\": \"{$depKey}\" => \"" . mb_substr($dep, 0, 80) . '"';
		}
	}
}

if ($failures !== []) {
	fwrite(STDERR, "l10n label-in-name check FAILED:\n");
	foreach ($failures as $line) {
		fwrite(STDERR, "  - {$line}\n");
	}
	exit(1);
}

echo 'l10n label-in-name OK (' . count(glob($l10n . '/*.json') ?: []) . ' catalogs, ' . count($labelDeps) . " swept labels).\n";
exit(0);
