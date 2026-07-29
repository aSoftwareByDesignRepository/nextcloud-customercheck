<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;

/**
 * Weekly Brief WIP + cash calendar honesty — product §10.2–§10.4.
 *
 * Forbidden: pipeline × probability; seller forecast buckets as cash.
 */
final class BriefLogic
{
	public static function clampWipLimit(int $limit): int
	{
		return max(Constants::WIP_LIMIT_MIN, min(Constants::WIP_LIMIT_MAX, $limit));
	}

	/**
	 * Confirm is rejected when committed+confirming would exceed WIP.
	 */
	public static function canConfirm(int $currentCommittedWip, int $wipLimit): bool
	{
		$limit = self::clampWipLimit($wipLimit);
		return $currentCommittedWip < $limit;
	}

	/**
	 * Carry streak max 2 — further carry becomes abandon suggestion.
	 */
	public static function canCarry(int $carryStreak): bool
	{
		return $carryStreak < 2;
	}

	/**
	 * Expected cash calendar — NEVER includes pipeline or forecast buckets.
	 *
	 * @param array{
	 *   overdueArCents?: int,
	 *   arDueThisMonthCents?: int,
	 *   arDueNextMonthCents?: int,
	 *   renewalsThisMonthCents?: int,
	 *   renewalsNextMonthCents?: int,
	 *   pipelineCents?: int,
	 *   forecastCommitCents?: int
	 * } $money
	 * @return array{
	 *   overdueArCents: int,
	 *   arDueThisMonthCents: int,
	 *   arDueNextMonthCents: int,
	 *   renewalsThisMonthCents: int,
	 *   renewalsNextMonthCents: int,
	 *   expectedInThisMonthCents: int,
	 *   pipelineExcludedCents: int,
	 *   forecastExcludedCents: int
	 * }
	 */
	public static function cashCalendar(array $money): array
	{
		$overdue = max(0, (int)($money['overdueArCents'] ?? 0));
		$arThis = max(0, (int)($money['arDueThisMonthCents'] ?? 0));
		$arNext = max(0, (int)($money['arDueNextMonthCents'] ?? 0));
		$renThis = max(0, (int)($money['renewalsThisMonthCents'] ?? 0));
		$renNext = max(0, (int)($money['renewalsNextMonthCents'] ?? 0));
		$pipeline = max(0, (int)($money['pipelineCents'] ?? 0));
		$forecast = max(0, (int)($money['forecastCommitCents'] ?? 0));

		return [
			'overdueArCents' => $overdue,
			'arDueThisMonthCents' => $arThis,
			'arDueNextMonthCents' => $arNext,
			'renewalsThisMonthCents' => $renThis,
			'renewalsNextMonthCents' => $renNext,
			'expectedInThisMonthCents' => $arThis + $renThis,
			'pipelineExcludedCents' => $pipeline,
			'forecastExcludedCents' => $forecast,
		];
	}

	public static function isoWeekKey(\DateTimeInterface $date): string
	{
		return $date->format('o') . '-W' . $date->format('W');
	}

	/**
	 * Unbilled_review must not be suggested when honesty_blocked.
	 *
	 * @param list<string> $kinds
	 * @return list<string>
	 */
	public static function filterSuggestions(array $kinds, bool $honestyBlocked): array
	{
		if (!$honestyBlocked) {
			return array_values($kinds);
		}
		return array_values(array_filter(
			$kinds,
			static fn (string $k): bool => $k !== 'unbilled_review',
		));
	}

	/**
	 * Done requires evidence (§10.3): IC paid / activity done / link fixed /
	 * plan healthy / manager override with reason.
	 *
	 * @return list<string>
	 */
	public static function doneEvidenceCodes(): array
	{
		return [
			'invoice_paid',
			'activity_done',
			'links_fixed',
			'plan_healthy',
			'manager_override',
		];
	}

	/**
	 * @return string|null error code, or null when evidence is acceptable
	 */
	public static function validateDoneEvidence(?string $evidence, ?string $note): ?string
	{
		$code = strtolower(trim((string)$evidence));
		if ($code === '' || !in_array($code, self::doneEvidenceCodes(), true)) {
			return Constants::ERR_VALIDATION;
		}
		if ($code === 'manager_override' && trim((string)$note) === '') {
			return Constants::ERR_VALIDATION;
		}
		return null;
	}
}
