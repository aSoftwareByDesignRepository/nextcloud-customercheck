<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Minimal RFC 5545 serialisation helpers.
 *
 * Both consumers (the renewal ICS feed of §7.1 and the VTODO push of §7.2) emit
 * a handful of properties, so hand-rolling the escaping and folding keeps the
 * output auditable — and keeps a customer name containing a comma or a newline
 * from producing an unparsable calendar.
 */
final class Ics
{
	/**
	 * Escape a TEXT value: backslash, semicolon, comma and newline (RFC 5545 §3.3.11).
	 */
	public static function escapeText(string $value): string
	{
		$value = str_replace("\r\n", "\n", $value);
		$value = str_replace("\r", "\n", $value);

		return str_replace(
			['\\', ';', ',', "\n"],
			['\\\\', '\\;', '\\,', '\\n'],
			$value,
		);
	}

	/**
	 * Join content lines with CRLF and fold anything longer than 75 octets
	 * (RFC 5545 §3.1). Folding counts bytes, and a continuation must not split a
	 * UTF-8 sequence, so multi-byte characters are kept whole.
	 *
	 * @param list<string> $lines
	 */
	public static function fold(array $lines): string
	{
		$out = [];
		foreach ($lines as $line) {
			foreach (self::foldLine($line) as $piece) {
				$out[] = $piece;
			}
		}

		return implode("\r\n", $out) . "\r\n";
	}

	/**
	 * @return list<string>
	 */
	private static function foldLine(string $line): array
	{
		if (strlen($line) <= 75) {
			return [$line];
		}

		$pieces = [];
		$current = '';
		$limit = 75;
		$length = mb_strlen($line, 'UTF-8');

		for ($i = 0; $i < $length; $i++) {
			$char = mb_substr($line, $i, 1, 'UTF-8');
			if (strlen($current) + strlen($char) > $limit) {
				$pieces[] = $current;
				$current = ' ' . $char;
				// Continuation lines carry a leading space that counts toward the limit.
				$limit = 75;
				continue;
			}
			$current .= $char;
		}

		if ($current !== '') {
			$pieces[] = $current;
		}

		return $pieces;
	}

	private function __construct()
	{
	}

	/**
	 * @param list<string> $extraLines
	 */
	public static function vevent(string $uid, string $summary, string $dateYmd, string $description = '', array $extraLines = []): string
	{
		$lines = [
			'BEGIN:VEVENT',
			'UID:' . self::escapeText($uid),
			'DTSTAMP:' . gmdate('Ymd\THis\Z'),
			'DTSTART;VALUE=DATE:' . $dateYmd,
			'SUMMARY:' . self::escapeText($summary),
		];
		if ($description !== '') {
			$lines[] = 'DESCRIPTION:' . self::escapeText($description);
		}
		foreach ($extraLines as $line) {
			$lines[] = $line;
		}
		$lines[] = 'END:VEVENT';

		return self::fold($lines);
	}

	/**
	 * @param list<string> $vevents Already-folded VEVENT blobs (may contain CRLF)
	 */
	public static function calendar(string $name, array $vevents): string
	{
		$header = self::fold([
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Software by Design//CustomerCheck//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escapeText($name),
		]);
		$body = implode('', $vevents);
		$footer = self::fold(['END:VCALENDAR']);

		return $header . $body . $footer;
	}
}
