<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Billing integrity heuristic — COMMAND-CENTER-SPEC §7.2.
 *
 * Never claims GoBD/tax truth. Pure state machine over facade counts and
 * local license invoice statuses (CRM-owned when InvoiceCheck is absent).
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
	 *   licenseLinked?: bool,
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
		$licenseLinked = !empty($signals['licenseLinked']);
		if (!$pcLinked && !$icLinked && !$licenseLinked) {
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

	/**
	 * Classify CRM license / agreement invoiceStatus (Constants::INVOICE_STATUSES).
	 *
	 * @return 'overdue'|'open'|'paid'|'ignore'
	 */
	public function classifyAgreementInvoiceStatus(string $status): string
	{
		$normalized = mb_strtolower(trim($status));
		return match ($normalized) {
			'overdue' => 'overdue',
			'sent' => 'open',
			'paid' => 'paid',
			default => 'ignore',
		};
	}

	/**
	 * Merge InvoiceCheck counts with local license counts without inventing IC truth.
	 *
	 * Rules:
	 * - IC linked and reports any open/overdue → trust IC for the headline counts.
	 * - IC linked but empty, while licenses have unpaid → fall back to licenses.
	 * - IC absent → licenses only.
	 *
	 * @return array{overdueCount: int, openCount: int, sourceMode: 'invoicecheck'|'licenses'|'licenses_fallback'|'none'}
	 */
	public function mergeInvoiceCounts(
		bool $icLinked,
		int $icOverdue,
		int $icOpen,
		int $licenseOverdue,
		int $licenseOpen,
	): array {
		$icOverdue = max(0, $icOverdue);
		$icOpen = max(0, $icOpen);
		$licenseOverdue = max(0, $licenseOverdue);
		$licenseOpen = max(0, $licenseOpen);

		if ($icLinked) {
			if ($icOverdue > 0 || $icOpen > 0) {
				return [
					'overdueCount' => $icOverdue,
					'openCount' => $icOpen,
					'sourceMode' => 'invoicecheck',
				];
			}
			if ($licenseOverdue > 0 || $licenseOpen > 0) {
				return [
					'overdueCount' => $licenseOverdue,
					'openCount' => $licenseOpen,
					'sourceMode' => 'licenses_fallback',
				];
			}

			return [
				'overdueCount' => 0,
				'openCount' => 0,
				'sourceMode' => 'invoicecheck',
			];
		}

		if ($licenseOverdue > 0 || $licenseOpen > 0) {
			return [
				'overdueCount' => $licenseOverdue,
				'openCount' => $licenseOpen,
				'sourceMode' => 'licenses',
			];
		}

		return [
			'overdueCount' => 0,
			'openCount' => 0,
			'sourceMode' => 'none',
		];
	}
}
