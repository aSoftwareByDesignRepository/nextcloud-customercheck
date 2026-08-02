<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;

/**
 * Workspace capability matrix — product §3.2.
 *
 * App admins bypass membership for all workspaces.
 */
final class WorkspaceCapability
{
	/** @var array<string, list<string>> */
	private const MATRIX = [
		'viewer' => [
			'read', 'mail.read', 'review.read',
		],
		'member' => [
			// brief.confirm is limited: own commitments only (enforced in PersonaApiController).
			'read', 'write', 'mail.read', 'mail.send', 'review.read', 'brief.confirm',
		],
		'manager' => [
			'read', 'write', 'delete', 'move', 'members', 'mailbox',
			'mail.read', 'mail.send', 'review.read', 'brief.confirm', 'team.coach', 'bulk', 'ooo',
			'conflicts.resolve',
		],
	];

	public static function allows(string $role, string $capability, bool $isAppAdmin = false): bool
	{
		if ($isAppAdmin) {
			return true;
		}
		$caps = self::MATRIX[$role] ?? [];
		return in_array($capability, $caps, true);
	}

	public static function normalizeRole(string $role): string
	{
		$role = strtolower(trim($role));
		return in_array($role, Constants::WS_ROLES, true) ? $role : 'viewer';
	}

	/**
	 * Members see only self-row on Team; viewers blocked.
	 */
	public static function canOpenTeamBoard(string $role, bool $isAppAdmin = false): bool
	{
		if ($isAppAdmin) {
			return true;
		}
		return in_array($role, ['member', 'manager'], true);
	}

	public static function teamSeesAllRows(string $role, bool $isAppAdmin = false): bool
	{
		if ($isAppAdmin) {
			return true;
		}
		return $role === 'manager';
	}
}
