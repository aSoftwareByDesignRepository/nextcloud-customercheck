<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Constants;

/**
 * Turn raw IMAP body bytes into a safe plain-text preview for CRM UI.
 * Never returns HTML — Assign/company feed must not inject markup.
 * Always returns JSON-safe UTF-8 (invalid sequences never reach JSONResponse).
 *
 * Handles multipart MIME (prefer text/plain, else stripped text/html),
 * quoted-printable / base64 part bodies, and HTML entity decoding.
 */
final class MailPreviewText
{
	/**
	 * Normalize a fetched body part into capped UTF-8 plain text.
	 */
	public static function toPlainPreview(string $raw, int $maxBytes = Constants::MAIL_PREVIEW_MAX_BYTES): string
	{
		$maxBytes = max(512, min(Constants::MAIL_PREVIEW_MAX_BYTES, $maxBytes));
		$raw = self::extractBestMimePart($raw);
		$raw = self::stripMimeDebris($raw);
		$raw = self::maybeDecodeTransfer($raw);
		$raw = self::stripHtml($raw);
		$raw = self::stripMimeDebris($raw);
		$raw = str_replace(["\r\n", "\r"], "\n", $raw);
		$raw = preg_replace("/[ \t]+\n/", "\n", $raw) ?? $raw;
		$raw = preg_replace("/\n{3,}/", "\n\n", $raw) ?? $raw;
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}
		// Sanitize before length checks so byte caps operate on valid UTF-8.
		$raw = self::ensureUtf8($raw);
		if ($raw === '') {
			return '';
		}
		if (strlen($raw) > $maxBytes) {
			$raw = substr($raw, 0, $maxBytes);
			// Avoid cutting mid-UTF-8 sequence.
			$raw = self::ensureUtf8($raw);
			$raw = rtrim($raw) . '…';
		}

