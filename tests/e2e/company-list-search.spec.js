// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Companies search must update the table in place — typing must not reload the page.
 */
test.describe('Companies list live search', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('typing does not reload; results and URL update; focus stays in the box', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    const suffix = Date.now().toString(36);
    const name = `ZeusSearch ${suffix}`;
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    await page.locator('#crm-company-name').fill(name);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    await page.goto('/apps/customercheck/companies?workspaceId=1');
    await page.waitForSelector('#crm-companies-q', { timeout: 30000 });
    await page.waitForSelector('#crm-companies-results', { timeout: 30000 });

    await page.evaluate(() => {
      window.__crmSearchStay = 1;
    });

    const search = page.locator('#crm-companies-q');
    await search.click();
    await search.pressSequentially(name, { delay: 60 });

    await expect(search).toBeFocused();
    expect(await page.evaluate(() => window.__crmSearchStay)).toBe(1);
    await expect(page.locator('#crm-companies-table')).toContainText(name, { timeout: 15000 });
    await expect(page).toHaveURL(/q=ZeusSearch/);
    await expect(page.locator('#crm-companies-search-clear')).toBeVisible();
    await expect(page.locator('#crm-companies-results')).not.toHaveAttribute('aria-busy', 'true');

    const box = await search.boundingBox();
    expect(box && box.height).toBeGreaterThanOrEqual(44);

    await page.setViewportSize({ width: 375, height: 812 });
    await expect(search).toBeVisible();
    const mobileBox = await search.boundingBox();
    expect(mobileBox && mobileBox.height).toBeGreaterThanOrEqual(44);
  });
});
