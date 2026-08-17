<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

use OCP\IRequest;

/**
 * Opt-in HTML islands for in-place list updates.
 *
 * Header-only: a query flag would let anyone bookmark a chrome-less page.
 * GET remains a read — same ACL as the full list page.
 *
 * Proxies sometimes strip unknown X-* headers. Prefer: return=minimal (RFC 7240)
 * plus X-Requested-With: XMLHttpRequest is the fallback. Prefer alone is not
 * enough — browsers do not send it on top-level navigation, but a stray client
 * might; XHR is required so a full document load never becomes chrome-less.
 */
final class ListFragmentRequest
{
	public const HEADER = 'X-CustomerCheck-Fragment';
	public const PREFER_HEADER = 'Prefer';
	public const REQUESTED_WITH_HEADER = 'X-Requested-With';
	public const PREFER_MINIMAL = 'return=minimal';

	public static function wants(?IRequest $request): bool
	{
		if ($request === null) {
			return false;
		}
		$raw = strtolower(trim((string)$request->getHeader(self::HEADER)));
		if ($raw === '1' || $raw === 'true') {
			return true;
		}
		return self::isXmlHttpRequest($request) && self::prefersMinimal($request);
	}

	public static function isXmlHttpRequest(?IRequest $request): bool
	{
		if ($request === null) {
			return false;
		}
		return strtolower(trim((string)$request->getHeader(self::REQUESTED_WITH_HEADER))) === 'xmlhttprequest';
	}

	public static function prefersMinimal(?IRequest $request): bool
	{
		if ($request === null) {
			return false;
		}
		$prefer = strtolower((string)$request->getHeader(self::PREFER_HEADER));
		if ($prefer === '') {
			return false;
		}
		foreach (preg_split('/\s*,\s*/', $prefer) ?: [] as $part) {
			$token = trim($part);
			if ($token === 'return=minimal' || $token === 'return="minimal"') {
				return true;
			}
		}

		return false;
	}

	public static function varyValue(): string
	{
		return self::HEADER . ', ' . self::PREFER_HEADER . ', ' . self::REQUESTED_WITH_HEADER;
	}
}
