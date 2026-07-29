// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * WP-S1-CUC-ABSENCE / Suite D6 — hub pages must stay up (HTTP 200 + shell)
 * even when sibling apps are missing or muted.
 *
 * Requires valid NC_ADMIN_USER / NC_ADMIN_PASS (OCS Basic auth must succeed).
 */
test.describe('CUC hub absence / degrade surfaces', () => {
  test.skip(!process.env.NC_ADMIN_USER || !process.env.NC_ADMIN_PASS, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test.beforeAll(async ({ request }) => {
    const base = process.env.NC_BASE_URL || 'http://localhost:8081';
    const user = process.env.NC_ADMIN_USER;
    const pass = process.env.NC_ADMIN_PASS;
    const res = await request.get(`${base}/ocs/v1.php/cloud/user`, {
      headers: {
        'OCS-APIRequest': 'true',
        Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64'),
      },
    });
    test.skip(res.status() === 401 || res.status() === 403, `Admin credentials rejected by OCS (${res.status()}) — update tests/e2e/.env`);
  });

  for (const path of ['/apps/customercheck/today', '/apps/customercheck/companies', '/apps/customercheck/money']) {
    test(`${path} returns usable shell (200)`, async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });
      await login(page, credsFromEnv('ADMIN'));
      const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
      expect(response, path).not.toBeNull();
      expect(response.status(), path).toBeLessThan(400);
      await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
      await expect(page.locator('#app-navigation')).toBeVisible();
    });
  }

  test('company 360 shows muted sibling copy or live panels without crashing', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    // Literal /companies/new must not match — only numeric company detail routes.
    const detailHref = await page.locator('a[href*="/apps/customercheck/companies/"]').evaluateAll((anchors) => {
      const hit = anchors.find((a) => /\/apps\/customercheck\/companies\/\d+(\/|$|\?)/.test(a.getAttribute('href') || ''));
      return hit ? hit.getAttribute('href') : null;
    });
    if (!detailHref) {
      test.skip(true, 'No companies seeded');
      return;
    }
    const response = await page.goto(detailHref, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response.status()).toBeLessThan(400);
    await expect(page).toHaveURL(/\/apps\/customercheck\/companies\/\d+/);
    await expect(page.locator('#crm-glance, #crm-tickets, #crm-contracts').first()).toBeVisible({ timeout: 15000 });
    const body = await page.locator('#crm-main-content').innerText();
    expect(body.length).toBeGreaterThan(20);
    // Either live sibling density counts or the D6 muted capability copy —
    // never a blank crash. Density alone is not enough (it always mounts).
    const muted = /not available|App not installed|nicht verfügbar|nicht installiert/i.test(body);
    const densityLinks = await page.locator('#crm-glance a[href]').count();
    const densityMuted = await page.locator('#crm-glance .crm-capability-note, #crm-glance .crm-muted, #crm-tickets .crm-capability-note').count();
    expect(muted || densityLinks > 0 || densityMuted > 0).toBeTruthy();
  });
});
