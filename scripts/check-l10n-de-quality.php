<?php

declare(strict_types=1);

/**
 * Fails when German msgstr is still identical to English for user-facing prose,
 * or when known machine-translation calques land in the catalog.
 *
 * Example: "Advanced" must be "Erweitert", never "Fortschrittlich".
 *
 * Usage: php scripts/check-l10n-de-quality.php
 */

$base = dirname(__DIR__) . '/l10n';
$en = json_decode((string) file_get_contents($base . '/en.json'), true, 512, JSON_THROW_ON_ERROR);
$de = json_decode((string) file_get_contents($base . '/de.json'), true, 512, JSON_THROW_ON_ERROR);

/** @var array<string, true> Brand names, technical tokens, and bilingual allowlist. */
$allowExact = [
	'CustomerCheck' => true,
	'ProjectCheck' => true,
	'TicketCheck' => true,
	'InvoiceCheck' => true,
	'Nextcloud' => true,
	'CSV' => true,
	'PDF' => true,
	'API' => true,
	'URL' => true,
	'OCR' => true,
	'IBAN' => true,
	'JSON' => true,
	'HTML' => true,
	'SMTP' => true,
	'IMAP' => true,
	'UUID' => true,
	'CRM' => true,
	'OK' => true,
	'ID' => true,
	'Status' => true,
	'Name' => true,
	'Info' => true,
	'Export' => true,
	'Import' => true,
	'Filter' => true,
	'Deal' => true,
	'Deals' => true,
	'Board' => true,
	'Lead' => true,
	'Partner' => true,
	'Auto' => true,
	'App' => true,
	'Circle' => true,
	'Detail' => true,
	'Details' => true,
	'Feeds' => true,
	'Blocker' => true,
	'Champion' => true,
	'Forecast' => true,
	'Follow-up' => true,
	'Organisation' => true,
	'Overhead' => true,
	'Normal' => true,
	'Continue' => true,
	'Person' => true,
	'Team' => true,
	'System' => true,
	'Tickets' => true,
	'Timing' => true,
	'Webhook' => true,
	'CUC2.…' => true,
	'Optional:' => true,
	'Required:' => true,
	'Selected:' => true,
	// Placeholder-only format strings (no prose letters).
	'%1$s: %2$s. %3$s. %4$s' => true,
];

/**
 * Exact EN→DE pairs that must never regress to known-bad MT senses.
 * @var array<string, array{good: string, banned: list<string>}>
 */
$glossary = [
	'Advanced' => [
		'good' => 'Erweitert',
		'banned' => ['Fortschrittlich', 'Fortgeschritten'],
	],
	'Save' => [
		'good' => 'Speichern',
		'banned' => ['Sparen', 'Spare'],
	],
	'Cancel' => [
		'good' => 'Abbrechen',
		'banned' => ['Stornieren'],
	],
];

/** Substrings that must never appear in any German msgstr. */
$bannedSubstrings = [
	'Fortschrittlich',
	'Armaturenbrett',
	'Beladung',
];

$failures = [];
$bannedHits = [];
$glossaryHits = [];

foreach ($glossary as $key => $rule) {
	$got = $de['translations'][$key] ?? null;
	if (!is_string($got)) {
		$glossaryHits[] = "missing key {$key}";
		continue;
	}
	if ($got !== $rule['good']) {
		$glossaryHits[] = "{$key}: got \"{$got}\", want \"{$rule['good']}\"";
	}
	foreach ($rule['banned'] as $bad) {
		if ($got === $bad) {
			$glossaryHits[] = "{$key}: banned sense \"{$bad}\"";
		}
	}
}

foreach ($de['translations'] as $key => $value) {
	if (!is_string($value) || !is_string($key)) {
		continue;
	}
	foreach ($bannedSubstrings as $bad) {
		if (str_contains($value, $bad)) {
			$bannedHits[] = [$key, $value, $bad];
		}
	}

	$enValue = $en['translations'][$key] ?? $key;
	if (!is_string($enValue) || $value !== $enValue) {
		continue;
	}
	if ($allowExact[$value] ?? false) {
		continue;
	}
	if (!preg_match('/\p{L}/u', $value)) {
		continue;
	}
	if (preg_match('/^\d+\s*%$/', trim($value))) {
		continue;
	}
	// Very short tokens / codes
	if (mb_strlen($value) <= 3) {
		continue;
	}
	$failures[] = $key;
}

$ok = true;
if ($glossaryHits !== []) {
	$ok = false;
	fwrite(STDERR, "German glossary regressions (" . count($glossaryHits) . "):\n");
	foreach ($glossaryHits as $line) {
		fwrite(STDERR, "  - {$line}\n");
	}
}
if ($bannedHits !== []) {
	$ok = false;
	fwrite(STDERR, "Banned awkward German calques still present (" . count($bannedHits) . "):\n");
	foreach ($bannedHits as [$key, $value, $bad]) {
		fwrite(STDERR, "  - contains \"{$bad}\": {$value}\n");
	}
}
if ($failures !== []) {
	$ok = false;
	fwrite(STDERR, "German translations still identical to English (" . count($failures) . "):\n");
	foreach ($failures as $key) {
		fwrite(STDERR, '  - ' . $key . "\n");
	}
}

if (!$ok) {
	fwrite(STDERR, "\nl10n DE quality check FAILED.\n");
	exit(1);
}

echo 'l10n DE quality OK (' . count($de['translations']) . " keys; glossary locked; no banned calques; no leftover English prose).\n";
exit(0);
