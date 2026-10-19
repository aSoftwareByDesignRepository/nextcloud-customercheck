// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/customercheck/craft/web/. The companion
 * gate scripts/check-screenshot-matrix.py verifies completeness — a
 * declared cell without a real PNG fails the ready-gate craft stage.
 *
 * States:
 *   populated — default workspace (seed data from the lab fixture) plus one
 *               matrix company created via the real API in beforeAll
 *   empty     — honest zero-row render via the surface's own filter param
 *               (declared per surface as `emptyQuery`: companies `?q=`,
 *               pipeline `?stage=`, agreements `?kind=`, money `?state=`,
 *               activities `?type=` — each lands verbatim in the controller's
 *               search filters). Only declared where a deterministic
 *               zero-result filter exists; CustomerCheck is single-org
 *               (MULTI_WORKSPACE_ENABLED=false) so a fresh-workspace empty
 *               render is impossible and dashboards declare populated only.
 *   current   — state-invariant chrome (forms, settings, support-us)
 *
 * Run:
 *   npx playwright test e2e/capture-screenshot-matrix.spec.js
 */
import { test, expect } from '@playwright/test';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { credsFromEnv, login, dismissOrFailOnUpdateNeeded } from '../tests/e2e/helpers/auth.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const MANIFEST = JSON.parse(readFileSync(join(__dirname, 'screenshot-matrix.json'), 'utf8'));
const OUT = resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/customercheck', MANIFEST.outDir);
const APP = '/index.php/apps/customercheck';
const TS = Date.now();

let rt = '';
let companyId = 0;

async function api(request, path, opts = {}) {
	const res = await request.fetch(`${APP}/api/v1${path}`, {
		...opts,
		headers: { requesttoken: rt, 'Content-Type': 'application/json', ...(opts.headers || {}) },
	});
	const body = await res.json();
	expect(body.success, `API ${opts.method || 'GET'} ${path} failed: ${JSON.stringify(body)}`).toBe(true);
	return body;
}

async function enableTheme(page, themeId) {
	const status = await page.evaluate(async (id) => {
		const res = await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${id}/enable`, {
			method: 'PUT',
			headers: { 'OCS-APIRequest': 'true', requesttoken: window.OC.requestToken },
		});
		return res.status;
	}, themeId);
	if (status !== 200) {
		throw new Error(`theme enable ${themeId} → HTTP ${status}`);
	}
	// The data-theme-* attribute is rendered server-side — reload so the
	// just-enabled theme is actually applied before polling for it.
	await page.reload({ waitUntil: 'domcontentloaded' });
	await expect
		.poll(async () => page.evaluate((id) => document.body.hasAttribute(`data-theme-${id}`), themeId), { timeout: 15000 })
		.toBe(true);
}

async function disableTheme(page, themeId) {
	await page.evaluate(async (id) => {
		await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
			method: 'DELETE',
			headers: { 'OCS-APIRequest': 'true', requesttoken: window.OC.requestToken },
		});
	}, themeId);
}

/** Hide volatile chrome so captures are review-clean, not noise-clean. */
async function settle(page, url) {
	await page.goto(url, { waitUntil: 'domcontentloaded' });
	await dismissOrFailOnUpdateNeeded(page);
	await page.evaluate(() => {
		document.querySelectorAll('.crm-toast, [role="alert"].crm-toast, #toast-container').forEach((n) => n.remove());
	});
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
	await page.waitForTimeout(350);
}

function shotName(surface, state, vp, theme) {
	return `cuc-${surface.id}-${state}-${vp.name}${theme === 'dark' ? '-dark' : ''}.png`;
}

function surfacePath(surface) {
	return surface.path.replace('{companyId}', String(companyId));
}

function* matrixCells(theme) {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) {
					continue;
				}
				yield { surface, state, vp };
			}
		}
	}
}

function hasCreds() {
	try {
		credsFromEnv('ADMIN');
		return true;
	} catch {
		return false;
	}
}

test.describe('SCREENSHOT_MATRIX capture', () => {
	test.skip(!hasCreds(), 'no NC_ADMIN_USER/NC_ADMIN_PASS creds');
	test.setTimeout(900_000);

	test.beforeAll(async ({ browser }) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		const { username, password } = credsFromEnv('ADMIN');
		await login(page, { username, password });
		await page.goto(`${APP}/today`, { waitUntil: 'domcontentloaded' });
		rt = await page.evaluate(() => {
			const oc = /** @type {any} */ (window).OC;
			return (oc && oc.requestToken)
				|| document.head.getAttribute('data-requesttoken')
				|| '';
		});
		expect(rt, 'no requesttoken on app page').not.toBe('');
		const request = page.context().request;

		// Seed one matrix company so populated list/detail cells carry a row.
		const company = await api(request, '/companies', {
			method: 'POST',
			data: { name: `MX Matrix GmbH ${TS}` },
		});
		companyId = company.data.id;
		expect(companyId, 'company create returned no id').toBeGreaterThan(0);

		await ctx.close();
	});

	test.afterAll(async ({ browser }) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		const { username, password } = credsFromEnv('ADMIN');
		await login(page, { username, password });
		await page.goto(`${APP}/today`, { waitUntil: 'domcontentloaded' });
		await disableTheme(page, 'dark');
		await ctx.close();
	});

	test('captures every manifest cell', async ({ page }) => {
		mkdirSync(OUT, { recursive: true });
		const missing = [];

		for (const theme of ['light', 'dark']) {
			if (theme === 'dark') {
				await enableTheme(page, 'dark');
			}
			for (const { surface, state, vp } of matrixCells(theme)) {
				await page.setViewportSize({ width: vp.width, height: vp.height });
				const scoped = state === 'empty' ? (surface.emptyQuery || '') : '';
				const url = `${APP}${surfacePath(surface)}${scoped}`;
				await settle(page, url);
				await expect(page.locator('#crm-main-content, #app-content').first())
					.toBeVisible({ timeout: 15_000 });
				const file = join(OUT, shotName(surface, state, vp, theme));
				// #app-content scrolls internally — a viewport-height shot would
				// only capture the fold. Grow the viewport to full content height.
				const contentH = await page.evaluate(() =>
					(document.querySelector('#app-content') || document.documentElement).scrollHeight);
				const shotH = Math.min(Math.max(contentH + 60, vp.height), 8192);
				if (shotH !== vp.height) {
					await page.setViewportSize({ width: vp.width, height: shotH });
					await page.waitForTimeout(150);
				}
				await page.screenshot({ path: file });
				if (!existsSync(file)) {
					missing.push(file);
				}
			}
			if (theme === 'dark') {
				await disableTheme(page, 'dark');
			}
		}

		expect(missing, `matrix cells missing: ${missing.join(', ')}`).toEqual([]);
	});
});
