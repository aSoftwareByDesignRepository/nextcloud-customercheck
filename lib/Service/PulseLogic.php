<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Business Pulse honesty + link-debt rules — product §9.3–§9.4.
 *
 * If ProjectCheck still emits stub openHours=0 / unknown billable, Unbilled shows
 * honesty_blocked — never €0 / 0h as truth.
 */
final class PulseLogic
{
	/**
	 * @param array{
	 *   pcAvailable?: bool,
	 *   pcLinkedCount?: int,
	 *   openBillableMinutes?: int|null,
	 *   billableKnown?: bool,
	 *   stubOpenHours?: bool
	 * } $signals
	 * @return array{
	 *   state: 'ok'|'honesty_blocked'|'unavailable'|'unknown',
	 *   openBillableMinutes: int|null,
	 *   label: string
	 * }
	 */
	public static function unbilled(array $signals): array
	{
		$pcAvailable = !empty($signals['pcAvailable']);
		if (!$pcAvailable) {
			return [
				'state' => 'unavailable',
				'openBillableMinutes' => null,
				'label' => 'unavailable',
			];
		}

		$stub = !empty($signals['stubOpenHours']);
		$known = !empty($signals['billableKnown'])
			&& array_key_exists('openBillableMinutes', $signals)
			&& $signals['openBillableMinutes'] !== null;

		if ($stub || !$known) {
			return [
				'state' => 'honesty_blocked',
				'openBillableMinutes' => null,
				'label' => 'honesty_blocked',
			];
		}

		$minutes = max(0, (int)$signals['openBillableMinutes']);
		return [
			'state' => 'ok',
			'openBillableMinutes' => $minutes,
			'label' => 'ok',
		];
	}

	/**
	 * Link debt: missing PC / TC / Files when the sibling is capable.
	 *
	 * @param array{
	 *   pcCapable?: bool,
	 *   tcCapable?: bool,
	 *   filesCapable?: bool,
	 *   pcLinked?: bool,
	 *   tcLinked?: bool,
	 *   filesLinked?: bool
	 * } $company
	 * @return list<string>
	 */
	public static function linkDebt(array $company): array
	{
		$missing = [];
		if (!empty($company['pcCapable']) && empty($company['pcLinked'])) {
			$missing[] = 'ProjectCheck';
		}
		if (!empty($company['tcCapable']) && empty($company['tcLinked'])) {
			$missing[] = 'TicketCheck';
		}
		if (!empty($company['filesCapable']) && empty($company['filesLinked'])) {
			$missing[] = 'Files';
		}
		return $missing;
	}

	/**
	 * Waiting tickets must never be confused with all-open tickets.
	 *
	 * @param list<array{waiting?: bool, status?: string}> $tickets
	 */
	public static function countWaiting(array $tickets): int
	{
		$n = 0;
		foreach ($tickets as $t) {
			if (!empty($t['waiting'])) {
				$n++;
				continue;
			}
			$status = strtolower((string)($t['status'] ?? ''));
			if ($status === 'waiting' || $status === 'waiting_on_us') {
				$n++;
			}
		}
		return $n;
	}
}
