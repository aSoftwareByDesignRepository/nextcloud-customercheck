<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Dismissible Quick start how-tos per page — DutyCheck-style granny instructions.
 *
 * @phpstan-type QuickStartStep array{title: string, text: string, href?: string, linkLabel?: string}
 * @phpstan-type QuickStartCard array{key: string, domId: string, lead: string, steps: list<QuickStartStep>}
 */
final class PageQuickStartCatalog
{
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $url,
	) {
	}

	/**
	 * @return QuickStartCard|null
	 */
	public function forPage(string $pageId): ?array
	{
		$pageId = match ($pageId) {
			'workload' => 'team',
			default => $pageId,
		};
		$def = $this->definitions()[$pageId] ?? null;
		if ($def === null) {
			return null;
		}

		return [
			'key' => $pageId . '_quickstart_v1',
			'domId' => 'crm-quickstart-' . $pageId,
			'lead' => $def['lead'],
			'steps' => $def['steps'],
		];
	}

	/**
	 * Page ids that must ship a quickstart (contract tests).
	 *
	 * @return list<string>
	 */
	public static function requiredPageIds(): array
	{
		return [
			'today', 'pulse', 'brief', 'my-accounts', 'team', 'strategy',
			'mail-triage', 'sales-inbox', 'review', 'conflicts', 'companies', 'company-form',
			'pipeline', 'agreements', 'agreement-form', 'money', 'activities',
			'import', 'settings', 'app-settings', 'support-us',
		];
	}

	/**
	 * @return array<string, array{lead: string, steps: list<QuickStartStep>}>
	 */
	private function definitions(): array
	{
		$l = $this->l10n;
		$u = $this->url;

		return [
			'today' => [
				'lead' => $l->t('Your daily list — pick one row and finish it.'),
				'steps' => [
					[
						'title' => $l->t('1. Scan the queues'),
						'text' => $l->t('Follow-ups, renewals, invoices, promises, mail, and tasks from this workspace are grouped. Start at the top — that is what needs you first.'),
					],
					[
						'title' => $l->t('2. Take one action'),
						'text' => $l->t('Open the company, mark Done, triage mail, or coach a teammate. One clear button per row.'),
					],
					[
						'title' => $l->t('3. Jump when stuck'),
						'text' => $l->t('Use Jump to queue to skip to a section. Managers also see team stuck items here.'),
						'href' => $u->linkToRoute('customercheck.page.team'),
						'linkLabel' => $l->t('Open Team'),
					],
				],
			],
			'pulse' => [
				'lead' => $l->t('See workspace health at a glance — then fix what looks stuck.'),
				'steps' => [
					[
						'title' => $l->t('1. Read the metrics'),
						'text' => $l->t('Numbers come from this workspace’s companies plus linked ProjectCheck, InvoiceCheck, and TicketCheck when those apps are on.'),
					],
					[
						'title' => $l->t('2. Follow the red flags'),
						'text' => $l->t('Open anything that looks stuck — then fix it on Today, Team, or Companies.'),
						'href' => $u->linkToRoute('customercheck.page.today'),
						'linkLabel' => $l->t('Open Today'),
					],
					[
						'title' => $l->t('3. Use Money for amounts'),
						'text' => $l->t('Overview shows health. Open Money when you need overdue and unpaid details.'),
						'href' => $u->linkToRoute('customercheck.page.money'),
						'linkLabel' => $l->t('Open Money'),
					],
				],
			],
			'brief' => [
				'lead' => $l->t('Commit what you own this week, then verify you kept the promise.'),
				'steps' => [
					[
						'title' => $l->t('1. Review owned work'),
						'text' => $l->t('Commitments are yours for this workspace. Expected cash uses overdue invoices and renewals with known € — never pipeline × hope.'),
					],
					[
						'title' => $l->t('2. Commit or adjust'),
						'text' => $l->t('Say what you will finish. Change anything that is no longer realistic before you confirm.'),
					],
					[
						'title' => $l->t('3. Verify later'),
						'text' => $l->t('Come back to mark what was done. Missed items stay visible so coaching stays fair.'),
					],
				],
			],
			'my-accounts' => [
				'lead' => $l->t('Companies you own or plan — coverage gaps first.'),
				'steps' => [
					[
						'title' => $l->t('1. Find your accounts'),
						'text' => $l->t('Only companies where you are owner or planner in this workspace appear here — nobody else’s book.'),
					],
					[
						'title' => $l->t('2. Fix coverage gaps'),
						'text' => $l->t('Missing a budget decision-maker, main contact, or day-to-day contact? Open the company and add people.'),
						'href' => $u->linkToRoute('customercheck.page.companies'),
						'linkLabel' => $l->t('Open Companies'),
					],
					[
						'title' => $l->t('3. Keep plans alive'),
						'text' => $l->t('Stalled plans need a next step. Use Strategy for the full plan list.'),
						'href' => $u->linkToRoute('customercheck.page.strategy'),
						'linkLabel' => $l->t('Open Plans'),
					],
				],
			],
			'team' => [
				'lead' => $l->t('Coach people who are stuck. Cover absences. Move work safely.'),
				'steps' => [
					[
						'title' => $l->t('1. Spot who needs help'),
						'text' => $l->t('Needs help means coach them. On track means fine. Rows use open deals, quotes, promises, and overdue work from this workspace.'),
					],
					[
						'title' => $l->t('2. Cover while away'),
						'text' => $l->t('In Manager tools, pick who is out and who covers. Ownership stays with the primary person.'),
					],
					[
						'title' => $l->t('3. Move or escalate'),
						'text' => $l->t('Reassign deals and promises with a typed confirm, or escalate when a manager must step in.'),
					],
				],
			],
			'strategy' => [
				'lead' => $l->t('Account plans across the workspace — goals, health, next moves.'),
				'steps' => [
					[
						'title' => $l->t('1. Scan plan health'),
						'text' => $l->t('Healthy, at risk, and stalled plans are listed from CustomerCheck for this workspace so you know where to coach.'),
					],
					[
						'title' => $l->t('2. Open the company'),
						'text' => $l->t('Update the action plan on the company 360 page — keep goals short and clear.'),
					],
					[
						'title' => $l->t('3. Align with owners'),
						'text' => $l->t('Each plan has an owner. Use My Accounts or Team when someone is behind.'),
						'href' => $u->linkToRoute('customercheck.page.myAccounts'),
						'linkLabel' => $l->t('Open My customers'),
					],
				],
			],
			'mail-triage' => [
				'lead' => $l->t('Assign unmatched shared mail to a company — personal Mail never appears here.'),
				'steps' => [
					[
						'title' => $l->t('1. Open a message'),
						'text' => $l->t('Only the shared workspace mailbox feeds this list.'),
					],
					[
						'title' => $l->t('2. Pick a company'),
						'text' => $l->t('Search or accept the suggestion, then Assign. Or mark No relation.'),
					],
					[
						'title' => $l->t('3. Need more sources?'),
						'text' => $l->t('Contacts, Files, Calendar, Tasks, and import anomalies live under Inbox → Unassigned (filter chips).'),
						'href' => $u->linkToRoute('customercheck.page.salesInbox'),
						'linkLabel' => $l->t('Open Inbox'),
					],
				],
			],
			'sales-inbox' => [
				'lead' => $l->t('Your mail in one place — Personal, Team, and Unassigned.'),
				'steps' => [
					[
						'title' => $l->t('1. Pick a tab'),
						'text' => $l->t('Personal is your connected mailbox. Team is the shared inbox. Unassigned is where you assign items to a company.'),
					],
					[
						'title' => $l->t('2. Open a message'),
						'text' => $l->t('Click a row to read a plain-text preview. Private badges mean only you can see that mail.'),
					],
					[
						'title' => $l->t('3. Connect your email'),
						'text' => $l->t('Managers enable personal sync under Team & mailbox. Then open My email and pick folders.'),
						'href' => $u->linkToRoute('customercheck.page.settingsSection', ['section' => 'my-email']),
						'linkLabel' => $l->t('Open My email'),
					],
				],
			],
			'review' => [
				'lead' => $l->t('One inbox for unmatched mail and other review items — assign or dismiss.'),
				'steps' => [
					[
						'title' => $l->t('1. Read the suggestion'),
						'text' => $l->t('When a company is suggested, tap Assign. Use the chips to filter by source.'),
					],
					[
						'title' => $l->t('2. Or say no relation'),
						'text' => $l->t('No relation closes the item without a company. More options hides snooze and follow-up.'),
					],
					[
						'title' => $l->t('3. Turn sources on'),
						'text' => $l->t('Managers enable Contacts, Files, Calendar, Tasks, or import anomalies under Team & mailbox.'),
						'href' => $u->linkToRoute('customercheck.page.settingsSection', ['section' => 'review-sources']),
						'linkLabel' => $l->t('Assign source settings'),
					],
				],
			],
			'conflicts' => [
				'lead' => $l->t('Two people changed the same field — pick what to keep and write why.'),
				'steps' => [
					[
						'title' => $l->t('1. Open the conflict'),
						'text' => $l->t('Notifications deep-link here. The saved value is pre-selected.'),
					],
					[
						'title' => $l->t('2. Choose and resolve'),
						'text' => $l->t('Keep what’s saved, take the other edit, or type a value. A short reason is enough.'),
					],
					[
						'title' => $l->t('3. Back to work'),
						'text' => $l->t('When the queue is empty, jump back to Today.'),
						'href' => $u->linkToRoute('customercheck.page.today'),
						'linkLabel' => $l->t('Open Today'),
					],
				],
			],
			'companies' => [
				'lead' => $l->t('Your customer registry — find, filter, and open a 360° view.'),
				'steps' => [
					[
						'title' => $l->t('1. Search or filter'),
						'text' => $l->t('These companies belong to the workspace you selected. Type a name, or use quick filters for active and archived.'),
					],
					[
						'title' => $l->t('2. Open a company'),
						'text' => $l->t('The 360 page holds people, deals, mail, money, and the action plan.'),
					],
					[
						'title' => $l->t('3. Add when missing'),
						'text' => $l->t('Create a company before you log deals or agreements. Import can bring many at once.'),
						'href' => $u->linkToRoute('customercheck.page.companyNew'),
						'linkLabel' => $l->t('New company'),
					],
				],
			],
			// company-detail: no Quick start — Welcome Coach owns next steps on the 360 page.
			'company-form' => [
				'lead' => $l->t('Create or edit a customer record — name first, details later.'),
				'steps' => [
					[
						'title' => $l->t('1. Enter the name'),
						'text' => $l->t('Just enter a clear company name. Advanced fields stay collapsed until you need them.'),
					],
					[
						'title' => $l->t('2. Link apps when ready'),
						'text' => $l->t('ProjectCheck or TicketCheck links are optional. You can add them after save.'),
					],
					[
						'title' => $l->t('3. Save and open 360'),
						'text' => $l->t('After saving you land on the customer page — add a person next.'),
						'href' => $u->linkToRoute('customercheck.page.companies'),
						'linkLabel' => $l->t('Back to Companies'),
					],
				],
			],
			'pipeline' => [
				'lead' => $l->t('Deals as a table or board — pick a company, give the deal a title, move the stage.'),
				'steps' => [
					[
						'title' => $l->t('1. Pick table or board'),
						'text' => $l->t('Deals live in CustomerCheck for this workspace. Use the view that helps you move them.'),
					],
					[
						'title' => $l->t('2. New deal = company + title'),
						'text' => $l->t('That is enough to create one. Value and products stay under More options.'),
					],
					[
						'title' => $l->t('3. Follow up on old quotes'),
						'text' => $l->t('Quotes sitting too long show up on Today. Open the deal and follow up or close it.'),
						'href' => $u->linkToRoute('customercheck.page.today'),
						'linkLabel' => $l->t('Open Today'),
					],
				],
			],
			'agreements' => [
				'lead' => $l->t('Licenses, partner packages, and one-time items tied to companies.'),
				'steps' => [
					[
						'title' => $l->t('1. Filter what matters'),
						'text' => $l->t('Agreements are stored in CustomerCheck under companies in this workspace. Find renewals or a single company quickly.'),
					],
					[
						'title' => $l->t('2. Open or create'),
						'text' => $l->t('Each agreement belongs to one company. Create from here or from the company page.'),
						'href' => $u->linkToRoute('customercheck.page.agreementNew'),
						'linkLabel' => $l->t('New agreement'),
					],
					[
						'title' => $l->t('3. Watch runway'),
						'text' => $l->t('Renewal dates feed Money and company glance — keep end dates accurate.'),
					],
				],
			],
			'agreement-form' => [
				'lead' => $l->t('Name, end date, and amount first — More holds type and product.'),
				'steps' => [
					[
						'title' => $l->t('1. Pick the company'),
						'text' => $l->t('Agreements always hang under a customer. Create the company first if it is missing.'),
					],
					[
						'title' => $l->t('2. Name, until, amount'),
						'text' => $l->t('A clear label, when it ends, and the euro amount. That is enough for Money and renewals.'),
					],
					[
						'title' => $l->t('3. Save — renew later'),
						'text' => $l->t('On edit, Renew extends one year. Cancel lives under End this agreement.'),
					],
				],
			],
			'money' => [
				'lead' => $l->t('Invoice states from linked apps — overdue first, then unpaid and paid.'),
				'steps' => [
					[
						'title' => $l->t('1. Start with overdue'),
						'text' => $l->t('Overdue rows come from InvoiceCheck for companies linked in this workspace. Open the company to act.'),
					],
					[
						'title' => $l->t('2. Check unpaid'),
						'text' => $l->t('Sent but not paid yet. Confirm the invoice still matches the agreement.'),
					],
					[
						'title' => $l->t('3. Unknown needs a link'),
						'text' => $l->t('If InvoiceCheck is not linked, states may show as unknown until you connect the apps.'),
						'href' => $u->linkToRoute('customercheck.page.settings'),
						'linkLabel' => $l->t('Open Settings'),
					],
				],
			],
			'activities' => [
				'lead' => $l->t('Calls, emails, meetings, and to-dos — filter, complete, follow up.'),
				'steps' => [
					[
						'title' => $l->t('1. Filter the list'),
						'text' => $l->t('These are activities logged in CustomerCheck for this workspace. Narrow by type or open vs done.'),
					],
					[
						'title' => $l->t('2. Complete or open'),
						'text' => $l->t('Mark Done when finished, or open the company for context.'),
					],
					[
						'title' => $l->t('3. Log from the company'),
						'text' => $l->t('New activities are best created on the company 360 page so history stays together.'),
						'href' => $u->linkToRoute('customercheck.page.companies'),
						'linkLabel' => $l->t('Open Companies'),
					],
				],
			],
			'import' => [
				'lead' => $l->t('Bring customers in — drop a file, check the preview, tap import.'),
				'steps' => [
					[
						'title' => $l->t('1. Drop or paste'),
						'text' => $l->t('A CSV/JSON from mail, or one company name per line. Other apps appear as tiles when installed.'),
					],
					[
						'title' => $l->t('2. Check the preview'),
						'text' => $l->t('Ready rows are safe. Only names with a choice need a quick pick.'),
					],
					[
						'title' => $l->t('3. Tap import'),
						'text' => $l->t('One green button adds the ready companies. Then open them to add people.'),
						'href' => $u->linkToRoute('customercheck.page.companies'),
						'linkLabel' => $l->t('Open Companies'),
					],
				],
			],
			'settings' => [
				'lead' => $l->t('Use the chips above — team mailbox for managers, preferences for everyone.'),
				'steps' => [
					[
						'title' => $l->t('1. Team mailbox (managers)'),
						'text' => $l->t('Add members and set up the shared inbox under Team mailbox. That powers Assign mail.'),
						'href' => $u->linkToRoute('customercheck.page.settingsSection', ['section' => 'mailbox']),
						'linkLabel' => $l->t('Open Team mailbox'),
					],
					[
						'title' => $l->t('2. My email'),
						'text' => $l->t('When managers allow personal sync, connect your inbox and pick folders here.'),
						'href' => $u->linkToRoute('customercheck.page.settingsSection', ['section' => 'my-email']),
						'linkLabel' => $l->t('Open My email'),
					],
					[
						'title' => $l->t('3. Preferences'),
						'text' => $l->t('Pick table or board as your default deals view. Access policy and seats live under App settings.'),
						'href' => $u->linkToRoute('customercheck.page.appSettings'),
						'linkLabel' => $l->t('Open App settings'),
					],
				],
			],
			'app-settings' => [
				'lead' => $l->t('Who may use CustomerCheck, reminders, public links, mobile seats, and the agreement catalogue.'),
				'steps' => [
					[
						'title' => $l->t('1. Review access'),
						'text' => $l->t('App administrators always have access. Turn restriction on and allowlist groups or users for everyone else.'),
					],
					[
						'title' => $l->t('2. Rotate public links when needed'),
						'text' => $l->t('Calendar renewal feed and website lead/order links are public URLs. Rotate if a link leaks.'),
					],
					[
						'title' => $l->t('3. Seats and catalogue'),
						'text' => $l->t('Mobile license seats and custom product codes are set here. Team members and mailbox stay on Team & mailbox.'),
						'href' => $u->linkToRoute('customercheck.page.settings'),
						'linkLabel' => $l->t('Open Team & mailbox'),
					],
				],
			],
			'support-us' => [
				'lead' => $l->t('Optional partner care — the app stays free under AGPL either way.'),
				'steps' => [
					[
						'title' => $l->t('1. Use the app freely'),
						'text' => $l->t('CustomerCheck is open source. You never need to pay to keep using it.'),
					],
					[
						'title' => $l->t('2. Support if it helps'),
						'text' => $l->t('Sponsors and partner care fund fixes, reviews, and new features when you want them prioritised.'),
					],
					[
						'title' => $l->t('3. Ask for commissioned work'),
						'text' => $l->t('Organisations can request prioritised features via the contact options on this page.'),
					],
				],
			],
		];
	}
}
