<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;

/**
 * Always-visible page header leads (crm-page-header__lead).
 *
 * Quick start tips are dismissible — these lines are not. Each string must answer:
 * (1) the page job in plain words, (2) where the rows/numbers come from, (3) why they show up.
 */
final class PageHelpCatalog
{
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @return list<string>
	 */
	public static function requiredPageIds(): array
	{
		return PageQuickStartCatalog::requiredPageIds();
	}

	public function forPage(string $pageId): ?string
	{
		$pageId = match ($pageId) {
			'workload' => 'team',
			default => $pageId,
		};

		return $this->definitions()[$pageId] ?? null;
	}

	/**
	 * @return array<string, string>
	 */
	private function definitions(): array
	{
		$l = $this->l10n;

		return [
			'today' => $l->t('Job: clear what needs you now. Rows come from this workspace — chase, renewals, invoices, promises, mail, and tasks.'),
			'pulse' => $l->t('Job: see how the book is doing. Numbers come from CustomerCheck plus linked ProjectCheck, InvoiceCheck, and TicketCheck — this workspace only, never mixed.'),
			'brief' => $l->t('Job: own this week’s promises and check last week. Cash dates come from overdue invoices and renewals with known amounts in this workspace — not pipeline hope.'),
			'my-accounts' => $l->t('Job: work your own book. Only companies in this workspace where you are account owner or plan owner — coverage gaps rise to the top.'),
			'team' => $l->t('Job: see who needs help. One row per teammate in this workspace — green means fine, amber means coach them. Counts come from chase, quotes, promises, and overdue work.'),
			'strategy' => $l->t('Job: review account plans. Plans are stored in CustomerCheck for this workspace — health and next review dates decide what looks stuck.'),
			'mail-triage' => $l->t('Job: assign unmatched shared mail. Messages come from the shared workspace mailbox only — personal Nextcloud Mail never appears here.'),
			'prepare-meeting' => $l->t('Job: prep one call. One stack for this company: open work, recent talk, coverage gaps, and plan goal from CustomerCheck and linked apps.'),
			'companies' => $l->t('Job: find any customer. Your registry for this workspace — search, status, and account owner. Filters apply to these companies only.'),
			'company-detail' => $l->t('Job: run one customer. Everything for this company in the current workspace — people, deals, mail, money, and plan; linked apps show when connected.'),
			'company-form' => $l->t('Job: create or edit a customer in this workspace. Name is enough to start; set an account owner so My customers and chase stay clear. ProjectCheck and TicketCheck links are optional.'),
			'pipeline' => $l->t('Job: move deals. Deals stored in CustomerCheck for this workspace — each has an owner. Forecast labels are planning only — not expected cash.'),
			'agreements' => $l->t('Job: watch renewals. Licenses and partner packages stored in CustomerCheck for this workspace. Expiry dates feed Money, Pulse, and Today.'),
			'agreement-form' => $l->t('Job: add or edit one agreement. It always belongs to one company in this workspace. Dates and amounts update Money and renewals automatically.'),
			'money' => $l->t('Job: chase cash. Invoice states from linked InvoiceCheck for companies in this workspace — overdue first, then unpaid and paid.'),
			'activities' => $l->t('Job: see logged work. Calls, emails, meetings, and to-dos in CustomerCheck for this workspace. Open items also feed Today.'),
			'import' => $l->t('Job: bring data in safely. Preview only until you accept. Sources: CSV upload or linked ProjectCheck / TicketCheck when those apps are on.'),
			'settings' => $l->t('Job: set up this book. Preferences, workspace members, shared mailbox, and sibling apps — nothing here leaves this Nextcloud.'),
			'app-settings' => $l->t('Job: run the app for the whole instance. Access, reminders, public tokens, mobile license seats, and the agreement catalogue — app administrators only; nothing leaves this Nextcloud.'),
			'support-us' => $l->t('Job: optional support for the project. The web app stays free under AGPL either way.'),
		];
	}
}
