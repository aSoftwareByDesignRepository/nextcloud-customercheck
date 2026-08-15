<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;

/**
 * Allowlist HTML sanitizer for compose, signatures, and stored inbound body_html.
 * Spec NN-10 / MH-16 — no script, handlers, or javascript: URLs.
 *
 * Normative allowlist (compose/body): p, br, div, span, strong, b, em, i, u,
 * ul, ol, li, a, h2, h3, blockquote, hr.
 * Signature-only (AS-18): http(s) img may appear inside signature HTML (no data:/javascript:).
 */
final class HtmlSanitizeService
{
	private const ALLOWED_TAGS = [
		'p' => true,
		'br' => true,
		'strong' => true,
		'b' => true,
		'em' => true,
		'i' => true,
		'u' => true,
		'ul' => true,
		'ol' => true,
		'li' => true,
		'a' => true,
		'blockquote' => true,
		'h2' => true,
		'h3' => true,
		'hr' => true,
		'div' => true,
		'span' => true,
	];

	private const SIGNATURE_EXTRA_TAGS = [
		'img' => true,
	];

	/**
	 * Sanitize compose / inbound body HTML.
	 */
	public function sanitize(?string $html): string
	{
		return $this->sanitizeInternal($html, false);
	}

	/**
	 * Sanitize signature HTML (allows https img — AS-18).
	 */
	public function sanitizeSignature(?string $html): string
	{
		$out = $this->sanitizeInternal($html, true);
		if (strlen($out) > 65536) {
			$out = substr($out, 0, 65536);
		}

		return $out;
	}

