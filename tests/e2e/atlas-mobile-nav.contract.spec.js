// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open nav + no h-scroll */
import { createRequire } from 'node:module';
import { test } from '@playwright/test';

const require = createRequire(import.meta.url);
const { assertAtlasMobileNav } = require('../../../_shared/e2e/atlas-mobile-nav-contract.js');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || process.env.NC_BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
const DASHBOARD = process.env.E2E_CUC_DASHBOARD_URL || `${BASE}/apps/customercheck/`;

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto(DASHBOARD, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('[data-cuc-nav-toggle], #cuc-nav-toggle', { timeout: 30000 });
	await assertAtlasMobileNav(page, {
		toggle: page.locator('[data-cuc-nav-toggle], #cuc-nav-toggle').first(),
		nav: page.locator('#app-navigation'),
		openClass: /cuc-nav--open/,
	});
});
