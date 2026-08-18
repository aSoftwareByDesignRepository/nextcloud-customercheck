// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv, gotoCrm, dismissOrFailOnUpdateNeeded } from './helpers/auth.js';
import { openFilterPanel } from './helpers/new-deal-form.js';

/**
 * Keyboard-friendly smoke for core journeys (PSD §8 / S10).
 */
test.describe('CustomerCheck journeys smoke', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('create company form validates slug and lands on 360', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies/new');
    await expect(page.locator('.crm-page-stack').first()).toBeVisible();

    const name = page.locator('#crm-company-name');
    const slug = page.locator('#crm-company-slug');
    await expect(name).toBeVisible();

    const suffix = Date.now().toString(36);
    await name.fill(`Journey Co ${suffix}`);
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await slug.fill(`journey-co-${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) {
        form.requestSubmit();
      }
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    await dismissOrFailOnUpdateNeeded(page);
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('#crm-main-content')).toBeVisible();
    await expect(page.locator('.crm-page-stack')).toBeVisible();
    await expect(page.locator('[data-crm-focus-tab="people"]')).toBeVisible();
    await page.locator('[data-crm-focus-tab="deals"]').click();
    await expect(page.locator('#crm-contracts')).toBeVisible();
    await page.locator('[data-crm-focus-tab="more"]').click();
    await expect(page.locator('#crm-emails')).toBeVisible();
    await page.locator('[data-crm-focus-tab="money"]').click();
    // Progressive disclosure: empty linked apps stay in DOM under the bundle
    await expect(page.locator('#crm-invoices')).toHaveCount(1);
    await expect(page.locator('#crm-projects')).toHaveCount(1);
    await expect(page.locator('#crm-linked-bundle')).toBeVisible();
    await page.locator('#crm-linked-bundle > summary').click();
    await expect(page.locator('#crm-linked-bundle .crm-linked-status__row')).toHaveCount(3);
    await expect(page.locator('#crm-linked-bundle .crm-card--nested')).toHaveCount(0);
  });

  test('pipeline exposes table/board toggle and filter panel', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/pipeline');
    await expect(page.locator('.crm-page-stack')).toBeVisible();

    await expect(page.locator('#crm-pipeline-toolbar')).toBeVisible({ timeout: 10000 });
    const tableBtn = page.locator('#crm-pipeline-toolbar [data-crm-pipeline-view="table"]').first();
    const boardBtn = page.locator('#crm-pipeline-toolbar [data-crm-pipeline-view="board"]').first();
    await expect(tableBtn).toBeVisible({ timeout: 10000 });
    await expect(boardBtn).toBeVisible({ timeout: 10000 });

    await openFilterPanel(page, 'crm-pipeline-filter-toggle', 'crm-pipeline-filter', 'crm-pipeline-controls--filter-open');
    await expect(page.locator('#crm-pipeline-controls')).toHaveClass(/crm-pipeline-controls--filter-open/);
    await expect(page.locator('#crm-pipeline-new-deal')).toBeVisible();

    await boardBtn.click();
    await expect(page.locator('#crm-pipeline-board')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#crm-pipeline-table')).toBeHidden();
    await expect(boardBtn).toHaveAttribute('aria-pressed', 'true');
    await expect(tableBtn).toHaveAttribute('aria-pressed', 'false');
  });

  test('app settings documents ICS and lead webhook for app admins', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/app-settings');
    await page.waitForURL(/\/app-settings\/access/, { timeout: 30000 });
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('#section-access')).toBeVisible();
    await expect(page.locator('#crm-settings-access-form')).toBeVisible();

    await page.goto('/apps/customercheck/app-settings/tokens');
    await page.waitForSelector('#section-tokens', { timeout: 30000 });
    await expect(page.locator('#crm-ics-url')).toBeVisible();
    await expect(page.locator('#crm-lead-url')).toBeVisible();
    const ics = await page.locator('#crm-ics-url').inputValue();
    expect(ics).toMatch(/\/feed\/.+\/renewals\.ics$/);
  });

  test('company 360 exposes GDPR export and tickets jump link', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies/new');
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Export Co ${suffix}`);
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await page.locator('#crm-company-slug').fill(`export-co-${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    await dismissOrFailOnUpdateNeeded(page);
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('#crm-company-gdpr-export')).toBeAttached({ timeout: 10000 });
    // Relocated into the page header actions strip (must not stay in the hidden source).
    await expect(page.locator('#crm-page-actions #crm-company-gdpr-export')).toBeVisible({ timeout: 10000 });
    const focus = page.locator('[data-crm-360-focus="1"]');
    await expect(focus).toBeVisible();
    await expect(focus.locator('[data-crm-focus-tab]')).toHaveCount(5);
    await focus.locator('[data-crm-focus-tab="money"]').click();
    await expect(page.locator('#crm-tickets')).toHaveCount(1);
  });

  test('companies filter panel supports archived filter', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies');
    await expect(page.locator('#crm-companies-toolbar')).toBeVisible();
    await expect(page.locator('.crm-quick-filters')).toBeVisible();
    await page.locator('.crm-quick-filter[href*="archived=1"]').click();
    await page.waitForURL(/archived=1/, { timeout: 15000 });
    // Active archive filter opens the panel automatically — assert field, do not toggle closed.
    await expect(page.locator('#crm-companies-filter.crm-filter-panel')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#crm-filter-archived')).toBeVisible();
    await expect(page.locator('#crm-filter-archived')).toHaveValue('1');
  });

  test('pipeline new deal opens company picker dialog', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/pipeline');
    await page.locator('#crm-pipeline-new-deal').click({ force: true });
    const dialog = page.locator('dialog.crm-native-dialog[open]').first();
    await expect(dialog).toBeVisible({ timeout: 10000 });
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect(dialog).toHaveClass(/modal--md/);
    await expect(dialog).toHaveClass(/crm-dialog--content/);
    await expect(dialog).toHaveClass(/crm-dialog--new-deal/);
    await expect(dialog.locator('form.crm-form--stack')).toBeVisible();
    await expect(dialog.locator('.crm-deal-company-picker')).toBeVisible({ timeout: 10000 });
    await expect(dialog.locator('.crm-company-list, .crm-company-choice:not([hidden])').first()).toBeVisible();
    await expect(dialog.locator('input[name="companyId"][type="hidden"]')).toBeAttached();
    await expect(dialog.locator('select[name="companyId"]')).toHaveCount(0);
    await expect(dialog.getByText('Showing %s companies')).toHaveCount(0);
    await expect(dialog.locator('form.crm-form--new-deal')).toBeVisible();
    await expect(dialog.locator('input[name="title"]')).toBeVisible();
    await expect(dialog.locator('select[name="personId"]')).toBeAttached();
    await expect(dialog.locator('select[name="leadSource"]')).toBeVisible();
    await expect(dialog.locator('select[name="forecastBucket"] option[value=""]')).toHaveCount(1);
    await expect(dialog.locator('select[name="forecastBucket"] option[value="certain"]')).toHaveCount(1);
    await expect(dialog.locator('.crm-form-section__legend').first()).toBeVisible();
    await expect(dialog.locator('.modal-close, .crm-btn--secondary[data-cancel]').first()).toBeVisible();
    // Confirm helper also ships aria-modal (smoke the Confirm path via cancel then reopen not required).
    const hasPresent = await page.evaluate(() => !!(window.CustomerCheckDialogPresent && typeof window.CustomerCheckDialogPresent.safeExternalHref === 'function'));
    expect(hasPresent).toBeTruthy();
    const hasPicker = await page.evaluate(() => !!(window.CustomerCheckCompanyPicker && typeof window.CustomerCheckCompanyPicker.mountListPanel === 'function'));
    expect(hasPicker).toBeTruthy();

    // Responsive: phone viewport must still fit the dialog without horizontal overflow.
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(dialog).toBeVisible();
    const box = await dialog.boundingBox();
    expect(box).toBeTruthy();
    expect(box.width).toBeLessThanOrEqual(390);
  });

  test('agreements money activities expose AZC filter panels', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await gotoCrm(page, '/apps/customercheck/agreements');
    await expect(page.locator('#crm-agreements-toolbar')).toBeVisible();
    await openFilterPanel(page, 'crm-agreements-filter-toggle', 'crm-agreements-filter', 'crm-list-controls--filter-open');
    await expect(page.locator('#crm-agreements-controls')).toHaveClass(/crm-list-controls--filter-open/);
    await expect(page.locator('#crm-filter-kind')).toBeVisible();

    await gotoCrm(page, '/apps/customercheck/money');
    await expect(page.locator('#crm-money-toolbar')).toBeVisible();
    await openFilterPanel(page, 'crm-money-filter-toggle', 'crm-money-filter', 'crm-list-controls--filter-open');
    await expect(page.locator('#crm-money-controls')).toHaveClass(/crm-list-controls--filter-open/);

    await gotoCrm(page, '/apps/customercheck/activities');
    await expect(page.locator('#crm-activities-toolbar')).toBeVisible();
    await openFilterPanel(page, 'crm-activities-filter-toggle', 'crm-activities-filter', 'crm-list-controls--filter-open');
    await expect(page.locator('#crm-activities-controls')).toHaveClass(/crm-list-controls--filter-open/);
    await expect(page.locator('#crm-filter-scope')).toBeVisible();
  });

  test('company form exposes sibling link fields', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies/new');
    await expect(page.locator('#crm-company-name')).toBeVisible();
    await expect(page.locator('#crm-company-advanced')).toBeVisible();
    // Slug + sibling links live under Advanced (collapsed on create)
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await expect(page.locator('#crm-company-slug')).toBeVisible();
    await expect(page.locator('#crm-company-pc')).toBeVisible();
    await expect(page.locator('#crm-company-tc')).toBeVisible();
  });
});
