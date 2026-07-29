<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Stuck detection for Team Coaching — product §13.3.
 */
final class StuckLogic
{
	/**
	 * @param array{
	 *   chaseCount?: int,
	 *   promiseOverdue?: int,
	 *   planStalled?: bool,
	 *   commitmentMissed?: int
	 * } $signals
	 */
	public static function isStuck(array $signals): bool
	{
		return (int)($signals['chaseCount'] ?? 0) > 0
			|| (int)($signals['promiseOverdue'] ?? 0) > 0
			|| !empty($signals['planStalled'])
			|| (int)($signals['commitmentMissed'] ?? 0) > 0;
	}

	/**
	 * @param array{
	 *   chaseCount?: int,
	 *   promiseOverdue?: int,
	 *   planStalled?: bool,
	 *   commitmentMissed?: int
	 * } $signals
	 */
	public static function stuckScore(array $signals): int
	{
		$score = (int)($signals['chaseCount'] ?? 0)
			+ (int)($signals['promiseOverdue'] ?? 0)
			+ (int)($signals['commitmentMissed'] ?? 0);
		if (!empty($signals['planStalled'])) {
			$score++;
		}
		return $score;
	}

	/**
	 * Auto-resolve escalation when subject is no longer stuck.
	 */
	public static function shouldAutoResolve(bool $stillStuck): bool
	{
		return !$stillStuck;
	}
}
