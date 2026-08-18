<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * List and mapper searches must never mean “every book”.
 *
 * A search is scoped when it names a workspace, a company, or a deal.
 * An empty workspaceIds allow-list is still “named” so the mapper can
 * fail-close it to zero rows. Empty companyIds is not a real company
 * scope and also fail-closes.
 */
final class ListWorkspaceGuard
{
	public static function isListable(int $workspaceId): bool
	{
		return $workspaceId > 0;
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public static function companyListIsScoped(array $filters): bool
	{
		if ((int)($filters['workspaceId'] ?? 0) > 0) {
			return true;
		}

		return is_array($filters['workspaceIds'] ?? null);
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public static function childListIsScoped(array $filters): bool
	{
		if (self::companyListIsScoped($filters)) {
			return true;
		}
		if ((int)($filters['companyId'] ?? 0) > 0) {
			return true;
		}
		if ((int)($filters['dealId'] ?? 0) > 0) {
			return true;
		}
		if (!is_array($filters['companyIds'] ?? null)) {
			return false;
		}
		foreach ($filters['companyIds'] as $id) {
			if ((int)$id > 0) {
				return true;
			}
		}

		return false;
	}
}

