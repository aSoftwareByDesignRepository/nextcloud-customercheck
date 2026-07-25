// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

const routes = [
  { path: '/apps/customercheck/', name: 'today' },
  { path: '/apps/customercheck/companies', name: 'companies' },
  { path: '/apps/customercheck/pipeline', name: 'pipeline' },
  { path: '/apps/customercheck/agreements', name: 'agreements' },
  { path: '/apps/customercheck/money', name: 'money' },
  { path: '/apps/customercheck/activities', name: 'activities' },
  { path: '/apps/customercheck/import', name: 'import' },
  { path: '/apps/customercheck/settings', name: 'settings' },
];

for (const { path, name } of routes) {
  test(`CRM layout smoke: ${name} has shell, stack, no overflow`, async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC_*_USER / NC_*_PASS');
    const creds = process.env.NC_ADMIN_USER
      ? credsFromEnv('ADMIN')
      : credsFromEnv('EMPLOYEE');
    await page.setViewportSize({ width: 375, height: 800 });
    await login(page, creds);
    await page.goto(path);
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('.crm-page-header').first()).toBeVisible();
    await expect(page.locator('.crm-page-stack').first()).toBeVisible();
    const overflow = await page.evaluate(() => {
      const main = document.getElementById('crm-main-content');
      if (!main) return false;
      return main.scrollWidth <= main.clientWidth + 2;
    });
    expect(overflow).toBe(true);
  });
}
