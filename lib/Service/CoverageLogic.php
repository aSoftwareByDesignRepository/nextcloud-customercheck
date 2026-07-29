<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Buying-role coverage — product §12.2 / §12.7.
 *
 * Covered (key & partner): active economic_buyer AND champion AND (day_to_day OR technical).
 * Standard tier: optional — never hard-gap.
 */
final class CoverageLogic
{
	/**
	 * @param list<array{buying_role?: ?string, status?: string}> $people
	 * @return array{
	 *   covered: bool,
	 *   gap: bool,
	 *   missing: list<string>,
	 *   flags: list<string>
	 * }
	 */
	public static function evaluate(string $kamTier, array $people, bool $touchOverdue = false, bool $promiseOverdue = false, bool $partnerHoursHot = false, bool $renewalHot = false): array
	{
		$activeRoles = [];
		$championLeft = false;
		foreach ($people as $p) {
			$role = $p['buying_role'] ?? null;
			$status = (string)($p['status'] ?? 'active');
			if ($role === 'champion' && $status === 'left') {
				$championLeft = true;
			}
			if ($status !== 'active' || $role === null || $role === '') {
				continue;
			}
			$activeRoles[(string)$role] = true;
		}

		$hasBuyer = isset($activeRoles['economic_buyer']);
		$hasChampion = isset($activeRoles['champion']);
		$hasOps = isset($activeRoles['day_to_day']) || isset($activeRoles['technical']);

		$missing = [];
		if (!$hasBuyer) {
			$missing[] = 'economic_buyer';
		}
		if (!$hasChampion) {
			$missing[] = 'champion';
		}
		if (!$hasOps) {
			$missing[] = 'day_to_day_or_technical';
		}

		$enforce = in_array($kamTier, ['key', 'partner'], true);
		$covered = $hasBuyer && $hasChampion && $hasOps;
		$gap = $enforce && !$covered;

		$flags = [];
		if (!$hasChampion) {
			$flags[] = 'champion_gap';
		}
		if (!$hasBuyer) {
			$flags[] = 'buyer_gap';
		}
		if ($championLeft) {
			$flags[] = 'champion_left';
		}
		if ($touchOverdue) {
			$flags[] = 'touch_overdue';
		}
		if ($promiseOverdue) {
			$flags[] = 'promise_overdue';
		}
		if ($partnerHoursHot) {
			$flags[] = 'partner_hours_hot';
		}
		if ($renewalHot) {
			$flags[] = 'renewal_hot';
		}

		return [
			'covered' => $covered,
			'gap' => $gap,
			'missing' => $missing,
			'flags' => $flags,
		];
	}

	/**
	 * Default touch interval days — product §12.3.
	 */
	public static function defaultTouchIntervalDays(string $kamTier, ?string $partnerProductCode = null): int
	{
		$code = strtolower((string)$partnerProductCode);
		if ($code === 'cp-prem') {
			return 30;
		}
		if ($code === 'cp-std' || $code === 'cp-small') {
			return 90;
		}
		if ($kamTier === 'key') {
			return 14;
		}
		return 30;
	}

	/**
	 * Partner tier auto from active CP-* agreement unless locked.
	 */
	public static function resolveKamTier(string $current, bool $locked, bool $hasActivePartnerAgreement): string
	{
		if ($locked) {
			return $current;
		}
		if ($hasActivePartnerAgreement) {
			return 'partner';
		}
		return $current === 'partner' ? 'standard' : $current;
	}
}
