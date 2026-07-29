<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Pure 360 density counters (WP-S1-CUC-360 / AC-S1.3).
 *
 * Unpaid invoice logic mirrors InvoicingCheck receivables candidates
 * ({@see \OCA\InvoiceCheck\Util\ReceivableMath::isReceivableCandidate}) plus
 * CRM legacy aliases and the facade `overdue` flag.
 */
final class DensityCounts
{
	/** @var list<string> */
	private const CLOSED_PROJECT = ['completed', 'cancelled', 'archived', 'done'];

	/**
	 * IC receivable statuses + legacy CRM aliases still seen in older fixtures.
	 *
	 * @var list<string>
	 */
	private const UNPAID_INVOICE = [
		'finalized',
		'partially_paid',
		'paid_pending_sync',
		'sent',
		'overdue',
		'unpaid',
		'open',
		'partial',
	];

	/** @var list<string> */
	private const PAID_OR_DEAD = ['paid', 'void', 'voiding', 'draft', 'finalizing'];

	/**
	 * @param list<array<string, mixed>> $projects
	 */
	public static function openProjects(array $projects): int
	{
		$n = 0;
		foreach ($projects as $proj) {
			$status = strtolower(trim((string)($proj['status'] ?? '')));
			if (!in_array($status, self::CLOSED_PROJECT, true)) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * @param list<array<string, mixed>> $invoices
	 */
	public static function unpaidInvoices(array $invoices): int
	{
		$n = 0;
		foreach ($invoices as $inv) {
			if (self::isUnpaidInvoice($inv)) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * First unpaid invoice id for deep-link (icInvoiceId preferred).
	 *
	 * @param list<array<string, mixed>> $invoices
	 */
	public static function firstUnpaidInvoiceId(array $invoices): ?int
	{
		foreach ($invoices as $inv) {
			if (!self::isUnpaidInvoice($inv)) {
				continue;
			}
			$id = (int)($inv['icInvoiceId'] ?? $inv['id'] ?? 0);
			if ($id > 0) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $inv
	 */
	public static function isUnpaidInvoice(array $inv): bool
	{
		$docKind = strtolower(trim((string)($inv['docKind'] ?? $inv['doc_kind'] ?? 'invoice')));
		if ($docKind !== '' && (str_contains($docKind, 'credit') || $docKind === 'cn')) {
			return false;
		}

		$status = strtolower(trim((string)($inv['status'] ?? $inv['invoiceStatus'] ?? '')));
		if (in_array($status, self::PAID_OR_DEAD, true)) {
			return false;
		}

		// Facade overdue flag wins once status is not paid/void/draft.
		if (array_key_exists('overdue', $inv) && filter_var($inv['overdue'], FILTER_VALIDATE_BOOLEAN)) {
			return true;
		}

		if (!in_array($status, self::UNPAID_INVOICE, true)) {
			return false;
		}

		if (array_key_exists('grossTotalCents', $inv) && array_key_exists('paidTotalCents', $inv)) {
			return (int)$inv['paidTotalCents'] < (int)$inv['grossTotalCents'];
		}

		return true;
	}

	/**
	 * Open helpdesk tickets (not closed/resolved).
	 *
	 * @param list<array<string, mixed>> $tickets
	 */
	public static function openTickets(array $tickets): int
	{
		$n = 0;
		foreach ($tickets as $t) {
			$status = strtolower(trim((string)($t['status'] ?? '')));
			if (self::isClosedTicketStatus($status)) {
				continue;
			}
			$n++;
		}

		return $n;
	}

	/**
	 * Waiting-on-customer (or similar) tickets only.
	 *
	 * @param list<array<string, mixed>> $tickets
	 */
	public static function waitingTickets(array $tickets): int
	{
		$n = 0;
		foreach ($tickets as $t) {
			$status = strtolower(trim((string)($t['status'] ?? '')));
			if ($status !== '' && str_contains($status, 'wait')) {
				$n++;
			}
		}

		return $n;
	}

	private static function isClosedTicketStatus(string $status): bool
	{
		if ($status === '') {
			return false;
		}

		return str_contains($status, 'closed')
			|| str_contains($status, 'resolved')
			|| str_contains($status, 'done')
			|| str_contains($status, 'cancelled')
			|| str_contains($status, 'canceled');
	}
}
