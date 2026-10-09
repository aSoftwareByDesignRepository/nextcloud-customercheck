// @ts-check
/**
 * ATLAS_UI_INVARIANTS — CustomerCheck representative surfaces.
 *
 * Wires apps/_shared/e2e/atlas-ui-invariants.js onto the surfaces a CRM user
 * actually works on. Covered classes: a11y-dom (icon-only names, <24px
 * targets, focusable-in-aria-hidden, unannounced error callouts), stored-xss
 * (company name is a free-text field rendered on /companies + detail),
 * console errors, raw i18n keys, n+1 list requests, mutation freshness,
 * double-submit, form-loss-on-5xx.
 *
 * Fixture hygiene: every created entity carries the run stamp and is deleted
 * in finally — zero residue on the shared lab.
 */
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';

const require = createRequire(import.meta.url);
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertFormSurvivesFailure,
	assertSurfaceFresh,
	countApiRequests,
	trackConsoleErrors,
} = require('../../../_shared/e2e/atlas-ui-invariants.js');

const CONTENT = '#app-content';
const STAMP = `cucinv-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`;

/** session-cookie JSON call into the app API (browser context = logged in) */
async function api(page, method, path, body) {
	return page.evaluate(async ({ method: m, path: p, body: b }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '';
		const res = await fetch(p, {
			method: m,
			credentials: 'same-origin',
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: b === undefined ? undefined : JSON.stringify(b),
		});
		return { status: res.status, body: await res.json().catch(() => ({})) };
	}, { method, path, body });
}

async function createCompany(page, name) {
	const r = await api(page, 'POST', '/index.php/apps/customercheck/api/v1/companies', { name });
	return r.status === 201 && r.body && r.body.data ? r.body.data.id : null;
}

async function deleteCompany(page, id) {
	// Destructive-action contract: DELETE requires confirmSlug=<slug> verbatim.
	const detail = await api(page, 'GET', `/index.php/apps/customercheck/api/v1/companies/${id}`);
	const company = (detail.body && detail.body.data) || {};
	const slug = (company.company && company.company.slug) || company.slug;
	await api(page, 'DELETE', `/index.php/apps/customercheck/api/v1/companies/${id}`, { confirmSlug: slug });
}

