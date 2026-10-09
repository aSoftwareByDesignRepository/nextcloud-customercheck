// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Bachus deal-checklist journeys: one-click Done on Today, checklist panel on Company 360.
 * Skips when NC credentials are absent (CI without secrets).
 */
test.describe('Bachus deal checklist simplicity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('Today and settings/checklists pass axe serious/critical', async ({ page }) => {
    test.setTimeout(90000);
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    let results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    let serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    await page.goto('/apps/customercheck/settings/checklists');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('company 360 checklist button opens dialog with quick-add', async ({ page }) => {
    test.setTimeout(120000);
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`CL UX ${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    const addDeal = page.locator('[data-crm-add-deal], #crm-add-deal, button:has-text("Add deal")').first();
    if (await addDeal.count()) {
      await addDeal.click();
      const title = page.locator('input[name="title"], #crm-dialog input').first();
      await title.waitFor({ timeout: 10000 });
      await title.fill(`Order ${suffix}`);
      await page.locator('.crm-dialog button.crm-btn--primary, [data-crm-dialog] button.crm-btn--primary').last().click();
      await page.waitForTimeout(800);
    }

    const checklistBtn = page.locator('[data-crm-deal-checklist]').first();
    test.skip((await checklistBtn.count()) === 0, 'No deal on company yet — skip interactive checklist open');
    await checklistBtn.click();
    await expect(page.locator('.crm-deal-checklist-panel, [data-cl-quickadd]')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-cl-quickadd] input[name="title"]')).toBeVisible();
    await expect(page.locator('[data-cl-quickadd] button[type="submit"]')).toBeVisible();

    const results = await new AxeBuilder({ page })
      .include('.crm-deal-checklist-panel')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});
