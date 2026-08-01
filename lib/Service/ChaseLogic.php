<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;

/**
 * Pure chase reason / health rules — product §11.2–§11.3.
 *
 * No I/O. Unit + mutation tested so off-by-one and severity order cannot drift.
 */
final class ChaseLogic
{
	/** @var list<string> Severity order (highest first). */
	public const REASON_SEVERITY = [
		'lead_sla',
		'close_overdue',
		'no_next_action',
		'quote_followup_due',
		'close_this_week',
		'stale_stage',
		'unowned',
	];

	/**
	 * Open deal is healthy iff ≥1 open dated activity OR skip_until ≥ today.
	 */
	public static function isHealthy(
		bool $hasOpenDatedActivity,
		?\DateTimeInterface $skipUntil,
		\DateTimeInterface $today,
	): bool {
		if ($hasOpenDatedActivity) {
			return true;
		}
		if ($skipUntil === null) {
			return false;
		}
		return $skipUntil->format('Y-m-d') >= $today->format('Y-m-d');
	}

	/**
	 * Reject skip_until further than +7 days from today.
	 */
	public static function isSkipAllowed(?\DateTimeInterface $skipUntil, \DateTimeInterface $today): bool
	{
		if ($skipUntil === null) {
			return true;
		}
		$todayYmd = $today->format('Y-m-d');
		$skipYmd = $skipUntil->format('Y-m-d');
		if ($skipYmd < $todayYmd) {
			return true; // clearing / past is fine; caller may null it
		}
		$max = (clone \DateTimeImmutable::createFromInterface($today))
			->modify('+' . Constants::NEXT_ACTION_SKIP_MAX_DAYS . ' days')
			->format('Y-m-d');

		return $skipYmd <= $max;
	}

	/**
	 * @param array{
	 *   stage: string,
	 *   ownerUserId?: ?string,
	 *   leadSlaDueAt?: ?\DateTimeInterface,
	 *   leadFirstTouchAt?: ?\DateTimeInterface,
	 *   expectedClose?: ?\DateTimeInterface,
	 *   quoteSentOn?: ?\DateTimeInterface,
	 *   stageChangedAt?: ?\DateTimeInterface,
	 *   healthy: bool,
	 *   staleDays?: int,
	 *   stuckChaseDays?: int
	 * } $deal
	 * @return list<string> reasons in severity order
	 */
	public static function reasons(array $deal, \DateTimeInterface $today): array
	{
		$stage = (string)($deal['stage'] ?? '');
		if (in_array($stage, Constants::DEAL_TERMINAL_STAGES, true)) {
			return [];
		}

		$todayYmd = $today->format('Y-m-d');
		$reasons = [];

		$slaDue = $deal['leadSlaDueAt'] ?? null;
		$firstTouch = $deal['leadFirstTouchAt'] ?? null;
		if ($stage === 'lead' && $slaDue instanceof \DateTimeInterface && $firstTouch === null) {
			if ($slaDue->format('Y-m-d H:i:s') < $today->format('Y-m-d H:i:s')) {
				$reasons[] = 'lead_sla';
			}
		}

		$close = $deal['expectedClose'] ?? null;
		if ($close instanceof \DateTimeInterface && $close->format('Y-m-d') < $todayYmd) {
			$reasons[] = 'close_overdue';
		}

		if (empty($deal['healthy'])) {
			$reasons[] = 'no_next_action';
		}

		$quoteSent = $deal['quoteSentOn'] ?? null;
		if ($stage === 'quoted' && $quoteSent instanceof \DateTimeInterface) {
			$stuckDays = max(1, (int)($deal['stuckChaseDays'] ?? 3));
			$age = self::daysBetween($quoteSent, $today);
			if ($age >= $stuckDays) {
				$reasons[] = 'quote_followup_due';
			}
		}

		if ($close instanceof \DateTimeInterface) {
			$closeYmd = $close->format('Y-m-d');
			$weekEnd = (clone \DateTimeImmutable::createFromInterface($today))
				->modify('sunday this week')
				->format('Y-m-d');
			if ($closeYmd >= $todayYmd && $closeYmd <= $weekEnd) {
				$reasons[] = 'close_this_week';
			}
		}

		$staleDays = max(1, (int)($deal['staleDays'] ?? 14));
		$changed = $deal['stageChangedAt'] ?? null;
		if ($changed instanceof \DateTimeInterface && self::daysBetween($changed, $today) >= $staleDays) {
			$reasons[] = 'stale_stage';
		}

		$owner = trim((string)($deal['ownerUserId'] ?? ''));
		if ($owner === '') {
			$reasons[] = 'unowned';
		}

		$order = array_flip(self::REASON_SEVERITY);
		usort($reasons, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

		return array_values(array_unique($reasons));
	}

	public static function primaryReason(array $deal, \DateTimeInterface $today): ?string
	{
		$reasons = self::reasons($deal, $today);
		return $reasons[0] ?? null;
	}

	public static function quoteAgeDays(?\DateTimeInterface $quoteSentOn, \DateTimeInterface $today): ?int
	{
		if ($quoteSentOn === null) {
			return null;
		}
		return self::daysBetween($quoteSentOn, $today);
	}

	private static function daysBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
	{
		$a = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
		$b = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);
		return (int)$a->diff($b)->format('%r%a');
	}
}
