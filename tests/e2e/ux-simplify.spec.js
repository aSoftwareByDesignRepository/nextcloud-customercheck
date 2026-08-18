// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

test.describe('Simplified core UX journeys', () => {
  test('today first-run, companies list, one-click done, simple log email', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    // Either first-run CTAs, calm empty, or suite attention tables — never a dead end.
    const firstRun = page.locator('.crm-empty-state__actions a', { hasText: /Add company/i });
    const calm = page.locator('.crm-callout--success');
    const table = page.locator('.crm-command-section table.table, .crm-today-focus-line, #crm-today-role-title');
    await expect(firstRun.or(calm).or(table).first()).toBeVisible({ timeout: 15000 });

    await page.goto('/apps/customercheck/companies');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('table thead th', { hasText: /^Name$/i })).toBeVisible();
    await expect(page.locator('table thead th', { hasText: /^Slug$/i })).toHaveCount(0);

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Simple Co ${suffix}`);
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await page.locator('#crm-company-slug').fill(`simple-co-${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    // Edit primary; Export visible; Erase tucked under More.
    await expect(page.locator('#crm-page-actions a.crm-btn--primary, .header-actions a.crm-btn--primary').first()).toContainText(/Edit|Bearbeiten/i);
    await expect(page.locator('#crm-company-gdpr-export')).toBeVisible();
    const more = page.locator('#crm-page-actions details.crm-more-menu, .crm-page-actions-source details.crm-more-menu').first();
    await expect(more).toBeVisible();
    await more.locator('summary').click();
    await expect(page.locator('#crm-company-gdpr-erase')).toBeVisible();

    // Focus tabs: one panel at a time (Pipedrive-style).
    const focus = page.locator('[data-crm-360-focus="1"]');
    await expect(focus.locator('[data-crm-focus-tab]')).toHaveCount(5);
    await expect(focus.locator('[data-crm-focus-tab="today"][aria-selected="true"]')).toBeVisible();
    await expect(focus.locator('[data-crm-focus-tab="people"]')).toBeVisible();

    // New license keeps company context (EN or DE label) — lives on Deals tab.
    await focus.locator('[data-crm-focus-tab="deals"]').click();
    const newAg = page.locator('a', { hasText: /New license|Neue Lizenz/i }).first();
    await expect(newAg).toHaveAttribute('href', /companyId=\d+/);
    await focus.locator('[data-crm-focus-tab="today"]').click();

    // Add person via the always-visible People header (coach also offers it).
    await page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-person"]').click();
    const personDialog = page.locator('#crm-person-dialog');
    await expect(personDialog).toBeVisible({ timeout: 10000 });
    await expect(personDialog).toHaveJSProperty('open', true);
    let form = page.locator('#crm-person-form');
    await expect(form).toBeVisible();
    await form.locator('#crm-person-name').fill('Ada');
    await form.locator('#crm-person-email').fill(`ada-${suffix}@example.com`);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await page.locator('[data-crm-focus-tab="today"]').click();
    await page.locator('#crm-log-email').click();
    form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    // Single contact → person picker is auto-filled and omitted (Bachus: fewer fields).
    const personField = form.locator('[name="personId"]');
    if (await personField.count()) {
      await expect(personField).toBeVisible();
    }
    await expect(form.locator('[name="subject"]')).toBeVisible();
    await expect(form.locator('[name="fromAddr"]')).toHaveCount(0);
    await form.locator('[name="subject"]').fill('Hello Ada');
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await page.locator('[data-crm-focus-tab="more"]').click();
    await expect(page.locator('#crm-emails table tbody tr').first()).toContainText('Hello Ada');
    await page.locator('[data-crm-focus-tab="today"]').click();

    // Add reminder + one-click Done (type under More; no confirm dialog on Done).
    await page.locator('#crm-add-activity').click();
    form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await form.locator('[name="title"]').fill('Call Ada');
    await form.locator('details.crm-advanced summary').click();
    await form.locator('[name="type"]').selectOption('call');
    const today = new Date().toISOString().slice(0, 10);
    await form.locator('[name="dueDate"]').fill(today);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const doneBtn = page.locator('[data-crm-complete-activity]').first();
    await expect(doneBtn).toBeVisible();

    await page.waitForSelector('#crm-main-content', { timeout: 15000 });
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    await doneBtn.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-activities')).toBeVisible();
  });

  test('pipeline is lean: Edit deal, no chase jargon, no webhook empty copy', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/pipeline');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    // Prefer table view for lean-column assertions (board may be the user default).
    const tableToggle = page.locator('[data-crm-pipeline-view="table"]');
    if (await tableToggle.count()) {
      await tableToggle.click();
    }
    await expect(page.locator('#crm-pipeline-table:not([hidden]), #crm-pipeline-board:not([hidden])').first()).toBeVisible({ timeout: 15000 });
    await expect(page.getByText(/Edit chase|Chase bearbeiten/i)).toHaveCount(0);
    const editDeal = page.getByRole('button', { name: /Edit deal|Deal bearbeiten/i });
    const empty = page.getByText(/No deals yet\. Tap New deal|Noch keine Deals\. Tippe auf Neuer Deal/i);
    await expect(editDeal.or(empty).first()).toBeVisible();
    await expect(page.getByText(/website lead webhook/i)).toHaveCount(0);
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-main-content')).toBeVisible();
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});
