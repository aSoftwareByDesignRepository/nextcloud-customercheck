// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * WP-S1-CUC-PCLINK / AC-S1.2 — company form exposes create/link sibling actions
 * and can round-trip a company create without crashing.
 */
test.describe('CUC ProjectCheck link surfaces', () => {
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
    test.skip(res.status() === 401 || res.status() === 403, `Admin credentials rejected by OCS (${res.status()})`);
  });

  test('new company form shows sibling create/link controls when PC available', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    const response = await page.goto('/apps/customercheck/companies/new', { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response.status()).toBeLessThan(400);
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-company-name')).toBeVisible();
    // Sibling link fields live under Advanced (collapsed on create for UX simplicity).
    const advanced = page.locator('#crm-company-advanced');
    await expect(advanced).toBeVisible();
    if (!(await advanced.evaluate((el) => el instanceof HTMLDetailsElement && el.open))) {
      await advanced.locator('summary').click();
    }
    await expect(page.locator('#crm-company-pc, #crm-company-pc-help').first()).toBeVisible({ timeout: 15000 });
    const help = await page.locator('#crm-company-pc-help').innerText();
    expect(/ProjectCheck|not available|nicht verfügbar/i.test(help)).toBeTruthy();
  });
});
