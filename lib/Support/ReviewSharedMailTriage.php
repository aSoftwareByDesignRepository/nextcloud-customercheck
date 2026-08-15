<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Pure triage helpers for shared-mail Assign rows.
 *
 * Keeps inbox list / reconcile decisions free of “open Assign for gone IMAP”
 * ghosts that resurface when the page window shifts after deletes.
 */
final class ReviewSharedMailTriage
{
	public const DEAD_STATUSES = ['remote_deleted', 'discarded'];

	/**
	 * Mail row is gone from CRM triage (deleted on IMAP, discarded, or missing).
	 *
	 * @param array<string, mixed>|false|null $mailRow
	 */
	public static function isMailboxGone(array|false|null $mailRow): bool
	{
		if ($mailRow === false || $mailRow === null) {
			return true;
		}
		$status = strtolower(trim((string)($mailRow['triage_status'] ?? '')));

		return in_array($status, self::DEAD_STATUSES, true);
	}

	/**
	 * CRM-first outbound index (synthetic outbound: uid and/or direction=outbound)
	 * must never stay in Assign — IMAP delete/junk cannot dial those ids.
	 *
	 * @param array<string, mixed>|false|null $mailRow
	 */
	public static function isCrmOutboundNotForAssign(array|false|null $mailRow): bool
	{
		if ($mailRow === false || $mailRow === null) {
			return false;
		}
		$uid = trim((string)($mailRow['message_uid'] ?? $mailRow['messageUid'] ?? ''));
		if (str_starts_with($uid, 'outbound:')) {
			return true;
		}
		$direction = strtolower(trim((string)($mailRow['direction'] ?? '')));

		return $direction === 'outbound';
	}

	/**
	 * Mail is already filed on a company — Assign should close as assigned.
	 *
	 * @param array<string, mixed>|false|null $mailRow
	 */
	public static function isMatchedForAutoClose(array|false|null $mailRow): bool
	{
		if ($mailRow === false || $mailRow === null) {
			return false;
		}
		if (strtolower(trim((string)($mailRow['triage_status'] ?? ''))) !== 'matched') {
			return false;
		}

		return (int)($mailRow['company_id'] ?? 0) > 0;
	}

	/**
	 * @param array<string, mixed>|false|null $mailRow
	 * @return 'dead'|'matched'|'keep'
	 */
	public static function openReviewAction(array|false|null $mailRow): string
	{
		if (self::isMailboxGone($mailRow)) {
			return 'dead';
		}
		if (self::isMatchedForAutoClose($mailRow)) {
			return 'matched';
		}
		// After matched-with-company: drop CRM-sent copies that never belong in Assign.
		if (self::isCrmOutboundNotForAssign($mailRow)) {
			return 'dead';
		}

		return 'keep';
	}

	/**
	 * Stable fetch window size so sentAt sort is not starved by a tiny updated_at page.
	 */
	public static function fetchWindow(int $limit, bool $dateOnlyQuery): int
	{
		$limit = max(1, min(100, $limit));
		if ($dateOnlyQuery) {
			return max($limit, min(500, max($limit * 5, 200)));
		}

		// Over-fetch so dead-mail drops + sentAt ordering still fill a full page.
		return max($limit, min(500, max($limit * 3, 150)));
	}
}
