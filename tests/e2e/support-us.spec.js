// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

test.describe('CustomerCheck Support & us', () => {
  test('dedicated page shows AZC-family hero and partner CTAs', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC credentials');
    const creds = process.env.NC_ADMIN_USER ? credsFromEnv('ADMIN') : credsFromEnv('EMPLOYEE');
    await login(page, creds);
    await page.goto('/apps/customercheck/support-us');
    await page.waitForSelector('#customercheck-app', { timeout: 30000 });

    const pageRoot = page.locator('.crm-support-us-page');
    await expect(pageRoot).toBeVisible();
    await expect(pageRoot).toHaveAttribute('data-crm-support-us-layout', 'offer-grid');
    await expect(page.locator('.crm-support-us-page__hero')).toBeVisible();
    await expect(page.locator('#crm-support-us-hero-title')).toBeVisible();
    await expect(page.locator('#crm-support-us')).toBeVisible();
    await expect(page.locator('#crm-support-us')).toHaveAttribute('data-support-us', '1');
    await expect(page.locator('#crm-support-us')).toHaveAttribute('data-support-us-presentation', 'page');
    await expect(page.locator('.crm-support-us__option-title')).toHaveCount(2);
    await expect(page.locator('a.crm-support-us__cta--primary')).toBeVisible();
    await expect(page.locator('.crm-support-us-page__wordmark-by')).toHaveText(/BY DESIGN/);
    await expect(page.locator('.crm-support-us-page__mark')).toHaveAttribute('src', /vendor-logo-mark\.png/);

    const mainText = await page.locator('#crm-main-content').innerText();
    expect(mainText.trim().length).toBeGreaterThan(80);
    expect(mainText).toMatch(/CustomerCheck|AGPL|Partner/i);
  });
});
