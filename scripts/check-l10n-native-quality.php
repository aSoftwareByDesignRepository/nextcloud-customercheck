<?php

declare(strict_types=1);

/**
 * Mother-tongue gate for CustomerCheck locales (da/de/es/fr/it/nb/nl/pl/pt_BR/sv).
 *
 * Fails when:
 * - user-facing prose is still identical to English (outside brand/token allowlist)
 * - banned English UI jargon appears in msgstr
 * - formal address appears where the style guide requires informal (du/tu/je/…)
 *
 * Usage: php scripts/check-l10n-native-quality.php
 */

$base = dirname(__DIR__) . '/l10n';
$en = json_decode((string) file_get_contents($base . '/en.json'), true, 512, JSON_THROW_ON_ERROR);

/** @var array<string, true> */
$allowExact = [
	'CustomerCheck' => true,
	'CustomerCheck Mobile' => true,
	'Software by Design · Check apps' => true,
	'ProjectCheck' => true,
	'TicketCheck' => true,
	'InvoiceCheck' => true,
	'BudgetCheck' => true,
	'Nextcloud' => true,
	'Nextcloud Mail' => true,
	'GitHub' => true,
	'Talk' => true,
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
	'Organisation' => true,
	'Overhead' => true,
	'Normal' => true,
	'Continue' => true,
	'Person' => true,
	'Team' => true,
	'Open' => true, // nl imperative "open" is spelled identically
	'package' => true, // fr loanword, matches "package partenaire" dependents
	'Website' => true, // de "die Website" is the dominant German term; all deps use Website-* compounds
	'System' => true,
	'Tickets' => true,
	'Timing' => true,
	'Webhook' => true,
	'Coach' => true,
	'Check Partner' => true,
	'CUC2.…' => true,
	'CUC2' => true,
	'Optional:' => true,
	'Required:' => true,
	'Selected:' => true,
	'Action' => true,
	'Actions' => true,
	'Attention' => true,
	'Catalogue' => true,
	'Date' => true,
	'Error' => true,
	'File' => true,
	'Files' => true,
	'Folder' => true,
	'Link' => true,
	'Manager' => true,
	'Note' => true,
	'Period' => true,
	'Review' => true,
	'Send' => true,
	'Type' => true,
	'Find: %s' => true,
	'File %s' => true,
	'%1$s: %2$s. %3$s. %4$s' => true,
	'%1$s — %2$s' => true,
	'Mail' => true,
	'Logo' => true,
	'Germany' => true,
	'Luxembourg' => true,
	'deal' => true,
	'Checklist: %1$s — %2$s' => true,
	'1 message' => true,
	'%s messages' => true,
	'Postcode' => true,
	'SSL (port 465)' => true,
	'SSL (port 993)' => true,
	'STARTTLS (port 143)' => true,
	'STARTTLS (port 587)' => true,
	'Google' => true,
	'Microsoft' => true,
	'Sync' => true,
	'Italic' => true,
	'Bold' => true,
	'Underline' => true,
	'Liechtenstein' => true,
	'MaintenanceCheck' => true,
	'To-dos' => true,
	'Follow-up' => true,
	'Account' => true,
	'Inbox' => true,
	'Personal' => true,
	'Message' => true,
	'Checklist' => true,
	'Checklists' => true,
	'Client secret' => true,
	'France' => true,
	'Austria' => true,
	'%1$s, %2$s deals, %3$s' => true,
	'%n item' => true,
	'%n items' => true,
	'Communication' => true,
	'Contact' => true,
	'Contacts' => true,
	'Context' => true,
	'Direction' => true,
	'Documents' => true,
	'Items' => true,
	'Label' => true,
	'Later' => true,
	'Minutes' => true,
	'Notes' => true,
	'Password' => true,
	'Plans' => true,
	'Possible' => true,
	'Product' => true,
	'Reference' => true,
	'Tasks' => true,
	'Week' => true,
	'Open deals' => true,
	'record' => true,
	'Cash' => true,
	'%s minutes' => true,
	'dd.mm.yyyy' => true,
	'Website' => true,
];

