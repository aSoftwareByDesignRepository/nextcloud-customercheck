<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\AppInfo\Application;
use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Exception\AccessDeniedException;
use OCA\CustomerCheck\Exception\NotAuthenticatedException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * CustomerCheck access model — portfolio ACCESS-AND-DIRECTORY-PICKERS §2.1.
 *
 *  1. **Dedicated App Admin (BudgetCheck semantics).**
 *     `isAppAdmin = isSystemAdmin OR uid ∈ app_admin_user_ids`.
 *     Nextcloud admins always retain policy access; any enabled user may be
 *     delegated without joining the NC `admin` group.
 *  2. **Empty allowlists while restriction is on means nobody but app admins.**
 *     This app holds sales data, so the allowlist fails closed rather than
 *     falling back to "everyone".
 */
class AccessControlService
{
	public const KEY_APP_ADMINS = Constants::CONFIG_APP_ADMIN_USER_IDS;
	public const KEY_ACCESS_RESTRICTION = Constants::CONFIG_ACCESS_RESTRICTION_ENABLED;
	public const KEY_ACCESS_ALLOWED_USER_IDS = Constants::CONFIG_ACCESS_ALLOWED_USER_IDS;
	public const KEY_ACCESS_ALLOWED_GROUP_IDS = Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS;

	public const DENIAL_RESTRICTION = 'restriction';
	public const DENIAL_EMPTY = 'empty_user';

	public function __construct(
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
	}

	/**
	 * @throws NotAuthenticatedException when there is no session user
	 */
	public function currentUserId(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotAuthenticatedException();
		}

		return $user->getUID();
	}

	/** Empty string when the request is anonymous — never throws. */
	public function currentUserIdOrEmpty(): string
	{
		$user = $this->userSession->getUser();

		return $user === null ? '' : $user->getUID();
	}

	public function isSystemAdmin(string $userId): bool
	{
		return $userId !== '' && $this->groupManager->isAdmin($userId);
	}

	/**
	 * Portfolio §2.1 / BudgetCheck:
	 *
	 *     if uid == '': return false
	 *     if isSystemAdmin(uid): return true
	 *     return uid ∈ app_admin_user_ids
	 */
	public function isAppAdmin(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isSystemAdmin($userId)) {
			return true;
		}

		return in_array($userId, $this->getAppAdminIds(), true);
	}

	/**
	 * Directory door only (same model as BudgetCheck / MobilityCheck).
	 *
	 *     if uid == '': return false
	 *     if isAppAdmin(uid): return true
	 *     if not isAccessRestrictionEnabled(): return true   // Open mode
	 *     return userMatchesAccessAllowList(uid)             // Restricted
	 *
	 * Open mode must show CustomerCheck in the menu for every
	 * authenticated user. Do not fold workspace membership or other gates here.
	 */
	public function canUseApp(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if (!$this->isAccessRestrictionEnabled()) {
			return true;
		}

		return $this->userMatchesAccessAllowList($userId);
	}

	/**
	 * Stable reason when {@see canUseApp} is false (never call when true).
	 *
	 * Non-empty denials are always restriction: Open mode never denies a
	 * logged-in user, and app admins always pass {@see canUseApp}.
	 */
	public function denialReasonWhenCannotUseApp(string $userId): string
	{
		if ($userId === '') {
			return self::DENIAL_EMPTY;
		}

		return self::DENIAL_RESTRICTION;
	}

	public function canManageAppConfiguration(string $userId): bool
	{
		return $this->isAppAdmin($userId);
	}

	public function isAccessRestrictionEnabled(): bool
	{
		return $this->config->getAppValue(
			Application::APP_ID,
			self::KEY_ACCESS_RESTRICTION,
			Constants::CONFIG_DEFAULTS[Constants::CONFIG_ACCESS_RESTRICTION_ENABLED],
		) === '1';
	}

	/**
	 * Restriction is on but neither list has an entry: only app admins are left
	 * in. Settings renders a danger callout for this state (§8.8 E1).
	 */
	public function isFailClosedWithEmptyAllowLists(): bool
	{
		return $this->isAccessRestrictionEnabled()
			&& $this->getAllowedUserIds() === []
			&& $this->getAllowedGroupIds() === [];
	}

	public function userMatchesAccessAllowList(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if (in_array($userId, $this->getAllowedUserIds(), true)) {
			return true;
		}
		foreach ($this->getAllowedGroupIds() as $groupId) {
			if ($this->groupManager->isInGroup($userId, $groupId)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @throws NotAuthenticatedException
	 * @throws \OCA\CustomerCheck\Exception\AppAccessDeniedException
	 */
	public function requireCanUseApp(): string
	{
		$userId = $this->currentUserId();
		if (!$this->canUseApp($userId)) {
			throw new \OCA\CustomerCheck\Exception\AppAccessDeniedException();
		}

		return $userId;
	}

	/**
	 * @throws NotAuthenticatedException
	 * @throws AccessDeniedException
	 */
	public function requireAppAdmin(): string
	{
		$userId = $this->currentUserId();
		if (!$this->isAppAdmin($userId)) {
			throw new AccessDeniedException();
		}

		return $userId;
	}

	/** @return list<string> */
	public function getAppAdminIds(): array
	{
		return $this->getJsonList(self::KEY_APP_ADMINS);
	}

	/** @return list<string> */
	public function getAllowedUserIds(): array
	{
		return $this->getJsonList(self::KEY_ACCESS_ALLOWED_USER_IDS);
	}

	/** @return list<string> */
	public function getAllowedGroupIds(): array
	{
		return $this->getJsonList(self::KEY_ACCESS_ALLOWED_GROUP_IDS);
	}

	/**
	 * Every uid that may receive CustomerCheck notifications (§6.2 "all CRM users").
	 *
	 * Derived from the explicit user allowlist plus the members of every allowed
	 * group plus dedicated app administrators plus every Nextcloud system admin
	 * (implicit app admins). When the restriction is off this cannot be
	 * enumerated cheaply, so the caller falls back to the same set — notifying
	 * literally every instance user would be noise, not a feature.
	 *
	 * @return list<string>
	 */
	public function listNotifiableUserIds(): array
	{
		$uids = $this->getAllowedUserIds();

		foreach ($this->getAllowedGroupIds() as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$uids[] = $user->getUID();
			}
		}

		foreach ($this->getAppAdminIds() as $adminUid) {
			$uids[] = $adminUid;
		}

		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			foreach ($adminGroup->getUsers() as $user) {
				$uids[] = $user->getUID();
			}
		}

		$uids = array_values(array_unique(array_filter($uids, static fn (string $uid): bool => $uid !== '')));
		sort($uids);

		return $uids;
	}

	/** @return list<string> */
	private function getJsonList(string $key): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, $key, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		$values = [];
		foreach ($decoded as $entry) {
			if (is_string($entry) || is_int($entry)) {
				$value = trim((string)$entry);
				if ($value !== '') {
					$values[] = $value;
				}
			}
		}

		return array_values(array_unique($values));
	}
}
