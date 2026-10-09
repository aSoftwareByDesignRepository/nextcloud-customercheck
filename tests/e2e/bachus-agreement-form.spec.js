// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Bachus — agreement create/edit is essentials-first, More collapsed, Cancel demoted.
 */
test.describe('Bachus agreement form effortless flows', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('create essentials visible, More closed, axe clean; edit keeps More closed and Cancel collapsed', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    const companyName = `Ag Form Co ${suffix}`;
    await page.locator('#crm-company-name').fill(companyName);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    const companyUrl = page.url();
    createdCompanies.push(companyUrl);
    const companyId = companyUrl.match(/companies\/(\d+)/)?.[1];
    expect(companyId).toBeTruthy();

    await page.goto(`/apps/customercheck/agreements/new?companyId=${companyId}`);
    await page.waitForSelector('#crm-agreement-form', { timeout: 30000 });
    await expect(page.locator('#crm-ag-company')).toHaveValue(String(companyId));
    await expect(page.locator('#crm-agreement-advanced')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-ag-label')).toBeVisible();
    await expect(page.locator('#crm-ag-amount')).toBeVisible();
    await expect(page.locator('#crm-ag-until')).toBeVisible();
    await expect(page.locator('#crm-ag-kind')).toBeHidden();

    const label = `License ${suffix}`;
    await page.locator('#crm-ag-label').fill(label);
    const until = new Date();
    until.setFullYear(until.getFullYear() + 1);
    const untilIso = `${until.getFullYear()}-${String(until.getMonth() + 1).padStart(2, '0')}-${String(until.getDate()).padStart(2, '0')}`;
    // #crm-ag-until is a readonly datepicker display; write ISO on the hidden host input.
    const untilHost = page.locator('.crm-datepicker-host', { has: page.locator('#crm-ag-until') });
    await untilHost.locator('input[data-crm-date-iso]').evaluate((el, value) => {
      if (el instanceof HTMLInputElement) {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, untilIso);
    await page.locator('#crm-ag-amount').fill('1200.00');
    await page.locator('#crm-agreement-form button[type="submit"]').click();
    const err = page.locator('#crm-agreement-form-error');
    await Promise.race([
      page.waitForURL(new RegExp(`/apps/customercheck/companies/${companyId}`), { timeout: 30000 }),
      err.waitFor({ state: 'visible', timeout: 30000 }).then(async () => {
        throw new Error('Agreement save failed: ' + (await err.textContent()));
      }),
    ]);

    await page.goto(`/apps/customercheck/companies/${companyId}#crm-contracts`);
    await page.waitForSelector('#crm-contracts', { timeout: 30000 });
    await page.locator('#crm-contracts a[href*="/agreements/"][href$="/edit"]').filter({ hasText: label }).first().click();
    await page.waitForSelector('#crm-agreement-form[data-mode="edit"]', { timeout: 30000 });

    await expect(page.locator('#crm-agreement-advanced')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-ag-label')).toHaveValue(label);
    await expect(page.locator('#crm-ag-amount')).toBeVisible();
    await expect(page.locator('#crm-ag-kind')).toBeHidden();
    await expect(page.locator('.crm-ag-company-link')).toBeVisible();
    await expect(page.locator('#crm-agreement-renew')).toBeVisible();
    await expect(page.locator('#crm-agreement-end')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-agreement-cancel')).toBeAttached();
    await expect(page.locator('#crm-agreement-cancel')).toBeHidden();
    await expect(page.locator('#crm-agreement-form')).toHaveAttribute('data-terminal', '0');

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    // Edit save must not send limits (partner hoursQuota safety).
    /** @type {Record<string, unknown>|null} */
    let putBody = null;
    await page.route('**/apps/customercheck/api/v1/agreements/*', async (route) => {
      if (route.request().method() === 'PUT') {
        putBody = route.request().postDataJSON();
      }
      await route.continue();
    });
    await page.locator('#crm-ag-label').fill(label + ' v2');
    await page.locator('#crm-agreement-form button[type="submit"]').click();
    await page.waitForURL(new RegExp(`/apps/customercheck/companies/${companyId}`), { timeout: 30000 });
    expect(putBody).toBeTruthy();
    expect(putBody).not.toHaveProperty('limits');
    expect(putBody.label).toBe(label + ' v2');
  });
});
