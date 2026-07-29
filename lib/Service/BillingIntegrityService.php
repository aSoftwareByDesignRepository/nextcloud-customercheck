<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Billing integrity heuristic — COMMAND-CENTER-SPEC §7.2.
 *
 * Never claims GoBD/tax truth. Pure state machine over facade counts.
 */
class BillingIntegrityService
{
	/**
	 * @param array{
	 *   overdueCount?: int|null,
	 *   openCount?: int|null,
	 *   openBillableMinutes?: int|null,
	 *   uninvoicedProjects?: int|null,
	 *   pcLinked?: bool,
	 *   icLinked?: bool,
	 *   billableKnown?: bool,
	 *   uninvoicedKnown?: bool
	 * } $signals
	 * @return array{
	 *   state: 'ok'|'attention'|'critical'|'unknown',
	 *   overdueCount: int,
	 *   openCount: int,
	 *   openBillableMinutes: int|null,
	 *   uninvoicedProjects: int|null
	 * }
	 */
	public function evaluate(array $signals): array
	{
		$pcLinked = !empty($signals['pcLinked']);
		$icLinked = !empty($signals['icLinked']);
		if (!$pcLinked && !$icLinked) {
			return [
				'state' => 'unknown',
				'overdueCount' => 0,
				'openCount' => 0,
				'openBillableMinutes' => null,
				'uninvoicedProjects' => null,
			];
		}

		$overdue = max(0, (int)($signals['overdueCount'] ?? 0));
		$open = max(0, (int)($signals['openCount'] ?? 0));
		$billableKnown = array_key_exists('openBillableMinutes', $signals)
			&& $signals['openBillableMinutes'] !== null
			&& !empty($signals['billableKnown']);
		$uninvoicedKnown = array_key_exists('uninvoicedProjects', $signals)
			&& $signals['uninvoicedProjects'] !== null
			&& !empty($signals['uninvoicedKnown']);
		$billable = $billableKnown ? max(0, (int)$signals['openBillableMinutes']) : null;
		$uninvoiced = $uninvoicedKnown ? max(0, (int)$signals['uninvoicedProjects']) : null;

		if ($overdue >= 1) {
			$state = 'critical';
		} elseif ($open > 0 || ($billable !== null && $billable > 0) || ($uninvoiced !== null && $uninvoiced > 0)) {
			$state = 'attention';
		} else {
			$state = 'ok';
		}

		return [
			'state' => $state,
			'overdueCount' => $overdue,
			'openCount' => $open,
			'openBillableMinutes' => $billable,
			'uninvoicedProjects' => $uninvoiced,
		];
	}

	/**
	 * Classify a single IC invoice row into overdue / open / paid buckets.
	 *
	 * @param array{overdue?: bool, status?: string} $row
	 * @return 'overdue'|'open'|'paid'|'ignore'
	 */
	public function classifyInvoiceRow(array $row): string
	{
		if (!empty($row['overdue'])) {
			return 'overdue';
		}
		$status = mb_strtolower(trim((string)($row['status'] ?? '')));
		if (in_array($status, ['overdue'], true)) {
			return 'overdue';
		}
		if (in_array($status, ['open', 'sent', 'partially_paid', 'partial', 'draft'], true)) {
			return 'open';
		}
		if (in_array($status, ['paid', 'paid_pending_sync'], true)) {
			return 'paid';
		}

		return 'ignore';
	}
}
