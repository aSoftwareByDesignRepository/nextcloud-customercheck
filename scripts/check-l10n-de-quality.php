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
	'CustomerCheck Mobile' => true,
	'MaintenanceCheck' => true,
	'dd.mm.yyyy' => true,
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
	'Leads' => true,
	'Microsoft' => true,
	'Sync' => true,
	'Underline' => true,
	'Italic' => true,
	'Bold' => true,
	'Google' => true,
	'Liechtenstein' => true,
	'Partner' => true,
	'Check Partner' => true,
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
	'%1$s: %2$s. %3$s. %4$s' => true,
	'Mail' => true,
	'Logo' => true,
	'Germany' => true,
	'Luxembourg' => true,
	'deal' => true,
	'SSL (port 465)' => true,
	'SSL (port 993)' => true,
	'STARTTLS (port 143)' => true,
	'STARTTLS (port 587)' => true,
	'Account' => true,
	'Inbox' => true,
	'Personal' => true,
	'Message' => true,
	'Checklist' => true,
	'Checklists' => true,
	'Client secret' => true,
	'France' => true,
	'Austria' => true,
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
	'Churned' => [
		'good' => 'Abgewandert',
		'banned' => ['Aufgewühlt', 'Abgewechselt', 'Churned'],
	],
];

/** Substrings that must never appear in any German msgstr. */
$bannedSubstrings = [
	'Fortschrittlich',
	'Armaturenbrett',
	'Beladung',
	'Workspace',
	'Appweiter',
	'Allowlist',
	'Kundencheck noch heute',
	'Aufgewühlt',
];

/**
 * Regexes for broken machine-translation grammar (wrong imperative + du, haben/hast).
 * @var list<string>
 */
$bannedPatterns = [
	'/\b(?:Verbinden|Verknüpfen|Halten|Beschränken|Behalten|Filtern|Durchsuchen|Kündigen|Zeigen|Senden|Kopieren|Führen|Importieren|Entscheiden|Springen|Erledigen|Warten|Suchen|Korrigieren) du\b/u',
	'/\bdu (?:es )?lesen können\b/u',
	'/\bdu [^"]{0,80}?\b(?:angenommen|protokolliert|geschrieben|gewählt) haben\b/u',
	'/\bwenn du [^"]{0,40}\bbenötigen\b/u',
	'/\bdamit du [^"]{0,40}\bwissen,/u',
	'/\bwenn du absichtlich [^"]{0,40}\bwollen\b/u',
];

/**
 * Formal address must not appear in UI copy (Bachus / granny: consistent du).
 * Allowlisted keys keep a correct feminine/neuter "Sie" (= it) or similar.
 *
 * @var array<string, true>
 */
$allowFormalSieKeys = [
	'This marks the promise as cancelled. It will no longer appear as open.' => true,
];

$failures = [];
$bannedHits = [];
$patternHits = [];
$glossaryHits = [];
$formalHits = [];

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
	foreach ($bannedPatterns as $pattern) {
		if (preg_match($pattern, $value) === 1) {
			$patternHits[] = [$key, $value, $pattern];
		}
	}

	if (!($allowFormalSieKeys[$key] ?? false)
		&& preg_match('/\b(Sie|Ihnen|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres)\b/u', $value) === 1) {
		$formalHits[] = [$key, $value];
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
if ($patternHits !== []) {
	$ok = false;
	fwrite(STDERR, "Broken German grammar patterns still present (" . count($patternHits) . "):\n");
	foreach ($patternHits as [$key, $value, $pattern]) {
		fwrite(STDERR, "  - matches {$pattern}: {$value}\n");
	}
}
if ($formalHits !== []) {
	$ok = false;
	fwrite(STDERR, "Formal Sie-address still present (" . count($formalHits) . ") — use consistent du:\n");
	foreach ($formalHits as [$key, $value]) {
		fwrite(STDERR, "  - {$key}: {$value}\n");
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

echo 'l10n DE quality OK (' . count($de['translations']) . " keys; glossary locked; no banned calques; no broken du-grammar; consistent du; no leftover English prose).\n";
exit(0);
