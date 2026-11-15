// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall license settings page must scroll to the end.
 * Guards CSS Overflow L3 unpaired overflow-x:clip truncating seat picker / form actions.
 */
import { createRequire } from 'node:module';
import { test } from '@playwright/test';

const require = createRequire(import.meta.url);
const { assertAtlasVerticalScrollReachable } = require(
	'../../../_shared/e2e/atlas-vertical-scroll-contract.js',
);

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || process.env.NC_BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
const LICENSE =
	process.env.E2E_CUC_LICENSE_URL || `${BASE}/apps/customercheck/app-settings/license`;

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test('app-settings license scrolls to form / seats block', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 640 });
		await page.goto(LICENSE, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector(
			'#section-license, #crm-license-key-input',
			{ timeout: 45_000 },
		);

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			// shared-pack markup: last rendered section is the scroll end
			// (seats/terminals render only when licensed — key card otherwise)
			target: '#crm-license-page > section:last-of-type',
			bottomSlopPx: 12,
		});
	});

	test('app-settings license stays reachable at phone height', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 667 });
		await page.goto(LICENSE, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector(
			'#section-license, #crm-license-key-input',
			{ timeout: 45_000 },
		);

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#crm-license-page > section:last-of-type',
			bottomSlopPx: 16,
		});
	});
});
