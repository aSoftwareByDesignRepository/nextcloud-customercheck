<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;

/**
 * Always-visible page header leads (crm-page-header__lead).
 *
 * Quick start tips are dismissible — these lines are not. Keep each lead to one
 * short, plain sentence a non-expert can act on. Prefer team/customer words over
 * multi-tenant jargon.
 */
final class PageHelpCatalog
{
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Help leads cover every Quick start page plus company-detail (Welcome Coach
	 * owns tips there — still needs a short page lead).
	 *
	 * @return list<string>
	 */
	public static function requiredPageIds(): array
	{
		$ids = PageQuickStartCatalog::requiredPageIds();
		if (!in_array('company-detail', $ids, true)) {
			$insertAt = array_search('companies', $ids, true);
			if ($insertAt === false) {
				$ids[] = 'company-detail';
			} else {
				array_splice($ids, $insertAt + 1, 0, ['company-detail']);
			}
		}

		return $ids;
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
			'today' => $l->t('Pick one row and finish it — follow-ups, renewals, mail, and tasks waiting on you.'),
			'pulse' => $l->t('A quick look at how your customers are doing.'),
			'brief' => $l->t('Your promises for this week — and a check that last week’s were kept.'),
			'my-accounts' => $l->t('Companies you own or plan — gaps rise to the top.'),
			'team' => $l->t('Who needs help — one row per teammate. On track means fine; Needs help means coach them.'),
			'strategy' => $l->t('Account plans — open a company to add or edit one.'),
			'mail-triage' => $l->t('Assign unmatched shared mail to a company. Personal Nextcloud Mail never appears here.'),
			'review' => $l->t('Decide unmatched items — assign to a company or mark no relation.'),
			'conflicts' => $l->t('Two people edited the same field — pick what to keep and write a short reason.'),
			'prepare-meeting' => $l->t('One pack for the next call: people, recent talk, open work, and money.'),
			'companies' => $l->t('Find any customer — search, filter, then open.'),
			'company-detail' => $l->t('Add a person first, then deals and messages. Linked apps show when connected — if you see two Acmes, open the identity support guide in Help docs.'),
			'company-form' => $l->t('Name is enough to start — you open the customer page right after save.'),
			'pipeline' => $l->t('Move deals here. A new deal needs a company and a title — value is optional.'),
			'agreements' => $l->t('Licenses and partner packages — keep end dates accurate for renewals.'),
			'agreement-form' => $l->t('One agreement always belongs to one company. Dates and amounts update Money automatically.'),
			'money' => $l->t('Overdue, unpaid, paid and unknown invoice states.'),
			'activities' => $l->t('Calls, emails, meetings, and to-dos — open items also show on Today.'),
			'import' => $l->t('Drop a CSV or JSON file, or paste names. We show a preview first — nothing is saved yet.'),
			'settings' => $l->t('Team members, shared mailbox, and your preferences.'),
			'app-settings' => $l->t('Access, reminders, intake URLs, and seats for the whole Nextcloud — admins only.'),
			'support-us' => $l->t('Optional support for the project. The web app stays free under AGPL either way.'),
		];
	}
}
