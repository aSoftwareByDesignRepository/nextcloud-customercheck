<?php

declare(strict_types=1);

/**
 * WCAG 2.5.3 label-in-name gate for CustomerCheck locales.
 *
 * When a control's visible label is renamed in a locale, every string that
 * *references* that control must use the same term, or the translation breaks
 * twice at once:
 *
 *  - accessible-name strings (aria-label built from the same feature name) no
 *    longer contain the visible label text -> WCAG 2.5.3 violation;
 *  - help/toast copy tells the user to tap a label that does not exist.
 *
 * For every catalog (bases + regional variants) each dependent msgstr must
 * contain the msgstr of its label msgid.
 *
 * Added 2026-10-18 after the nl 'Checklist'->'Controlelijst' rename left
 * 'Checklist voor %s' + two tap-instruction sentences stale (same class was
 * live in es/fr/it/pl/pt_BR).
 *
 * Usage: php scripts/check-l10n-label-in-name.php
 */

$base = dirname(__DIR__) . '/l10n';

/** @var array<string, list<string>> label msgid => msgids whose msgstr must embed the label msgstr */
$labelRules = [
	'Checklist' => [
		'Checklist for %s',
		'Make a step list once. On any deal, tap Checklist → From template — no typing the same handoffs again.',
		'Template saved. Use it from any deal → Checklist → From template.',
	],
];

$failures = [];

foreach (glob($base . '/*.json') ?: [] as $path) {
	$locale = basename($path, '.json');
	$catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$translations = $catalog['translations'] ?? [];

	foreach ($labelRules as $labelKey => $dependentKeys) {
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
}

if ($failures !== []) {
	fwrite(STDERR, "l10n label-in-name check FAILED:\n");
	foreach ($failures as $line) {
		fwrite(STDERR, "  - {$line}\n");
	}
	exit(1);
}

echo 'l10n label-in-name OK (' . count(glob($base . '/*.json') ?: []) . " catalogs).\n";
exit(0);