test.describe('CustomerCheck UI invariants (atlas-ui-invariants)', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS (tests/e2e/.env)');

	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
		await login(page, credsFromEnv('ADMIN'));
	});

	// ── a11y-dom + console + raw-i18n sweep over representative surfaces ──
	for (const [label, url] of [
		['today', '/apps/customercheck/today'],
		['companies', '/apps/customercheck/companies'],
		['pipeline', '/apps/customercheck/pipeline'],
		['settings', '/apps/customercheck/settings'],
	]) {
		test(`a11y-dom /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page);
			await gotoCrm(page, url);
			// Radios/checkboxes under 24px are legal only with a >=24px label
			// target (WCAG 2.5.8 equivalent-target). Prove that before the sweep.
			const unlabeled = await page.evaluate(() => {
				const bad = [];
				document
					.querySelectorAll('#app-content input[type="radio"], #app-content input[type="checkbox"]')
					.forEach((inp) => {
						// A 0x0 input is inside a display:none ancestor (collapsed
						// details/dialog) — not reachable, so no target is owed.
						const ir = inp.getBoundingClientRect();
						const ics = getComputedStyle(inp);
						if (ir.width === 0 || ics.display === 'none' || ics.visibility === 'hidden') return;
						const lbl = inp.closest('label')
							|| (inp.id ? document.querySelector(`label[for="${CSS.escape(inp.id)}"]`) : null);
						if (!lbl) {
							bad.push(`no label: ${inp.id || inp.outerHTML.slice(0, 60)}`);
							return;
						}
						const r = lbl.getBoundingClientRect();
						const cs = getComputedStyle(lbl);
						if (cs.display === 'none' || cs.visibility === 'hidden' || (r.width === 0 && r.height === 0)) return;
						if (r.width < 24 || r.height < 24) {
							bad.push(`label ${Math.round(r.width)}x${Math.round(r.height)} < 24px: ${inp.id}`);
						}
					});
				return bad;
			});
			expect(unlabeled, `radio/checkbox without a >=24px label target on /${label}:\n${unlabeled.join('\n')}`).toEqual([]);
			const findings = await assertA11yDom(page, {
				content: CONTENT,
				// labelled small inputs are legal — the label is the target
				sizeAllow: 'label, .crm-check',
			});
			expect(findings, `a11y-dom findings on /${label}:\n${findings.join('\n')}`).toEqual([]);
			assertNoConsoleErrors(errs, { allow: [/favicon/i] });
			await assertNoRawI18nKeys(page, { content: CONTENT });
		});
	}

	// ── stored XSS: free-text company name rendered on list + detail ──
	test('stored-xss escaped on companies list + detail', async ({ page }) => {
		const name = `${STAMP} ${ATLAS_XSS_PAYLOADS[0]}`;
		const id = await createCompany(page, name);
		try {
			expect(id, 'company create via API').toBeTruthy();
			await gotoCrm(page, `/apps/customercheck/companies`);
			await assertNoInjection(page);
			await gotoCrm(page, `/apps/customercheck/companies/${id}`);
			await assertNoInjection(page);
		} finally {
			if (id) await deleteCompany(page, id);
		}
	});

	// ── mutation freshness: create then delete must surface ──
	test('mutation freshness on companies list', async ({ page }) => {
		const name = `${STAMP}-fresh`;
		// The default list is name-paginated — a lowercase 'cucinv-' fixture
		// sorts past page 1. The server-side q= filter makes the row
		// deterministically reachable instead of depending on page position.
		const filteredUrl = `/apps/customercheck/companies?q=${encodeURIComponent(name)}`;
		await gotoCrm(page, filteredUrl);
		const id = await createCompany(page, name);
		try {
			expect(id, 'company create via API').toBeTruthy();
			await assertSurfaceFresh(page, {
				content: CONTENT,
				mutate: async () => {},
				visit: async () => { await page.reload({ waitUntil: 'domcontentloaded' }); },
				expect: { present: name },
			});
		} finally {
			if (id) await deleteCompany(page, id);
		}
		await assertSurfaceFresh(page, {
			content: CONTENT,
			mutate: async () => {},
			visit: async () => { await page.reload({ waitUntil: 'domcontentloaded' }); },
			expect: { absent: name },
		});
	});

	// ── double-submit: rapid second submit must not escape to the server ──
	test('double-submit on company create sends one POST', async ({ page }) => {
		await gotoCrm(page, '/apps/customercheck/companies/new');
		await page.waitForSelector('#crm-company-form', { timeout: 30000 });
		await page.locator('#crm-company-name').fill(`${STAMP}-dbl`);
		const submit = page.locator('#crm-company-form button[type="submit"]');
		const created = [];
		page.on('response', (res) => {
			const m = res.request().url().match(/\/api\/v1\/companies\/(\d+)/);
			if (res.request().method() === 'GET' && m) created.push(Number(m[1]));
		});
		try {
			await assertNoDuplicateSubmit(page, {
				mutatingUrl: /\/api\/v1\/companies$/,
				method: 'POST',
				// force: the correct behavior disables the button mid-flight —
				// a real user can still machine-gun Enter/clicks, so dispatch
				// both events regardless of the disabled state.
				submit: () => submit.click({ force: true }).catch(() => {}),
			});
		} finally {
			// A company may have been created once — clean it up by listing.
			const list = await api(page, 'GET', '/index.php/apps/customercheck/api/v1/companies?limit=200');
			const rows = (list.body && list.body.data) ? list.body.data : [];
			for (const c of rows) {
				if (String(c.name || '').startsWith(STAMP)) await deleteCompany(page, c.id);
			}
		}
	});

	// ── form survival on 5xx: inputs retained + error rendered ──
	test('company form survives injected 500', async ({ page }) => {
		await gotoCrm(page, '/apps/customercheck/companies/new');
		await page.waitForSelector('#crm-company-form', { timeout: 30000 });
		await assertFormSurvivesFailure(page, {
			failUrl: /\/api\/v1\/companies$/,
			method: 'POST',
			fields: { '#crm-company-name': `${STAMP}-keep` },
			submit: () => page.locator('#crm-company-form button[type="submit"]').click(),
			// Scoped to the form's own callout — bare [role="alert"] would match
			// hidden global alert regions and never resolve to a visible node.
			errorSel: '#crm-company-form-error:not([hidden])',
		});
	});

	// ── O(1) list render: /companies must not fan out per-row requests ──
	test('companies list render is O(1) in API calls', async ({ page }) => {
		const hits = await countApiRequests(page, async () => {
			await gotoCrm(page, '/apps/customercheck/companies');
			await page.locator('#crm-companies-results').waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
		}, /\/api\/v1\/companies/);
		const listCalls = hits.filter((h) => /\/api\/v1\/companies(\?|$)/.test(h.url) && h.method === 'GET');
		expect(listCalls.length, `companies list fetches: ${listCalls.map((h) => h.url).join(' | ')}`).toBeLessThanOrEqual(2);
		const perRow = hits.filter((h) => /\/api\/v1\/companies\/\d+/.test(h.url));
		expect(perRow, `per-row N+1 fetches: ${perRow.map((h) => h.url).join(' | ')}`).toEqual([]);
	});
});
