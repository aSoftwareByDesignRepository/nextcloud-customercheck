<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\DensityCounts;
use PHPUnit\Framework\TestCase;

/** WP-S1-CUC-360 / AC-S1.3 — density bucket math (IC + CRM aliases). */
final class DensityCountsTest extends TestCase
{
	public function testOpenProjectsIgnoresClosedStatuses(): void
	{
		$this->assertSame(2, DensityCounts::openProjects([
			['status' => 'active'],
			['status' => 'Completed'],
			['status' => 'in_progress'],
			['status' => 'cancelled'],
			['status' => 'archived'],
			['status' => 'done'],
		]));
	}

	public function testUnpaidInvoicesCountsIcFinalizedAndPartial(): void
	{
		$this->assertSame(2, DensityCounts::unpaidInvoices([
			['status' => 'finalized', 'overdue' => true, 'icInvoiceId' => 1, 'grossTotalCents' => 1000, 'paidTotalCents' => 0],
			['status' => 'partially_paid', 'icInvoiceId' => 2, 'grossTotalCents' => 1000, 'paidTotalCents' => 400],
			['status' => 'paid', 'icInvoiceId' => 3, 'grossTotalCents' => 1000, 'paidTotalCents' => 1000],
			['status' => 'draft', 'icInvoiceId' => 4],
			['status' => 'void', 'icInvoiceId' => 5],
		]));
	}

	public function testUnpaidHonorsOverdueFlagEvenWhenStatusIsOdd(): void
	{
		// Empty/unknown status still counts when the IC facade marks overdue.
		$this->assertTrue(DensityCounts::isUnpaidInvoice([
			'status' => '',
			'overdue' => true,
			'grossTotalCents' => 500,
			'paidTotalCents' => 0,
		]));
		$this->assertTrue(DensityCounts::isUnpaidInvoice([
			'status' => 'awaiting_weird',
			'overdue' => 1,
			'grossTotalCents' => 500,
			'paidTotalCents' => 0,
		]));
		$this->assertFalse(DensityCounts::isUnpaidInvoice([
			'status' => 'paid',
			'overdue' => true, // paid must never count
		]));
	}

	public function testUnpaidExcludesCreditNotes(): void
	{
		$this->assertFalse(DensityCounts::isUnpaidInvoice([
			'status' => 'finalized',
			'docKind' => 'credit_note',
			'overdue' => true,
		]));
	}

	public function testUnpaidRequiresRemainingBalanceWhenCentsPresent(): void
	{
		$this->assertFalse(DensityCounts::isUnpaidInvoice([
			'status' => 'finalized',
			'grossTotalCents' => 1000,
			'paidTotalCents' => 1000,
		]));
		$this->assertTrue(DensityCounts::isUnpaidInvoice([
			'status' => 'paid_pending_sync',
			'grossTotalCents' => 1000,
			'paidTotalCents' => 100,
		]));
	}

	public function testFirstUnpaidInvoiceIdPrefersIcId(): void
	{
		$this->assertSame(42, DensityCounts::firstUnpaidInvoiceId([
			['status' => 'paid', 'icInvoiceId' => 9],
			['status' => 'finalized', 'icInvoiceId' => 42, 'grossTotalCents' => 10, 'paidTotalCents' => 0],
		]));
		$this->assertNull(DensityCounts::firstUnpaidInvoiceId([
			['status' => 'paid', 'icInvoiceId' => 1],
		]));
	}

	public function testWaitingTicketsMatchesWaitSubstringOnly(): void
	{
		$this->assertSame(1, DensityCounts::waitingTickets([
			['status' => ''],
			['status' => 'waiting_customer'],
			['status' => 'open'],
			['status' => 'new'],
			['status' => 'closed'],
			['status' => 'resolved'],
		]));
	}

	public function testOpenTicketsExcludesClosedStatuses(): void
	{
		$this->assertSame(4, DensityCounts::openTickets([
			['status' => ''],
			['status' => 'waiting_customer'],
			['status' => 'open'],
			['status' => 'new'],
			['status' => 'closed'],
			['status' => 'resolved'],
		]));
	}

	public function testEmptyListsAreZero(): void
	{
		$this->assertSame(0, DensityCounts::openProjects([]));
		$this->assertSame(0, DensityCounts::unpaidInvoices([]));
		$this->assertSame(0, DensityCounts::waitingTickets([]));
		$this->assertSame(0, DensityCounts::openTickets([]));
	}
}
