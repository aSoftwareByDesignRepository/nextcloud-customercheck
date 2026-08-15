<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Personal / Team mail visibility — CUC-PERSONAL-MAIL-1 §9 ACL.
 *
 * Private: owner only.
 * Shared + linked: company.read on that company.
 * Shared + unmatched: mail.read / mail.triage (caller supplies flag).
 */
final class MailAclLogic
{
	public const VISIBILITY_PRIVATE = 'private';
	public const VISIBILITY_SHARED = 'shared';

	public const KIND_PERSONAL = 'personal';
	public const KIND_TEAM = 'team';

	/**
	 * @param array{
	 *   visibility?: string,
	 *   ownerUserId?: ?string,
	 *   companyId?: ?int,
	 *   workspaceId?: int,
	 *   mailboxKind?: string
	 * } $message
	 * @param bool $canReadOrTriageMail For team unmatched: typically true after mail.read.
	 *                                 For personal unmatched shared (AS-12): must be write/triage.
	 */
	public static function canReadMessage(
		string $actorUserId,
		array $message,
		bool $canReadCompany,
		bool $canReadOrTriageMail,
	): bool {
		$actorUserId = trim($actorUserId);
		if ($actorUserId === '') {
			return false;
		}

		$visibility = strtolower(trim((string)($message['visibility'] ?? self::VISIBILITY_SHARED)));
		$owner = trim((string)($message['ownerUserId'] ?? ''));
		$companyId = isset($message['companyId']) ? (int)$message['companyId'] : 0;
		$mailboxKind = strtolower(trim((string)($message['mailboxKind'] ?? self::KIND_TEAM)));
		if ($mailboxKind === '') {
			$mailboxKind = self::KIND_TEAM;
		}

		if ($visibility === self::VISIBILITY_PRIVATE) {
			return $owner !== '' && hash_equals($owner, $actorUserId);
		}

		// Shared
		if ($owner !== '' && hash_equals($owner, $actorUserId)) {
			return true;
		}
		if ($companyId > 0) {
			return $canReadCompany;
		}

		// Unmatched shared: personal bodies → triage/write only (AS-12).
		// Team unmatched keeps classic shared-mail rule: mail.read is enough (caller already gated).
		if ($mailboxKind === self::KIND_PERSONAL) {
			return $canReadOrTriageMail;
		}

		return true;
	}

	/**
	 * Ingest visibility for a message based on account kind + folder share flag.
	 */
	public static function visibilityForIngest(string $accountKind, bool $folderShareWithWorkspace): string
	{
		$kind = strtolower(trim($accountKind));
		if ($kind === self::KIND_TEAM) {
			return self::VISIBILITY_SHARED;
		}
		if ($kind === self::KIND_PERSONAL && $folderShareWithWorkspace) {
			return self::VISIBILITY_SHARED;
		}

		return self::VISIBILITY_PRIVATE;
	}

	public static function normalizeVisibility(string $raw): string
	{
		$v = strtolower(trim($raw));
		return $v === self::VISIBILITY_PRIVATE ? self::VISIBILITY_PRIVATE : self::VISIBILITY_SHARED;
	}

	/**
	 * Failed-draft ACL: team rows are workspace-visible; personal and orphan
	 * (missing mailbox join / empty kind) are creator or mailbox-owner only.
	 */
	public static function actorMayAccessFailedDraft(
		string $actor,
		?string $mailboxKind,
		?string $createdBy,
		?string $mailboxOwner,
	): bool {
		$actor = trim($actor);
		if ($actor === '') {
			return false;
		}
		$kind = strtolower(trim((string)$mailboxKind));
		$createdBy = trim((string)$createdBy);
		$owner = trim((string)$mailboxOwner);
		if ($kind === self::KIND_TEAM) {
			return true;
		}
		// personal OR unknown/orphan — fail closed
		if ($createdBy !== '' && hash_equals($createdBy, $actor)) {
			return true;
		}
		if ($owner !== '' && hash_equals($owner, $actor)) {
			return true;
		}

		return false;
	}

	/**
	 * AS-12 list redaction for personal unmatched when caller lacks triage/write.
	 * Keeps id / mailboxId / kind / sentAt so triage UI can still count work;
	 * strips subject, from, snippet, preview, and identifying mailbox labels.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	public static function redactPersonalUnmatchedListItem(array $item, string $needsTriageLabel): array
	{
		$kind = strtolower(trim((string)($item['mailboxKind'] ?? $item['kind'] ?? '')));
		if ($kind !== self::KIND_PERSONAL) {
			return $item;
		}
		$item['subject'] = $needsTriageLabel;
		$item['from'] = '';
		$item['snippet'] = '';
		$item['preview'] = '';
		$item['forwardFromHints'] = [];
		$item['mailboxEmail'] = '';
		$item['mailboxLabel'] = $needsTriageLabel;
		$item['redacted'] = true;

		return $item;
	}
}
