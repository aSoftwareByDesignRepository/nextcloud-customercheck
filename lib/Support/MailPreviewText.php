<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCA\CustomerCheck\Constants;

/**
 * Turn raw IMAP body bytes into a safe plain-text preview for CRM UI.
 * Never returns HTML — Review/company feed must not inject markup.
 */
final class MailPreviewText
{
	/**
	 * Normalize a fetched body part into capped UTF-8 plain text.
	 */
	public static function toPlainPreview(string $raw, int $maxBytes = Constants::MAIL_PREVIEW_MAX_BYTES): string
	{
		$maxBytes = max(512, min(Constants::MAIL_PREVIEW_MAX_BYTES, $maxBytes));
		$raw = self::maybeDecodeTransfer($raw);
		$raw = self::stripHtml($raw);
		$raw = str_replace(["\r\n", "\r"], "\n", $raw);
		$raw = preg_replace("/[ \t]+\n/", "\n", $raw) ?? $raw;
		$raw = preg_replace("/\n{3,}/", "\n\n", $raw) ?? $raw;
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}
		if (strlen($raw) > $maxBytes) {
			$raw = substr($raw, 0, $maxBytes);
			// Avoid cutting mid-UTF-8 sequence.
			if (!mb_check_encoding($raw, 'UTF-8')) {
				$raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
			}
			$raw = rtrim($raw) . '…';
		}

		return $raw;
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
}
