<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;

/**
 * Always-visible page header leads (crm-page-header__lead).
 *
 * Quick start tips are dismissible — these lines are not. Keep each lead to one
 * short, plain sentence a non-expert can act on.
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
			'today' => $l->t('Pick one row and finish it — follow-ups, renewals, mail, and tasks for this workspace.'),
			'pulse' => $l->t('A quick look at how this customer book is doing — this workspace only.'),
			'brief' => $l->t('Your promises for this week in this workspace — and a check that last week’s were kept.'),
			'my-accounts' => $l->t('Companies you own or plan in this workspace — gaps rise to the top.'),
			'team' => $l->t('Who needs help — one row per teammate in this workspace. On track means fine; Needs help means coach them.'),
			'strategy' => $l->t('Account plans for this workspace — open a company to add or edit one.'),
			'mail-triage' => $l->t('Assign unmatched shared mail to a company. Personal Nextcloud Mail never appears here.'),
			'review' => $l->t('Decide unmatched items for this workspace — assign to a company or mark no relation.'),
			'conflicts' => $l->t('Two people edited the same field in this workspace — pick what to keep and write a short reason.'),
			'prepare-meeting' => $l->t('One pack for the next call from CustomerCheck: people, recent talk, open work, and money.'),
			'companies' => $l->t('Find any customer in this workspace — search, filter, then open.'),
			'company-detail' => $l->t('Add a person first, then deals and messages. Linked apps show when connected — if you see two Acmes, open the identity support guide in Help docs.'),
			'company-form' => $l->t('Name is enough to start in this workspace — you open the customer page right after save.'),
			'pipeline' => $l->t('Move deals in this workspace. New deal needs a company and a title — value is optional.'),
			'agreements' => $l->t('Licenses and partner packages in this workspace — keep end dates accurate for renewals.'),
			'agreement-form' => $l->t('One agreement for one company in this workspace. Dates and amounts update Money automatically.'),
			'money' => $l->t('Overdue, unpaid, paid and unknown invoice states for this workspace.'),
			'activities' => $l->t('Calls, emails, meetings, and to-dos in CustomerCheck — open items also show on Today.'),
			'import' => $l->t('Scan first. Nothing is written until you accept. ProjectCheck imports link by default. Sources stay in this Nextcloud.'),
			'settings' => $l->t('Members, mailbox, and preferences for this customer book.'),
			'app-settings' => $l->t('Access, reminders, intake URLs, and seats for the whole Nextcloud — admins only.'),
			'support-us' => $l->t('Optional support for the project. The web app stays free under AGPL either way.'),
		];
	}
}