/** Banned English leftovers in any non-en msgstr. */
$bannedSubstrings = [
	'Team & mailbox',
	'Add reminder',
	'Edit mailbox',
	'Shared Mailbox',
	'Shared-Mailbox',
	'weekly digest',
	'Weekly digest',
	'opt-in',
	'Allowlist',
	'Manager-Override',
	'Manager override',
	'Workspace',
	'Appweiter',
	'Kundencheck noch heute',
	'Semmelbrösel',
	'Bereite importieren',
];

/**
 * Formal-address detectors (informal-only locales).
 * @var array<string, string>
 */
$formalPatterns = [
	'de' => '/\b(Sie|Ihnen|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres)\b/u',
	'da' => '/\b(De|Dem|Deres)\b/u',
	'nb' => '/\b(De|Dem|Deres)\b/u',
	'sv' => '/\b(Ni|Er|Ert|Era)\b/u',
	'nl' => '/(?:^|[^a-zA-Z])(u|Uw)(?:[^a-zA-Z]|$)/u',
	'es' => '/\b(usted|Usted|ustedes|Ustedes)\b/u',
	'fr' => '/\b(vous|votre|vos|Vous|Votre|Vos)\b/u',
	'it' => '/\b(Lei|Loro)\b/u',
	'pl' => '/\b(Pan|Pani|Państwo)\b/u',
	'pt_BR' => '/\b(senhor|senhora|Sr\.|Sra\.|Vossa|Vosso)\b/iu',
];

/** @var array<string, true> */
$allowFormalKeys = [
	'This marks the promise as cancelled. It will no longer appear as open.' => true,
];

$locales = ['de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'pt_BR', 'sv'];
$ok = true;

foreach ($locales as $lang) {
	$path = $base . '/' . $lang . '.json';
	$cat = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$identical = [];
	$bannedHits = [];
	$formalHits = [];

	foreach ($cat['translations'] as $key => $value) {
		if (!is_string($key) || !is_string($value)) {
			continue;
		}
		foreach ($bannedSubstrings as $bad) {
			if (str_contains($value, $bad)) {
				$bannedHits[] = "{$bad} ← {$value}";
			}
		}
		if (!($allowFormalKeys[$key] ?? false)
			&& isset($formalPatterns[$lang])
			&& preg_match($formalPatterns[$lang], $value) === 1) {
			$enKey = $en['translations'][$key] ?? $key;
			// da/nb: "De" is also third-person plural "they" (De vises / De har skrevet).
			if (in_array($lang, ['da', 'nb'], true)
				&& is_string($enKey)
				&& preg_match('/\b(Dem|Deres)\b/u', $value) !== 1
				&& (
					preg_match('/^(They|Those|These|The)\b/', $enKey) === 1
					|| preg_match('/\bDe (vises|har|skrev|skrevet|kommer|bliver|er)\b/u', $value) === 1
				)) {
				// third-person De — not formal address
			} else {
				$formalHits[] = "{$key}: {$value}";
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
		if (mb_strlen($value) <= 3) {
			continue;
		}
		$identical[] = $key;
	}

	if ($bannedHits !== [] || $formalHits !== [] || $identical !== []) {
		$ok = false;
		fwrite(STDERR, "== {$lang} ==\n");
		if ($bannedHits !== []) {
			fwrite(STDERR, '  Banned jargon (' . count($bannedHits) . "):\n");
			foreach (array_slice($bannedHits, 0, 20) as $line) {
				fwrite(STDERR, "    - {$line}\n");
			}
		}
		if ($formalHits !== []) {
			fwrite(STDERR, '  Formal address (' . count($formalHits) . "):\n");
			foreach (array_slice($formalHits, 0, 20) as $line) {
				fwrite(STDERR, "    - {$line}\n");
			}
		}
		if ($identical !== []) {
			fwrite(STDERR, '  Identical to English (' . count($identical) . "):\n");
			foreach (array_slice($identical, 0, 30) as $key) {
				fwrite(STDERR, "    - {$key}\n");
			}
		}
	} else {
		echo "l10n native quality OK: {$lang} (" . count($cat['translations']) . " keys)\n";
	}
}

if (!$ok) {
	fwrite(STDERR, "\nl10n native quality check FAILED.\n");
	exit(1);
}

echo 'l10n native quality OK for all locales (' . implode('/', $locales) . ").\n";
exit(0);
