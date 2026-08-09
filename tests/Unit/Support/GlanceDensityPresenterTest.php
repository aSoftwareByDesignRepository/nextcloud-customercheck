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

		$this->assertCount(3, $tiles);
		$byKey = [];
		foreach ($tiles as $t) {
			$byKey[$t['key']] = $t;
		}
		$this->assertSame(GlanceDensityPresenter::TONE_CALM, $byKey['invoices']['tone']);
		$this->assertSame(0, $byKey['invoices']['value']);
		$this->assertStringContainsString('All clear', $byKey['invoices']['hint']);
		$this->assertSame('Open', $byKey['invoices']['cta']);
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
}