		return $raw;
	}

	/**
	 * Prefer text/plain from multipart; fall back to text/html; otherwise raw.
	 * IMAP BODY.PEEK[TEXT] often returns the full multipart payload for
	 * multipart/alternative messages — never show that MIME soup in the UI.
	 */
	public static function extractBestMimePart(string $raw): string
	{
		$boundary = self::detectMultipartBoundary($raw);
		if ($boundary === null) {
			return $raw;
		}

		$plain = null;
		$html = null;
		foreach (self::splitMultipartParts($raw, $boundary) as $part) {
			[$headers, $body] = self::splitPartHeadersAndBody($part);
			if ($body === '') {
				continue;
			}
			$ct = self::headerValue($headers, 'content-type');
			$cte = self::headerValue($headers, 'content-transfer-encoding');
			$decoded = self::decodeByTransferEncoding($body, $cte);
			$ctLower = mb_strtolower($ct);
			if (str_starts_with($ctLower, 'text/plain')) {
				$plain = $decoded;
			} elseif (str_starts_with($ctLower, 'text/html')) {
				$html = $decoded;
			}
		}

		if (is_string($plain) && trim($plain) !== '') {
			return $plain;
		}
		if (is_string($html) && trim($html) !== '') {
			return $html;
		}

		// Nested multiparts / leaked tails: never return boundary soup to the UI.
		return self::stripMimeDebris($raw);
	}

	/**
	 * Cut leaked MIME boundaries / Content-* headers that often trail a readable
	 * plain body (forwarded Thunderbird/Outlook multipart/related leftovers).
	 * Safe on already-clean text: returns input unchanged when no MIME markers.
	 */
	public static function stripMimeDebris(string $raw): string
	{
		if ($raw === '') {
			return '';
		}
		$norm = str_replace(["\r\n", "\r"], "\n", $raw);
		// Cut at first MIME boundary line followed by a Content-* header (optionally quote-prefixed).
		if (preg_match(
			'/^(?:>\s*)?--[A-Za-z0-9\'()+_,\-\.\/:=?]{6,}\s*\n(?:>\s*)?Content-(?:Type|Transfer-Encoding|Disposition)\s*:/im',
			$norm,
			$m,
			PREG_OFFSET_CAPTURE
		) === 1) {
			$cut = (int)$m[0][1];
			if ($cut > 0) {
				$norm = substr($norm, 0, $cut);
			}
		}
		// Orphan trailing Content-Type: multipart… (no prior readable cut).
		if (preg_match(
			'/\n(?:>\s*)?Content-Type\s*:\s*multipart\/[^\n]*\n(?:>\s*)?\s*boundary\s*=/i',
			$norm,
			$m,
			PREG_OFFSET_CAPTURE
		) === 1) {
			$cut = (int)$m[0][1];
			if ($cut > 0) {
				$norm = substr($norm, 0, $cut);
			}
		}
		// Drop leftover standalone MIME header lines at the end.
		$norm = preg_replace(
			'/(?:\n(?:>\s*)?(?:Content-(?:Type|Transfer-Encoding|Disposition)\s*:[^\n]*|boundary\s*=\s*[^\n]*))+\s*$/i',
			'',
			$norm
		) ?? $norm;

		return rtrim($norm);
	}

	/**
	 * Guarantee a string is valid UTF-8 so Nextcloud JSONResponse (JSON_THROW_ON_ERROR)
	 * never fatals into an HTML 500 when encoding mail preview payloads.
	 *
	 * Prefer dropping invalid sequences (keep valid UTF-8 intact). If that yields empty
	 * content from a non-empty legacy body, reinterpret as Windows-1252 (common DE mail).
	 */
	public static function ensureUtf8(string $value): string
	{
		if ($value === '') {
			return '';
		}
		if (mb_check_encoding($value, 'UTF-8')) {
			return $value;
		}
		if (function_exists('iconv')) {
			$ignored = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
			if (is_string($ignored) && mb_check_encoding($ignored, 'UTF-8') && ($ignored !== '' || trim($value) === '')) {
				return $ignored;
			}
		}
		if (function_exists('mb_convert_encoding')) {
			$as1252 = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
			if (is_string($as1252) && mb_check_encoding($as1252, 'UTF-8')) {
				return $as1252;
			}
			$prev = null;
			if (function_exists('mb_substitute_character')) {
				$prev = mb_substitute_character();
				mb_substitute_character(0xFFFD);
			}
			$substituted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
			if ($prev !== null) {
				mb_substitute_character($prev);
			}
			if (is_string($substituted) && mb_check_encoding($substituted, 'UTF-8')) {
				return $substituted;
			}
		}
		// Last resort: printable ASCII + whitespace only (never invalid UTF-8).
		$ascii = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);

		return is_string($ascii) ? $ascii : '';
	}

	public static function stripHtml(string $raw): string
	{
		$trimmed = ltrim($raw);
		if ($trimmed === '') {
			return '';
		}
		// Any angle-bracket tag in the sample → treat as untrusted HTML (never return raw markup).
		$sample = substr($trimmed, 0, 8000);
		$hasTag = str_starts_with($trimmed, '<')
			|| preg_match('/<[a-z!\/?][\s\S]*>/i', $sample) === 1;
		if (!$hasTag) {
			return $raw;
		}
		// Drop executable / style blocks before strip_tags so their contents are not left as text.
		$scrubbed = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $raw) ?? $raw;
		$text = html_entity_decode(strip_tags($scrubbed), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return is_string($text) ? $text : strip_tags($raw);
	}

	/**
	 * Best-effort quoted-printable / base64 when Content-Transfer-Encoding
	 * markers appear in a raw part (IMAP BODY[TEXT] may still be encoded).
	 */
	private static function maybeDecodeTransfer(string $raw): string
	{
		if (preg_match('/^=\?[\w-]+\?[BQbq]\?/', $raw) === 1) {
			return MimeHeader::decode($raw);
		}
		// Soft QP: many soft line breaks (= at EOL).
		if (preg_match('/=(?:\r?\n|[0-9A-Fa-f]{2})/', $raw) === 1 && substr_count($raw, '=') >= 2) {
			$decoded = quoted_printable_decode($raw);
			if (is_string($decoded) && $decoded !== '') {
				return $decoded;
			}
		}

		return $raw;
	}

	private static function detectMultipartBoundary(string $raw): ?string
	{
		if (preg_match('/\bboundary\s*=\s*"([^"]+)"/i', $raw, $m) === 1) {
			return $m[1];
		}
		if (preg_match('/\bboundary\s*=\s*([^;\s]+)/i', $raw, $m) === 1) {
			return trim($m[1], " \t\"'");
		}
		// BODY[TEXT] often starts at the first boundary line (no outer MIME headers).
		if (preg_match('/^--([^\r\n]+)\r?$/m', $raw, $m) === 1) {
			$candidate = $m[1];
			if ($candidate !== '' && !str_ends_with($candidate, '--')) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private static function splitMultipartParts(string $raw, string $boundary): array
	{
		$delim = '--' . $boundary;
		$chunks = explode($delim, $raw);
		$parts = [];
		foreach ($chunks as $chunk) {
			$chunk = ltrim($chunk, "\r\n");
			if ($chunk === '' || str_starts_with($chunk, '--')) {
				continue;
			}
			$parts[] = $chunk;
		}

		return $parts;
	}

	/**
	 * @return array{0:string,1:string} [headers, body]
	 */
	private static function splitPartHeadersAndBody(string $part): array
	{
		$norm = str_replace(["\r\n", "\r"], "\n", $part);
		$pos = strpos($norm, "\n\n");
		if ($pos === false) {
			return ['', $part];
		}

		return [substr($norm, 0, $pos), substr($norm, $pos + 2)];
	}

	private static function headerValue(string $headers, string $name): string
	{
		$headers = str_replace(["\r\n", "\r"], "\n", $headers);
		// Unfold continued header lines.
		$headers = preg_replace("/\n[ \t]+/", ' ', $headers) ?? $headers;
		$name = preg_quote($name, '/');
		if (preg_match('/^' . $name . '\s*:\s*(.+)$/im', $headers, $m) !== 1) {
			return '';
		}

		return trim($m[1]);
	}

	private static function decodeByTransferEncoding(string $body, string $cte): string
	{
		$cte = mb_strtolower(trim($cte));
		if ($cte === 'base64') {
			$decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? $body, true);
			return is_string($decoded) ? $decoded : $body;
		}
		if ($cte === 'quoted-printable' || $cte === 'qp') {
			$decoded = quoted_printable_decode($body);
			return is_string($decoded) && $decoded !== '' ? $decoded : $body;
		}

		return $body;
	}
}
