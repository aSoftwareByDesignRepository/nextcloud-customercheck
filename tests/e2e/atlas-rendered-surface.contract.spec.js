// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — CustomerCheck page surfaces.
 *
 * Asserts the *rendered* truth of each app page: content lists keep markers,
 * selects vertically centre their value, icons render non-zero, form controls
 * are not centred by shell leaks. DOM-only specs pass on visually broken pages
 * (marker resets, sunken selects, 0×0 icons) — this catches them.
 *
 * Every routed page surface is listed in SURFACES. Marker-less list designs
 * (kanban lists, chip rows, todo rows, mail threads, coach steps, settings
 * rows — all with `list-style:none` + custom item chrome in css/app.css) are
 * CustomerCheck design language and opt out via listAllow — never silently
 * skipped.
 */
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { login, credsFromEnv } from './helpers/auth.js';

const require = createRequire(import.meta.url);
const { assertAtlasRenderedSurface } = require(
	'../../../_shared/e2e/atlas-rendered-surface-contract.js',
);

// Every page#* route in appinfo/routes.php except detail/fragment routes
// (company {id}, agreement {id}, prepare-meeting 301, settings/{section} is
// covered by /settings root which redirects to the first section).
const SURFACES = [
	['/apps/customercheck/', 'index'],
	['/apps/customercheck/today', 'today'],
	['/apps/customercheck/pulse', 'pulse'],
	['/apps/customercheck/brief', 'brief'],
	['/apps/customercheck/my-accounts', 'my-accounts'],
	['/apps/customercheck/team', 'team'],
	['/apps/customercheck/strategy', 'strategy'],
	['/apps/customercheck/mail-triage', 'mail-triage'],
	['/apps/customercheck/sales-inbox', 'sales-inbox'],
	['/apps/customercheck/review', 'review'],
	['/apps/customercheck/conflicts', 'conflicts'],
	['/apps/customercheck/companies', 'companies-list'],
	['/apps/customercheck/companies/new', 'company-form'],
	['/apps/customercheck/pipeline', 'pipeline'],
	['/apps/customercheck/agreements', 'agreements-list'],
	['/apps/customercheck/agreements/new', 'agreement-form'],
	['/apps/customercheck/money', 'money'],
	['/apps/customercheck/activities', 'activities'],
	['/apps/customercheck/import', 'import'],
	['/apps/customercheck/settings', 'settings'],
	['/apps/customercheck/app-settings', 'app-settings'],
	['/apps/customercheck/support-us', 'support-us'],
	['/apps/customercheck/get-the-app', 'get-the-app'],
];

// Marker-less by design (css/app.css sets list-style:none on these components:
// kanban/chip/todo/coach/mail rows, picker listboxes, settings member lists,
// disclosure summaries). Asserted via listAllow — additions must name a class.
const LIST_ALLOW = [
	'.crm-breadcrumb__list',
	'.crm-list',
	'.crm-jump-nav__list',
	'.crm-360-focus__tabs',
	'.crm-howto__steps',
	'.crm-emails__deep-links',
	'.crm-welcome-coach__steps',
	'.crm-link-list',
	'.crm-kanban__list',
	'.crm-chip-list',
	'.crm-linked-bundle__summary',
	'.crm-linked-status',
	'.crm-deal-checklist',
	'.crm-checklist-tmpl-list',
	'.crm-glance-density__list',
	'.crm-review-more',
	'.crm-mail-thread',
	'.crm-communication-search-empty',
	'.crm-brief-verify__more',
	'.crm-team-capacity__panel',
	'.crm-team-capacity__options',
	'.crm-prepared-mail__attach-list',
	'.crm-sales-compose__attach-list',
	'.crm-plan-more__summary',
	'.crm-ws-members',
	'.crm-ws-members__empty',
	'.crm-mailbox-compose__summary',
	'.crm-mailbox-list',
	'.crm-quickstart__list',
	'.crm-next-steps__extra-details',
	'.crm-todo-list',
	'.crm-personal-mail-servers__summary',
	'.crm-stat-strip',
	'.crm-identity-more__summary',
	'.team-compliance-member-list',
	'.crm-company-list',
	'.user-picker__list',
	'.crm-get-app__features',
	'.crm-support-us-page__trust',
].join(', ');

// Deliberately centred components: empty-state callouts, the datepicker day
// grid, matrix/checkbox columns, denied cards — not inherited shell leaks.
const CENTER_ALLOW = [
	'.crm-empty-state',
	'.crm-access-denied',
	'.crm-denied__card',
	'.crm-datepicker',
	'.crm-table--matrix',
	'.crm-table-select-col',
	'.crm-import-drop',
	'.crm-mail-reader__empty',
	'.team-compliance-stat',
].join(', ');

test.describe('ATLAS_RENDERED_SURFACE_CONTRACT', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

	test.beforeEach(async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'));
	});

	for (const [path_, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name}`, async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto(path_, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('#crm-main-content')).toBeAttached({ timeout: 30_000 });
			// Persona mounts render async — wait for aria-busy to clear.
			await page.waitForFunction(() => {
				const mounts = document.querySelectorAll('[data-crm-persona-mount][aria-busy="true"]');
				return mounts.length === 0;
			}, null, { timeout: 30_000 }).catch(() => {});
			await assertAtlasRenderedSurface(page, {
				content: '#crm-main-content',
				navExclude: '#app-navigation, nav, .crm-nav, .crm-jump-nav, .crm-breadcrumb',
				listAllow: LIST_ALLOW,
				centerAllow: CENTER_ALLOW,
			});
		});
	}
});
