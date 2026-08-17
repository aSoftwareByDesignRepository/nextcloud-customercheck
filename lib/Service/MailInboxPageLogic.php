<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Opaque cursor for Sales Inbox list pages (sentAt DESC, id DESC).
 *
 * Cursor form: `{sentAt}|{id}` — same shape as CommunicationFeedService.
 * Security: cursor is only a resume token inside the caller’s already-scoped query
 * (workspace + tab ACL). It must never widen visibility.
 */
final class MailInboxPageLogic
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 100;
	/** Max SQL rounds when PHP-side ACL skips thin out a page. */
	public const MAX_FETCH_PASSES = 5;

	/**
	 * Unknown / empty tabs fall closed to personal (never “all workspace mail”).
	 */
	public static function normalizeTab(string $tab): string
	{
		$tab = strtolower(trim($tab));

		return match ($tab) {
			'team' => 'team',
			'unmatched' => 'unmatched',
			default => 'mine',
		};
	}

	/**
	 * Stable cursor timestamp — DB drivers may return string or DateTimeInterface.
	 */
	public static function normalizeSentAtForCursor(mixed $sentAt): string
	{
		if ($sentAt === null || $sentAt === '') {
			return '';
		}
		if ($sentAt instanceof \DateTimeInterface) {
			return $sentAt->format('Y-m-d H:i:s');
		}
		$s = trim((string)$sentAt);
		if ($s === '') {
			return '';
		}
		if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $s, $m) === 1) {
			return $m[1] . ' ' . $m[2];
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
			return $s . ' 00:00:00';
		}

		return '';
	}

	public static function isValidCursorSentAt(string $sentAt): bool
	{
		if ($sentAt === '') {
			return true;
		}

		return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sentAt) === 1;
	}

	/**
	 * @return ?string null when id is invalid
	 */
	public static function encodeCursor(?string $sentAt, int $id): ?string
	{
		if ($id <= 0) {
			return null;
		}
		$at = self::normalizeSentAtForCursor($sentAt);
		if (!self::isValidCursorSentAt($at)) {
			$at = '';
		}

		return $at . '|' . $id;
	}

	/**
	 * @return array{sentAt: string, id: int}|null
	 */
	public static function decodeCursor(?string $cursor): ?array
	{
		$cursor = trim((string)$cursor);
		if ($cursor === '' || !str_contains($cursor, '|')) {
			return null;
		}
		if (strlen($cursor) > 96) {
			return null;
		}
		[$at, $idPart] = explode('|', $cursor, 2);
		if ($idPart === '' || !ctype_digit($idPart)) {
			return null;
		}
		$id = (int)$idPart;
		if ($id <= 0) {
			return null;
		}
		if (!self::isValidCursorSentAt($at)) {
			return null;
		}

		return ['sentAt' => $at, 'id' => $id];
	}

	public static function clampLimit(int $limit): int
	{
		return max(1, min(self::MAX_LIMIT, $limit > 0 ? $limit : self::DEFAULT_LIMIT));
	}

	/**
	 * Over-fetch multiplier so rare PHP-side ACL skips still fill a page + one peek row.
	 */
	public static function fetchWindow(int $limit): int
	{
		$limit = self::clampLimit($limit);

		return ($limit + 1) * 3;
	}

	/**
	 * @param list<array<string, mixed>> $accepted Including the optional peek row past the page
	 * @return array{items: list<array<string, mixed>>, nextCursor: ?string, hasMore: bool}
	 */
	public static function pageResult(array $accepted, int $limit): array
	{
		$limit = self::clampLimit($limit);
		$hasMore = count($accepted) > $limit;
		$items = array_values(array_slice($accepted, 0, $limit));
		$nextCursor = null;
		if ($hasMore && $items !== []) {
			$last = $items[array_key_last($items)];
			$nextCursor = self::encodeCursor(
				$last['sentAt'] ?? '',
				(int)($last['id'] ?? 0),
			);
			if ($nextCursor === null) {
				$hasMore = false;
			}
		}

		return [
			'items' => $items,
			'nextCursor' => $nextCursor,
			'hasMore' => $hasMore && $nextCursor !== null,
		];
	}
}
