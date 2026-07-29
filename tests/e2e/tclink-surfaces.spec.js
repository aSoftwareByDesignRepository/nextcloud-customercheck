// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * WP-S1-CUC-TCLINK — TicketCheck create/link surface (mirrors PC Advanced panel).
 */
test.describe('CUC TicketCheck link surfaces', () => {
  test.skip(!process.env.NC_ADMIN_USER || !process.env.NC_ADMIN_PASS, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test.beforeAll(async ({ request }) => {
    const base = process.env.NC_BASE_URL || 'http://localhost:8081';
    const res = await request.get(`${base}/ocs/v1.php/cloud/user`, {
      headers: {
        'OCS-APIRequest': 'true',
        Authorization: 'Basic ' + Buffer.from(`${process.env.NC_ADMIN_USER}:${process.env.NC_ADMIN_PASS}`).toString('base64'),
      },
    });
    test.skip(res.status() === 401 || res.status() === 403, `Admin credentials rejected (${res.status()})`);
  });

  test('new company Advanced exposes TicketCheck link or muted copy', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    const response = await page.goto('/apps/customercheck/companies/new', { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response.status()).toBeLessThan(400);
    const advanced = page.locator('#crm-company-advanced');
    await expect(advanced).toBeVisible();
    if (!(await advanced.evaluate((el) => el instanceof HTMLDetailsElement && el.open))) {
      await advanced.locator('summary').click();
    }
    await expect(page.locator('#crm-company-tc, #crm-company-tc-help').first()).toBeVisible({ timeout: 15000 });
    const help = await page.locator('#crm-company-tc-help').innerText();
    expect(/TicketCheck|not available|nicht verfügbar/i.test(help)).toBeTruthy();
  });
});
