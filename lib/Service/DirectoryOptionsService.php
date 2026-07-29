<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Directory listings for Settings → Access control (PSD §2.5).
 *
 * App-admin picker lists only Nextcloud admins (AZC rule). Groups are the
 * full searchable directory. Allowed-user chips are resolved by uid so the
 * UI can show display names for already-selected colleagues without dumping
 * every account into the HTML.
 */
class DirectoryOptionsService
{
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}

	/**
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
	 * Lightweight user search for the allowed-users picker.
	 *
	 * @return list<array{id: string, displayName: string}>
	 */
	public function searchUsers(string $query, int $limit = 20): array
	{
		$query = trim($query);
		if ($query === '' || $limit < 1) {
			return [];
		}
		$limit = min(50, $limit);
		$out = [];
		foreach ($this->userManager->search($query, $limit) as $user) {
			$userId = trim((string)$user->getUID());
			if ($userId === '') {
				continue;
			}
			$displayName = trim((string)$user->getDisplayName());
			$out[] = [
				'id' => $userId,
				'displayName' => $displayName !== '' ? $displayName : $userId,
			];
		}

		return $out;
	}
}
