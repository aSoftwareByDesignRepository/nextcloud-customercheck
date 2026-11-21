<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit\Support;

use OCA\CustomerCheck\Support\GlanceDensityPresenter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class GlanceDensityPresenterTest extends TestCase
{
	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $text, array $args = []): string {
			if ($args === []) {
				return $text;
			}
			$out = $text;
			foreach ($args as $i => $arg) {
				$out = str_replace('%' . ($i + 1) . '$s', (string)$arg, $out);
				$out = preg_replace('/%s/', (string)$arg, $out, 1) ?? $out;
			}
			return $out;
		});
		return $l;
	}

	public function testZeroUnpaidIsCalmNeverWarningTone(): void
	{
		$tiles = GlanceDensityPresenter::tiles([
			'openProjects' => 0,
			'unpaidInvoices' => 0,
			'openTickets' => 0,
			'waitingTickets' => 0,
			'projectcheck' => true,
			'invoicecheck' => true,
			'ticketcheck' => true,
			'projectsHref' => '#crm-projects',
			'invoicesHref' => '/apps/invoicecheck/receivables?bucket=overdue',
			'ticketsHref' => '#crm-tickets',
		], $this->l10n());

		$this->assertCount(4, $tiles);
		$byKey = [];
		foreach ($tiles as $t) {
			$byKey[$t['key']] = $t;
		}
		$this->assertSame(GlanceDensityPresenter::TONE_CALM, $byKey['invoices']['tone']);
		$this->assertSame(0, $byKey['invoices']['value']);
		$this->assertStringContainsString('All clear', $byKey['invoices']['hint']);
		$this->assertSame('View', $byKey['invoices']['cta']);
	}

	public function testExpenseClaimsTileMirrorsTheContract(): void
	{
		// Missing app: offline tone, Link CTA, identity anchor.
		$offline = GlanceDensityPresenter::tiles(['expensecheck' => false], $this->l10n());
		$exp = $offline[3];
		$this->assertSame('expenses', $exp['key']);
		$this->assertSame(GlanceDensityPresenter::TONE_OFFLINE, $exp['tone']);
		$this->assertSame('#crm-identity-links', $exp['href']);
		$this->assertFalse($exp['available']);

		// Open claims: attention tone + real count + the deep href.
		$open = GlanceDensityPresenter::tiles([
			'expensecheck' => true,
			'openExpenseClaims' => 5,
			'expensesHref' => '/apps/expensecheck/all',
		], $this->l10n());
		$exp = $open[3];
		$this->assertSame(GlanceDensityPresenter::TONE_ATTENTION, $exp['tone']);
		$this->assertSame(5, $exp['value']);
		$this->assertSame('/apps/expensecheck/all', $exp['href']);
		$this->assertStringContainsString('5 open', $exp['hint']);
		$this->assertSame('View', $exp['cta']);

		// Nothing open: calm, never fake-warning.
		$clear = GlanceDensityPresenter::tiles(['expensecheck' => true, 'openExpenseClaims' => 0], $this->l10n());
		$this->assertSame(GlanceDensityPresenter::TONE_CALM, $clear[3]['tone']);
	}

	public function testUnpaidUsesAttentionAndKeepsDeepHref(): void
	{
		$tiles = GlanceDensityPresenter::tiles([
			'unpaidInvoices' => 3,
			'invoicecheck' => true,
			'invoicesHref' => '/apps/invoicecheck/invoices/9',
			'projectcheck' => true,
			'ticketcheck' => true,
		], $this->l10n());
		$inv = $tiles[1];
		$this->assertSame('invoices', $inv['key']);
		$this->assertSame(GlanceDensityPresenter::TONE_ATTENTION, $inv['tone']);
		$this->assertSame('/apps/invoicecheck/invoices/9', $inv['href']);
		$this->assertStringContainsString('3 unpaid', $inv['hint']);
	}

	public function testMissingAppIsOfflineAndPointsAtIdentity(): void
	{
		$tiles = GlanceDensityPresenter::tiles([
			'projectcheck' => false,
			'invoicecheck' => false,
			'ticketcheck' => false,
			'projectsHref' => '#crm-projects',
		], $this->l10n());
		foreach ($tiles as $tile) {
			$this->assertSame(GlanceDensityPresenter::TONE_OFFLINE, $tile['tone']);
			$this->assertFalse($tile['available']);
			$this->assertSame('#crm-identity-links', $tile['href']);
			$this->assertSame('Link', $tile['cta']);
			$this->assertStringContainsString('not installed', strtolower($tile['hint']));
		}
	}

	public function testNormalizeInvoiceHrefStripsOverdueWhenZeroUnpaid(): void
	{
		$out = GlanceDensityPresenter::normalizeInvoiceHref([
			'unpaidInvoices' => 0,
			'invoicesHref' => '/apps/invoicecheck/receivables?bucket=overdue',
		]);
		$this->assertSame('#crm-invoices', $out['invoicesHref']);

		$keep = GlanceDensityPresenter::normalizeInvoiceHref([
			'unpaidInvoices' => 2,
			'invoicesHref' => '/apps/invoicecheck/receivables?bucket=overdue',
		]);
		$this->assertSame('/apps/invoicecheck/receivables?bucket=overdue', $keep['invoicesHref']);
	}

	public function testSafeHrefBlocksJavascriptScheme(): void
	{
		$this->assertSame('#crm-projects', GlanceDensityPresenter::safeHref('javascript:alert(1)', '#crm-projects'));
		$this->assertSame('#crm-projects', GlanceDensityPresenter::safeHref('DATA:text/html,x', '#crm-projects'));
		$this->assertSame('/apps/x', GlanceDensityPresenter::safeHref('/apps/x', '#crm-projects'));
		$this->assertSame('#crm-invoices', GlanceDensityPresenter::safeHref('  ', '#crm-invoices'));
	}

	public function testWaitingTicketsRaiseAttention(): void
	{
		$tiles = GlanceDensityPresenter::tiles([
			'ticketcheck' => true,
			'openTickets' => 1,
			'waitingTickets' => 2,
			'ticketsHref' => '#crm-tickets',
			'projectcheck' => true,
			'invoicecheck' => true,
		], $this->l10n());
		$this->assertSame(GlanceDensityPresenter::TONE_ATTENTION, $tiles[2]['tone']);
		$this->assertStringContainsString('2 waiting', $tiles[2]['hint']);
	}

	public function testOpenProjectsRaiseAttentionTone(): void
	{
		$tiles = GlanceDensityPresenter::tiles([
			'openProjects' => 4,
			'projectcheck' => true,
			'invoicecheck' => true,
			'ticketcheck' => true,
			'unpaidInvoices' => 0,
			'openTickets' => 0,
			'waitingTickets' => 0,
			'projectsHref' => '#crm-projects',
		], $this->l10n());
		$this->assertSame(GlanceDensityPresenter::TONE_ATTENTION, $tiles[0]['tone']);
		$this->assertTrue(GlanceDensityPresenter::needsAttention($tiles));
		$this->assertStringContainsString('4 open', $tiles[0]['hint']);
	}

	public function testNeedsAttentionFalseWhenAllCalm(): void
	{
		$tiles = GlanceDensityPresenter::tiles([
			'openProjects' => 0,
			'unpaidInvoices' => 0,
			'openTickets' => 0,
			'waitingTickets' => 0,
			'projectcheck' => true,
			'invoicecheck' => true,
			'ticketcheck' => true,
			'expensecheck' => true,
		], $this->l10n());
		$this->assertFalse(GlanceDensityPresenter::needsAttention($tiles));
	}

	public function testNeedsAttentionTrueWhenOffline(): void
	{
		$tiles = GlanceDensityPresenter::tiles([
			'projectcheck' => false,
			'invoicecheck' => true,
			'ticketcheck' => true,
			'unpaidInvoices' => 0,
		], $this->l10n());
		$this->assertTrue(GlanceDensityPresenter::needsAttention($tiles));
	}
}
