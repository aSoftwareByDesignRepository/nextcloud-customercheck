<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;

/**
 * Always-visible page header leads (crm-page-header__lead).
 *
 * Quick start tips are dismissible — these lines are not. Each string must answer:
 * (1) what you are looking at, (2) where the rows/numbers come from, (3) why they show up.
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
			'today' => $l->t('Rows come from this workspace: chase, renewals, invoices, promises, mail, and tasks that need you now.'),
			'pulse' => $l->t('Workspace health from CustomerCheck plus linked ProjectCheck, InvoiceCheck, and TicketCheck — only this book, never mixed.'),
			'brief' => $l->t('Your owned weekly promises for this workspace. Cash dates come from overdue invoices and renewals with known amounts — not pipeline hope.'),
			'my-accounts' => $l->t('Only companies in this workspace where you are account owner or plan owner — coverage gaps rise to the top.'),
			'team' => $l->t('One row per teammate in this workspace — green means fine, amber means coach them. Counts come from chase, quotes, promises, and overdue work.'),
			'strategy' => $l->t('Account plans stored in CustomerCheck for this workspace — health and next review dates decide what looks stuck.'),
			'mail-triage' => $l->t('Unmatched messages from the shared workspace mailbox only — personal Nextcloud Mail never appears here.'),
			'prepare-meeting' => $l->t('One stack for this company: open work, recent talk, coverage gaps, and plan goal from CustomerCheck and linked apps.'),
			'companies' => $l->t('Your customer registry for this workspace. Search and filters apply to these companies only.'),
			'company-detail' => $l->t('Everything for this customer in the current workspace — people, deals, mail, money, and plan; linked apps show when connected.'),
			'company-form' => $l->t('Create or edit a customer in this workspace. Name is enough to start; ProjectCheck and TicketCheck links are optional.'),
			'pipeline' => $l->t('Deals stored in CustomerCheck for this workspace. Forecast labels are planning only — not expected cash.'),
			'agreements' => $l->t('Licenses and partner packages stored in CustomerCheck for this workspace. Expiry dates feed Money, Pulse, and Today.'),
			'agreement-form' => $l->t('An agreement always belongs to one company in this workspace. Dates and amounts update Money and renewals automatically.'),
			'money' => $l->t('Invoice states from linked InvoiceCheck for companies in this workspace — overdue first, then unpaid and paid.'),
			'activities' => $l->t('Calls, emails, meetings, and to-dos logged in CustomerCheck for this workspace. Open items also feed Today.'),
			'import' => $l->t('Preview only until you accept. Sources: CSV upload or linked ProjectCheck / TicketCheck when those apps are on.'),
			'settings' => $l->t('Preferences, workspace members, shared mailbox, and sibling apps for this book — nothing here leaves this Nextcloud.'),
			'app-settings' => $l->t('Instance-wide CustomerCheck access, reminders, public tokens, mobile license seats, and the agreement catalogue — app administrators only; nothing leaves this Nextcloud.'),
			'support-us' => $l->t('Optional support for the project. The web app stays free under AGPL either way.'),
		];
	}
}
