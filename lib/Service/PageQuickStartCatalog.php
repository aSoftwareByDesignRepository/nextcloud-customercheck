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
			'mail-triage', 'prepare-meeting', 'companies', 'company-detail', 'company-form',
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
		$link = static function (string $route, array $params = []) use ($u): string {
			return $u->linkToRoute('customercheck.page.' . $route, $params);
		};

		return [
			'today' => [
				'lead' => $l->t('Your daily command board — pick one row and finish it.'),
				'steps' => [
					[
						'title' => $l->t('1. Scan the queues'),
						'text' => $l->t('Chase, renewals, invoices, promises, mail, and tasks from this workspace are grouped. Start at the top — that is what needs you first.'),
					],
					[
						'title' => $l->t('2. Take one action'),
						'text' => $l->t('Open the company, mark Done, triage mail, or coach a teammate. One clear button per row.'),
					],
					[
						'title' => $l->t('3. Jump when stuck'),
						'text' => $l->t('Use Jump to queue to skip to a section. Managers also see team stuck items here.'),
						'href' => $link('team'),
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
						'href' => $link('today'),
						'linkLabel' => $l->t('Open Command Board'),
					],
					[
						'title' => $l->t('3. Use Money for amounts'),
						'text' => $l->t('Pulse shows health. Open Money when you need overdue and unpaid details.'),
						'href' => $link('money'),
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
						'text' => $l->t('Missing economic buyer, champion, or day-to-day contact? Open the company and add people.'),
						'href' => $link('companies'),
						'linkLabel' => $l->t('Open Companies'),
					],
					[
						'title' => $l->t('3. Keep plans alive'),
						'text' => $l->t('Stalled plans need a next step. Use Strategy for the full plan list.'),
						'href' => $link('strategy'),
						'linkLabel' => $l->t('Open Strategy'),
					],
				],
			],
			'team' => [
				'lead' => $l->t('Coach people who are stuck. Cover absences. Move work safely.'),
				'steps' => [
					[
						'title' => $l->t('1. Spot who needs help'),
						'text' => $l->t('Amber means coach them. Green means fine. Rows use chase, quotes, promises, and overdue work from this workspace.'),
					],
					[
						'title' => $l->t('2. Cover while away'),
						'text' => $l->t('In Capacity tools, pick who is out and who covers. Ownership stays with the primary person.'),
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
						'href' => $link('myAccounts'),
						'linkLabel' => $l->t('Open My Accounts'),
					],
				],
			],
			'mail-triage' => [
				'lead' => $l->t('Assign unmatched shared mailbox messages to the right company.'),
				'steps' => [
					[
						'title' => $l->t('1. Open a message'),
						'text' => $l->t('Only unmatched mail from the shared workspace mailbox waits here — not your personal inbox.'),
					],
					[
						'title' => $l->t('2. Match a company'),
						'text' => $l->t('Pick the customer it belongs to, or create a company if it is new.'),
						'href' => $link('companyNew'),
						'linkLabel' => $l->t('New company'),
					],
					[
						'title' => $l->t('3. Shared mailbox only'),
						'text' => $l->t('This queue is for the shared inbox — personal mail stays in Nextcloud Mail.'),
					],
				],
			],
			'prepare-meeting' => [
				'lead' => $l->t('One stack for the next call or QBR — context without tab chaos.'),
				'steps' => [
					[
						'title' => $l->t('1. Skim the stack'),
						'text' => $l->t('Open tasks, recent talk, and plan goal sit in one place for this company.'),
					],
					[
						'title' => $l->t('2. Note what you will ask'),
						'text' => $l->t('Use gaps and overdue items as your meeting agenda. Keep it to three questions if you can.'),
					],
					[
						'title' => $l->t('3. After the call'),
						'text' => $l->t('Log the activity and update promises on the company page so Today stays truthful.'),
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
						'href' => $link('companyNew'),
						'linkLabel' => $l->t('New company'),
					],
				],
			],
			'company-detail' => [
				'lead' => $l->t('Everything about one customer — people, deals, mail, money, plan.'),
				'steps' => [
					[
						'title' => $l->t('1. Jump to a section'),
						'text' => $l->t('Use the jump bar to reach people, pipeline, emails, documents, or the timeline.'),
					],
					[
						'title' => $l->t('2. Keep coverage full'),
						'text' => $l->t('Add economic buyer, champion, and day-to-day contacts so renewals stay safe.'),
					],
					[
						'title' => $l->t('3. Link Files'),
						'text' => $l->t('Browse a Nextcloud folder for contracts and notes — never link the Files home root.'),
					],
				],
			],
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
						'text' => $l->t('After saving, add people and set buying roles on the company page.'),
						'href' => $link('companies'),
						'linkLabel' => $l->t('Back to Companies'),
					],
				],
			],
			'pipeline' => [
				'lead' => $l->t('Deals as a table or board — stages, quotes, and honest forecast labels.'),
				'steps' => [
					[
						'title' => $l->t('1. Pick table or board'),
						'text' => $l->t('Deals live in CustomerCheck for this workspace. Use the view that helps you move them.'),
					],
					[
						'title' => $l->t('2. Update stage truthfully'),
						'text' => $l->t('Commit and Upside are planning labels — not expected cash. Lost deals need a reason code.'),
					],
					[
						'title' => $l->t('3. Chase old quotes'),
						'text' => $l->t('Quotes sitting too long show up on Today and Team. Open the deal and follow up or close it.'),
						'href' => $link('today'),
						'linkLabel' => $l->t('Open Command Board'),
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
						'href' => $link('agreementNew'),
						'linkLabel' => $l->t('New agreement'),
					],
					[
						'title' => $l->t('3. Watch runway'),
						'text' => $l->t('Renewal dates feed Money and company glance — keep end dates accurate.'),
					],
				],
			],
			'agreement-form' => [
				'lead' => $l->t('Add a license or partner package — pick the company first.'),
				'steps' => [
					[
						'title' => $l->t('1. Choose the company'),
						'text' => $l->t('Agreements always hang under a customer. Create the company first if it is missing.'),
					],
					[
						'title' => $l->t('2. Fill product and dates'),
						'text' => $l->t('Use a clear label, product code when you have one, and honest start/end dates.'),
					],
					[
						'title' => $l->t('3. Save'),
						'text' => $l->t('You can edit later. Renewals and Money views pick up the new dates automatically.'),
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
						'href' => $link('settings'),
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
						'href' => $link('companies'),
						'linkLabel' => $l->t('Open Companies'),
					],
				],
			],
			'import' => [
				'lead' => $l->t('Bring customers in from CSV or linked apps — review before you commit.'),
				'steps' => [
					[
						'title' => $l->t('1. Pick a source'),
						'text' => $l->t('Upload a CSV or pull from ProjectCheck / TicketCheck when those apps are enabled.'),
					],
					[
						'title' => $l->t('2. Preview matches'),
						'text' => $l->t('Check duplicates and names carefully. Bad imports are hard to untangle later.'),
					],
					[
						'title' => $l->t('3. Import, then enrich'),
						'text' => $l->t('After import, open companies to add people, roles, and folders.'),
						'href' => $link('companies'),
						'linkLabel' => $l->t('Open Companies'),
					],
				],
			],
			'settings' => [
				'lead' => $l->t('Workspace members, mailbox, preferences, and sibling apps for this book.'),
				'steps' => [
					[
						'title' => $l->t('1. Add workspace members'),
						'text' => $l->t('Managers, members, and viewers see different Team and write tools. Start with real people.'),
					],
					[
						'title' => $l->t('2. Configure mailbox if shared'),
						'text' => $l->t('Shared mailbox powers Mail triage. Keep credentials in Workspace settings — never in chat.'),
					],
					[
						'title' => $l->t('3. Personal preference'),
						'text' => $l->t('Pick table or board as your default pipeline view. Access policy and seats live under App settings.'),
						'href' => $link('appSettings'),
						'linkLabel' => $l->t('Open App settings'),
					],
				],
			],
			'app-settings' => [
				'lead' => $l->t('Who may use CustomerCheck, reminders, tokens, mobile seats, and the agreement catalogue.'),
				'steps' => [
					[
						'title' => $l->t('1. Review access'),
						'text' => $l->t('App administrators always have access. Turn restriction on and allowlist groups or users for everyone else.'),
					],
					[
						'title' => $l->t('2. Rotate tokens when needed'),
						'text' => $l->t('ICS feed, lead webhook, and order webhook are public URLs. Rotate if a token leaks.'),
					],
					[
						'title' => $l->t('3. Seats and catalogue'),
						'text' => $l->t('Mobile license seats and custom product codes are set here. Workspace members and mailbox stay on Workspace settings.'),
						'href' => $link('settings'),
						'linkLabel' => $l->t('Open Workspace settings'),
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
