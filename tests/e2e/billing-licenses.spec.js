// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Money card must track license invoice payment even when InvoiceCheck is off.
 */
test.describe('Billing license payment status', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('overdue license invoice shows critical Money tiles + honesty; axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Billing Lic ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    const companyId = page.url().match(/companies\/(\d+)/)?.[1];
    expect(companyId).toBeTruthy();

    await page.goto(`/apps/customercheck/agreements/new?companyId=${companyId}`);
    await page.waitForSelector('#crm-agreement-form', { timeout: 30000 });
    const label = `Pay me ${suffix}`;
    await page.locator('#crm-ag-label').fill(label);
    const until = new Date();
    until.setFullYear(until.getFullYear() + 1);
    const untilIso = `${until.getFullYear()}-${String(until.getMonth() + 1).padStart(2, '0')}-${String(until.getDate()).padStart(2, '0')}`;
    await page.locator('#crm-ag-until').fill(untilIso);
    await page.locator('#crm-ag-amount').fill('990.00');
    await page.locator('#crm-agreement-advanced > summary').click();
    await expect(page.locator('#crm-agreement-advanced')).toHaveAttribute('open', '');
    await page.locator('#crm-ag-invoice-status').selectOption('overdue');
    await page.locator('#crm-agreement-form button[type="submit"]').click();
    await page.waitForURL(new RegExp(`/apps/customercheck/companies/${companyId}`), { timeout: 30000 });

    await page.locator('#crm-billing').scrollIntoViewIfNeeded();
    await expect(page.locator('#crm-billing-body .crm-billing-strip')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#crm-billing-body [data-crm-billing-tile="status"] .crm-stat-tile__value')).toContainText(
      /Overdue|Überfällig/i,
    );
    await expect(page.locator('#crm-billing-body [data-crm-billing-tile="overdue"] .crm-stat-tile__value')).toHaveText('1');
    await expect(page.locator('#crm-billing-body [data-crm-billing-tile="licenses"] .crm-stat-tile__value')).toHaveText('1');
    await expect(page.locator('#crm-billing-body .crm-billing-honesty')).toContainText(
      /license payment|Lizenz|InvoiceCheck/i,
    );
    await expect(page.locator('#crm-billing-body .crm-billing-actions a[href="#crm-contracts"]')).toBeVisible();

    const results = await new AxeBuilder({ page })
      .include('#crm-billing')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});
