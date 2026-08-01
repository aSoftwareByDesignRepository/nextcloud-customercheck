<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Directory listings for Settings → Access control.
 *
 * App administrators and allowed users use search + chips over the full
 * directory (portfolio ACCESS-AND-DIRECTORY-PICKERS §2.1). Groups are a
 * searchable checkbox list. resolveUsers() paints display names for chips
 * already saved in config without dumping every account into the HTML.
 */
class DirectoryOptionsService
{
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * @deprecated Prefer resolveUsers() + directory search chips for dedicated app admins.
	 * Kept for any callers that still need the Nextcloud admin group roster.
	 *
	 * @return list<array{id: string, displayName: string}>
	 */
	public function availableAppAdmins(): array
	{
		$out = [];
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return [];
		}

		foreach ($adminGroup->getUsers() as $adminUser) {
			$userId = trim((string)$adminUser->getUID());
			if ($userId === '') {
				continue;
			}
			$displayName = trim((string)$adminUser->getDisplayName());
			$out[] = [
				'id' => $userId,
				'displayName' => $displayName !== '' ? $displayName : $userId,
			];
		}

		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}

	/**
	 * @return list<array{id: string, displayName: string}>
	 */
	public function availableGroups(): array
	{
		$out = [];
		try {
			foreach ($this->groupManager->search('') as $group) {
				$gid = (string)$group->getGID();
				if ($gid === '') {
					continue;
				}
				$displayName = trim((string)$group->getDisplayName());
				$out[] = [
					'id' => $gid,
					'displayName' => $displayName !== '' ? $displayName : $gid,
				];
			}
		} catch (\Throwable) {
			return [];
		}

		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}

	/**
	 * @param list<string> $uids
	 * @return list<array{id: string, displayName: string}>
	 */
	public function resolveUsers(array $uids): array
	{
		$out = [];
		foreach ($uids as $uid) {
			$uid = trim((string)$uid);
			if ($uid === '') {
				continue;
			}
			$user = $this->userManager->get($uid);
			$displayName = $user !== null ? trim((string)$user->getDisplayName()) : '';
			$out[] = [
				'id' => $uid,
				'displayName' => $displayName !== '' ? $displayName : $uid,
			];
		}

		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}

	/**
	 * Lightweight user search for people pickers (access, seats, workspace members).
	 *
	 * Merges uid search + display-name search (Nextcloud backends often miss names
	 * via search() alone). Skips disabled accounts.
	 *
	 * @return list<array{id: string, displayName: string}>
	 */
	public function searchUsers(string $query, int $limit = 20): array
	{
		$query = trim($query);
		if (mb_strlen($query) < 2 || $limit < 1) {
			return [];
		}
		$limit = min(50, max(1, $limit));
		$byId = $this->userManager->search($query, $limit, 0);
		$byName = $this->userManager->searchDisplayName($query, $limit, 0);
		$merged = [];
		foreach (array_merge($byId, $byName) as $user) {
			if ($user === null) {
				continue;
			}
			if (method_exists($user, 'isEnabled') && !$user->isEnabled()) {
				continue;
			}
			$userId = trim((string)$user->getUID());
			if ($userId === '' || isset($merged[$userId])) {
				continue;
			}
			$displayName = trim((string)$user->getDisplayName());
			$merged[$userId] = [
				'id' => $userId,
				'displayName' => $displayName !== '' ? $displayName : $userId,
			];
			if (count($merged) >= $limit) {
				break;
			}
		}

		return array_values($merged);
	}
}
