// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

const pages = [
  '/apps/customercheck/companies',
  '/apps/customercheck/',
  '/apps/customercheck/pipeline',
  '/apps/customercheck/agreements',
  '/apps/customercheck/money',
  '/apps/customercheck/activities',
  '/apps/customercheck/companies/new',
];

for (const path of pages) {
  test(`CRM a11y: ${path} has no serious/critical violations`, async ({ page }) => {
    test.setTimeout(90000);
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC credentials');
    const creds = process.env.NC_ADMIN_USER ? credsFromEnv('ADMIN') : credsFromEnv('EMPLOYEE');
    await login(page, creds);
    await page.goto(path);
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
}

test('CRM a11y: company 360 has no serious/critical violations', async ({ page }) => {
  test.setTimeout(90000);
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
  await login(page, credsFromEnv('ADMIN'));
  await page.goto('/apps/customercheck/companies/new');
  await page.waitForSelector('#crm-main-content', { timeout: 30000 });
  const suffix = Date.now().toString(36);
  await page.locator('#crm-company-name').fill(`Axe Co ${suffix}`);
  await page.locator('#crm-company-advanced').evaluate((el) => {
    if (el instanceof HTMLDetailsElement) el.open = true;
  });
  await page.locator('#crm-company-slug').fill(`axe-co-${suffix}`);
  await page.locator('#crm-company-form').evaluate((form) => {
    if (form instanceof HTMLFormElement) form.requestSubmit();
  });
  await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
  const results = await new AxeBuilder({ page })
    .include('#crm-main-content')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
    .analyze();
  const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
});
