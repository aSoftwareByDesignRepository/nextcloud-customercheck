// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Humans must see company names — never hunt for numeric IDs in list UIs.
 */
test.describe('CustomerCheck human-readable lists', () => {
  test('agreements and activities show company names, company form has name pickers', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC credentials');
    const creds = process.env.NC_ADMIN_USER ? credsFromEnv('ADMIN') : credsFromEnv('EMPLOYEE');
    await login(page, creds);

    await page.goto('/apps/customercheck/agreements');
    await page.waitForSelector('#customercheck-app', { timeout: 30000 });
    const agreementHeaders = await page.locator('#crm-agreements-filter, .crm-card table thead').first().textContent().catch(() => '');
    const agreementTable = page.locator('.crm-card table').first();
    if (await agreementTable.count()) {
      await expect(agreementTable.locator('th').filter({ hasText: 'Company' })).toBeVisible();
      const body = await agreementTable.locator('tbody').innerText();
      expect(body).not.toMatch(/→\s*#\d+/);
      expect(body).not.toMatch(/^#\d+$/m);
    }

    await page.goto('/apps/customercheck/activities');
    await page.waitForSelector('#customercheck-app', { timeout: 30000 });
    const actTable = page.locator('.crm-card table').first();
    if (await actTable.count()) {
      await expect(actTable.locator('th').filter({ hasText: 'Company' })).toBeVisible();
      await expect(page.getByRole('link', { name: 'Open company' })).toHaveCount(0);
    }

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    await expect(page.locator('#crm-company-pc[type="number"]')).toHaveCount(0);
    await expect(page.locator('#crm-company-tc[type="number"]')).toHaveCount(0);
    // Either searchable select (sibling available) or muted unavailable note — never a raw ID field.
    const pcSelect = page.locator('select#crm-company-pc');
    const pcHidden = page.locator('input#crm-company-pc[type="hidden"]');
    expect((await pcSelect.count()) + (await pcHidden.count())).toBeGreaterThan(0);
    void agreementHeaders;
  });
});