	/**
	 * Sanitize HTML; returns empty string when nothing safe remains.
	 * Caps output length to MAIL_BODY_MAX_BYTES.
	 */
	private function sanitizeInternal(?string $html, bool $allowSignatureImg): string
	{
		$raw = trim((string)$html);
		if ($raw === '') {
			return '';
		}
		if (strlen($raw) > Constants::MAIL_BODY_MAX_BYTES * 2) {
			$raw = substr($raw, 0, Constants::MAIL_BODY_MAX_BYTES * 2);
		}

		$raw = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link|base|svg|math)[^>]*>.*?</\1>#is', '', $raw) ?? '';
		$raw = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link|base|svg|math)\b[^>]*/?>#is', '', $raw) ?? '';

		$out = '';
		$offset = 0;
		$len = strlen($raw);
		while ($offset < $len) {
			if ($raw[$offset] !== '<') {
				$next = strpos($raw, '<', $offset);
				$chunk = $next === false ? substr($raw, $offset) : substr($raw, $offset, $next - $offset);
				$out .= htmlspecialchars($chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$offset = $next === false ? $len : $next;
				continue;
			}
			$end = strpos($raw, '>', $offset);
			if ($end === false) {
				$out .= htmlspecialchars(substr($raw, $offset), ENT_QUOTES | ENT_HTML5, 'UTF-8');
				break;
			}
			$tag = substr($raw, $offset, $end - $offset + 1);
			$offset = $end + 1;
			$out .= $this->sanitizeTag($tag, $allowSignatureImg);
		}

		if (strlen($out) > Constants::MAIL_BODY_MAX_BYTES) {
			$out = substr($out, 0, Constants::MAIL_BODY_MAX_BYTES);
		}

		return $out;
	}

	/**
	 * Plain-text twin for signature HTML (keeps img out of body sanitize path).
	 */
	public function signatureHtmlToPlain(string $html): string
	{
		$safe = $this->sanitizeSignature($html);
		$text = html_entity_decode(
			strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $safe)),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8',
		);
		$text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

		return trim($text);
	}

	/**
	 * Plain-text twin for multipart/alternative (strips tags after sanitize).
	 */
	public function htmlToPlain(string $html): string
	{
		$safe = $this->sanitize($html);
		$text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $safe)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

		return trim($text);
	}

	private function sanitizeTag(string $tag, bool $allowSignatureImg): string
	{
		$allowed = self::ALLOWED_TAGS;
		if ($allowSignatureImg) {
			$allowed = array_merge($allowed, self::SIGNATURE_EXTRA_TAGS);
		}
		if (preg_match('#^<\s*/\s*([a-zA-Z0-9]+)\s*>$#', $tag, $m)) {
			$name = strtolower($m[1]);
			return isset($allowed[$name]) ? '</' . $name . '>' : '';
		}
		if (!preg_match('#^<\s*([a-zA-Z0-9]+)(\s[^>]*)?\s*/?\s*>$#', $tag, $m)) {
			return '';
		}
		$name = strtolower($m[1]);
		if (!isset($allowed[$name])) {
			return '';
		}
		$selfClose = in_array($name, ['br', 'hr', 'img'], true);
		$attrs = '';
		if ($name === 'a' && isset($m[2])) {
			$href = $this->extractHref($m[2]);
			if ($href !== null) {
				$attrs = ' href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" rel="noopener noreferrer"';
			}
		}
		if ($name === 'img' && $allowSignatureImg && isset($m[2])) {
			$src = $this->extractHttpsSrc($m[2]);
			if ($src === null) {
				return '';
			}
			$alt = $this->extractImgAlt($m[2]);
			$width = $this->extractImgWidth($m[2]);
			$attrs = ' src="' . htmlspecialchars($src, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
				. ' alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
				. ' width="' . $width . '"';
		}
		if ($selfClose) {
			return '<' . $name . $attrs . '>';
		}

		return '<' . $name . $attrs . '>';
	}

	private function extractHref(string $attrBlob): ?string
	{
		if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrBlob, $m)
			&& !preg_match('/\bhref\s*=\s*([^\s>]+)/i', $attrBlob, $m)) {
			return null;
		}
		$href = html_entity_decode(trim($m[2] ?? $m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($href === '' || preg_match('#^\s*(javascript|data|vbscript)\s*:#i', $href)) {
			return null;
		}
		// Compose links: http(s)/mailto only (normative); relative /# kept for legacy inbound.
		if (!preg_match('~^(https?:|mailto:|/|#)~i', $href)) {
			return null;
		}

		return $href;
	}

	private function extractHttpsSrc(string $attrBlob): ?string
	{
		if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrBlob, $m)
			&& !preg_match('/\bsrc\s*=\s*([^\s>]+)/i', $attrBlob, $m)) {
			return null;
		}
		$src = html_entity_decode(trim($m[2] ?? $m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// AS-18: remote images only (no data:/javascript:). https preferred; http allowed for
		// self-hosted Nextcloud without TLS so local public File links still work.
		if ($src === '' || !preg_match('#^https?://#i', $src)) {
			return null;
		}
		if (preg_match('#^\s*(javascript|data|vbscript)\s*:#i', $src)) {
			return null;
		}
		// Refuse absurd / DoS URLs.
		if (strlen($src) > 2048) {
			return null;
		}

		return $src;
	}

	private function extractImgAlt(string $attrBlob): string
	{
		if (!preg_match('/\balt\s*=\s*(["\'])(.*?)\1/i', $attrBlob, $m)
			&& !preg_match('/\balt\s*=\s*([^\s>]+)/i', $attrBlob, $m)) {
			return 'Logo';
		}
		$alt = html_entity_decode(trim($m[2] ?? $m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$alt = preg_replace('/[\x00-\x1f\x7f]/u', '', $alt) ?? '';
		$alt = trim($alt);
		if ($alt === '') {
			return 'Logo';
		}

		return mb_substr($alt, 0, 120);
	}

	/**
	 * Cap display width for email clients (pixels). Default 180.
	 */
	private function extractImgWidth(string $attrBlob): int
	{
		if (!preg_match('/\bwidth\s*=\s*(["\']?)(\d+)\1/i', $attrBlob, $m)) {
			return 180;
		}
		$w = (int)$m[2];
		if ($w < 16) {
			return 16;
		}
		if ($w > 600) {
			return 600;
		}

		return $w;
	}
}
