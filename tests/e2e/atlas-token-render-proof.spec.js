// @ts-check
/**
 * atlas web_api reopen-delta leg (spec webapi-cuc-reopen-delta-20261121T2148Z):
 * rendered proof that the post-sync token set resolves on a real surface —
 * the --crm-bg-card chain repointed from dead --crm-bg-page/--crm-surface refs
 * (company-360 cards/panels), the --crm-mono stack + --crm-muted-on-tint
 * resolving on the themed body block — on light AND dark themes.
 * Writes PNG + JSON proof to $CUC_PROOF_DIR.
 */
import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { login, credsFromEnv } from './helpers/auth.js';

const PROOF = process.env.CUC_PROOF_DIR || 'test-results/atlas-token-proofs';
mkdirSync(PROOF, { recursive: true });

async function enableTheme(page, themeId) {
	const status = await page.evaluate(async (id) => {
		const res = await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${id}/enable`, {
			method: 'PUT',
			headers: { 'OCS-APIREQUEST': 'true', requesttoken: window.OC.requestToken },
		});
		return res.status;
	}, themeId);
	if (status !== 200) throw new Error(`theme enable ${themeId} → HTTP ${status}`);
	// data-theme-* is stamped on body at render time — reload applies it.
	await page.reload({ waitUntil: 'domcontentloaded' });
	await expect.poll(
		async () => page.evaluate((id) => document.body.hasAttribute(`data-theme-${id}`), themeId),
		{ timeout: 15000 },
	).toBe(true);
}

async function disableTheme(page, themeId) {
	await page.evaluate(async (id) => {
		await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
			method: 'DELETE',
			headers: { 'OCS-APIREQUEST': 'true', requesttoken: window.OC.requestToken },
		});
	}, themeId);
}

test('tokens: repointed surface chain + new tokens resolve on light and dark', async ({ page }) => {
	await login(page, credsFromEnv('ADMIN'));
	await page.goto('/apps/customercheck/companies/240', { waitUntil: 'domcontentloaded' });
	// Real surface: company-360 renders .crm-card + .crm-360-focus panels whose
	// backgrounds were repointed --crm-bg-page/--crm-surface -> --crm-bg-card.
	await expect(page.locator('.crm-card, .crm-360-focus__panel').first()).toBeVisible({ timeout: 20000 });

	const report = { themes: {} };

	for (const theme of ['light', 'dark']) {
		await enableTheme(page, theme);
		await page.waitForTimeout(400);

		const proof = await page.evaluate(() => {
			const bodyCs = getComputedStyle(document.body);
			const mainBg = bodyCs.getPropertyValue('--color-main-background').trim();
			const mono = bodyCs.getPropertyValue('--crm-mono').trim();
			const mutedOnTint = bodyCs.getPropertyValue('--crm-muted-on-tint').trim();
			const resolved = (prop) => {
				const el = document.createElement('div');
				el.style.cssText = `display:none;background-color:var(${prop})`;
				document.body.appendChild(el);
				const bg = getComputedStyle(el).backgroundColor;
				el.remove();
				return bg;
			};
			const card = document.querySelector('.crm-card');
			const panel = document.querySelector('.crm-360-focus__panel, .crm-page-stack > .crm-card');
			return {
				mainBg,
				mono,
				mutedOnTint,
				bgCardResolved: resolved('--crm-bg-card'),
				mainBgResolved: resolved('--color-main-background'),
				cardBg: card ? getComputedStyle(card).backgroundColor : null,
				panelBg: panel ? getComputedStyle(panel).backgroundColor : null,
			};
		});

		report.themes[theme] = proof;
		await page.screenshot({ path: join(PROOF, `token-render-company360-${theme}.png`), fullPage: false });
		await disableTheme(page, theme);
	}

	writeFileSync(join(PROOF, 'token-render-proof.json'), JSON.stringify(report, null, 2));

	for (const theme of ['light', 'dark']) {
		const t = report.themes[theme];
		expect(t.mainBg, `${theme}: --color-main-background must resolve`).toBeTruthy();
		// The repointed --crm-bg-card chain paints card/panel surfaces with the
		// theme main background (identical computed value to the dead names).
		expect(t.bgCardResolved, `${theme}: --crm-bg-card must equal main-background`).toBe(t.mainBgResolved);
		expect(t.cardBg, `${theme}: .crm-card must paint --crm-bg-card`).toBe(t.bgCardResolved);
		// New tokens resolve on the themed body block (consumer surfaces are
		// data-dependent — adjudicated in closeout).
		expect(t.mono.toLowerCase(), `${theme}: --crm-mono stack must resolve`).toMatch(/mono/);
		expect(t.mutedOnTint, `${theme}: --crm-muted-on-tint must resolve`).toMatch(/color-mix/);
	}
});
