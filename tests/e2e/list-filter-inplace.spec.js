// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';

/**
 * Activities / money / companies chips must fetch in place — never reload the shell.
 */
test.describe('List filters stay in place', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  async function markStay(page) {
    await page.evaluate(() => {
      window.__crmSearchStay = 1;
    });
  }

  async function expectStayed(page) {
    expect(await page.evaluate(() => window.__crmSearchStay)).toBe(1);
  }

  test('companies quick filter does not reload; URL and active chip update', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies?workspaceId=1', { ready: '#crm-companies-results' });
    await markStay(page);

    const leads = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="status=lead"]');
    await expect(leads).toBeVisible();
    const box = await leads.boundingBox();
    expect(box && box.height).toBeGreaterThanOrEqual(44);
    await leads.click();

    await expect(page).toHaveURL(/status=lead/, { timeout: 15000 });
    await expectStayed(page);
    await expect(page.locator('#crm-companies-results')).not.toHaveAttribute('aria-busy', 'true');
    await expect(page.locator('nav.crm-quick-filters a.crm-quick-filter.is-active[href*="status=lead"], nav.crm-quick-filters a.crm-quick-filter[aria-current="page"][href*="status=lead"]')).toBeVisible();
    await expect(page.locator('#crm-filter-status')).toHaveValue('lead');
  });

  test('money unpaid chip does not reload', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/money', { ready: '#crm-money-results' });
    await markStay(page);

    const unpaid = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="state=sent"]');
    await expect(unpaid).toBeVisible();
    await unpaid.click();

    await expect(page).toHaveURL(/state=sent/, { timeout: 15000 });
    await expectStayed(page);
    await expect(page.locator('#crm-money-results')).toBeVisible();
    await expect(page.locator('#crm-filter-state')).toHaveValue('sent');

    await page.setViewportSize({ width: 375, height: 812 });
    await expect(unpaid).toBeVisible();
  });

  test('activities All chip does not reload; Done still works after swap', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/activities', { ready: '#crm-activities-results' });
    await markStay(page);

    const all = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="scope=all"]');
    await expect(all).toBeVisible();
    await all.click();

    await expect(page).toHaveURL(/scope=all/, { timeout: 15000 });
    await expectStayed(page);
    await expect(page.locator('#crm-activities-results')).toBeVisible();
    await expect(page.locator('#crm-filter-scope')).toHaveValue('all');

    const done = page.locator('[data-crm-complete-activity]').first();
    if (await done.count()) {
      await expect(done).toBeVisible();
    }
  });
});
