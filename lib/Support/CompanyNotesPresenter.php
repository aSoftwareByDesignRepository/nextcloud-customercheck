<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Company.notes is a free-text field that website-order import also fills with
 * machine fingerprints (order id, term, lang). Those belong on the edit form,
 * not in the 360 identity header a person has to read.
 */
final class CompanyNotesPresenter
{
	public static function publicHeadline(?string $notes): string
	{
		$text = trim((string)$notes);
		if ($text === '') {
			return '';
		}
		$lines = preg_split('/\R/u', $text);
		if (!is_array($lines)) {
			return '';
		}
		$kept = [];
		foreach ($lines as $raw) {
			$line = trim((string)$raw);
			if ($line === '') {
				continue;
			}
			if (self::isMachineLine($line)) {
				continue;
			}
			$kept[] = $line;
		}

		return implode("\n", $kept);
	}

	public static function isMachineLine(string $line): bool
	{
		$line = trim($line);
		if ($line === '') {
			return false;
		}
		// OrderProcessor CSV: "website-order {id} | starts … | term 12m | lang en"
		if (preg_match('/^website-order\b/i', $line) === 1 && str_contains($line, '|')) {
			return true;
		}
		// ImportService flatten: "Website order {orderId}"
		if (preg_match('/^website order\s+\S+/i', $line) === 1) {
			return true;
		}
		$hasStarts = preg_match('/\bstarts\s+\d{4}-\d{2}-\d{2}\b/i', $line) === 1;
		$hasTerm = preg_match('/\bterm\s+\d+\s*m\b/i', $line) === 1;
		$hasLang = preg_match('/\blang\s+[a-z]{2}\b/i', $line) === 1;

		return $hasStarts && $hasTerm && $hasLang;
	}
}
