// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Bachus radical simplify — Review / Conflicts / Settings / agreement create journeys.
 */
test.describe('Bachus extreme simplicity journeys', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('Unassigned: unified Inbox chrome, search, list/reader decide zone', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/sales-inbox?tab=unmatched');
    await expect(page.locator('#crm-sales-inbox-root[data-crm-inbox-unified="1"]')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-sales-tab-unmatched')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="review"]')).toHaveCount(0);

    await expect(page.locator('#crm-sales-inbox-search-wrap')).toHaveCount(0);
    await expect(page.locator('#crm-sales-inbox-disclosure')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-list-tools')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-q')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-mail-filters')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-empty, #crm-sales-inbox-list .crm-mail-thread-row').first()).toBeVisible({ timeout: 20000 });
    if (await page.locator('#crm-sales-inbox-list .crm-mail-thread-row').count()) {
      await expect(page.locator('#crm-sales-inbox-cols')).toBeVisible();
      await expect(page.locator('[data-crm-inbox-sort="sent"]')).toHaveAttribute('aria-pressed', 'true');
    }
    await expect(page.locator('#crm-sales-inbox-list')).toBeVisible();

    const reviewRow = page.locator('#crm-sales-inbox-list .crm-mail-thread-row').first();
    if (await reviewRow.count()) {
      await reviewRow.click();
      await expect(page.locator('#crm-sales-inbox-reader')).toBeVisible();
      await expect(page.locator('#crm-sales-inbox-open-assign')).toBeVisible({ timeout: 20000 });
      await expect(page.locator('#crm-sales-inbox-reply')).toBeVisible();
      await page.locator('#crm-sales-inbox-open-assign').click();
      await expect(page.locator('#crm-sales-inbox-decide')).toBeVisible({ timeout: 20000 });
      await expect(page.locator('#crm-sales-inbox-assign-form, #crm-sales-inbox-decide-ro').first()).toBeVisible();
    }

    const results = await new AxeBuilder({ page })
      .include('#crm-sales-inbox-root')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('Conflicts: human lead, empty CTA or resolve chrome, axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/conflicts');
    await page.waitForSelector('[data-crm-persona-mount="conflicts"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="conflicts"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });

    await expect(page.locator('#crm-conflicts-lead')).toBeVisible();
    // Literal jargon bans — fixed technical terms, locale-independent.
    for (const jargon of ['three-way compare', 'last-write-wins']) {
      await expect(page.getByText(jargon)).toHaveCount(0);
    }

    const empty = page.locator('.crm-empty-state');
    const card = page.locator('[data-crm-conflict-id]').first();
    if (await empty.count()) {
      await expect(empty.locator('.crm-empty-state__actions a.crm-btn').first()).toBeVisible();
    } else {
      await expect(card).toBeVisible();
      // "Keep what's saved" radio is pre-selected by design; the manual choice
      // reveals the free-text input (structural labels — see persona.js).
      await expect(card.locator('label.crm-switch:has(input[value="keep_current"])').first()).toBeVisible();
      await expect(card.locator('input[value="keep_current"]').first()).toBeChecked();
      const manual = card.locator('.crm-conflict-manual').first();
      await expect(manual).toBeHidden();
      await card.locator('label.crm-switch:has(input[value="manual"])').first().click();
      await expect(manual).toBeVisible();
    }

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('Settings Review sources card + agreement create More collapse', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/settings/review-sources');
    await page.waitForSelector('#crm-review-sources', { timeout: 30000 });
    await expect(page.locator('#crm-review-sources .crm-card__title')).toBeVisible();
    await expect(page.locator('#crm-review-sources-form')).toBeVisible();
    await expect(page.locator('#crm-ws-status')).toBeVisible();
    await expect(page.locator('#crm-ws-error')).toBeAttached();
    await expect(page.locator('#crm-mailbox-form #crm-review-sources')).toHaveCount(0);
    await expect(page.locator('#crm-review-file-folder-pick')).toBeVisible();
    await expect(page.locator('#crm-review-file-folder')).toHaveAttribute('type', 'hidden');
    await expect(page.locator('input#crm-review-file-folder[type="number"]')).toHaveCount(0);
    await expect(page.locator('#crm-review-file-folder-help')).toBeVisible();
    await expect(page.locator('#crm-review-file-folder-help')).toContainText(/Never type a raw folder file id|rohe Ordner-Datei-ID/i);
    await expect(page.locator('#crm-review-file-folder-status')).toBeVisible();
    await expect(page.locator('#crm-review-sources-form')).toHaveAttribute('aria-busy', 'false', { timeout: 30000 });
    await expect(page.locator('#crm-review-sources-save')).toBeEnabled({ timeout: 30000 });
    const hasPicker = await page.evaluate(() => !!(window.CustomerCheckFolderPicker && typeof window.CustomerCheckFolderPicker.pickFolder === 'function'));
    expect(hasPicker).toBeTruthy();

    await page.goto('/apps/customercheck/agreements/new');
    await page.waitForSelector('#crm-agreement-form', { timeout: 30000 });
    await expect(page.locator('#crm-agreement-advanced')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-ag-label')).toBeVisible();
    await expect(page.locator('#crm-ag-amount')).toBeVisible();
    await expect(page.locator('#crm-ag-until')).toBeVisible();
    await expect(page.locator('#crm-ag-kind')).toBeAttached();
    await expect(page.locator('.crm-ag-essentials')).toBeVisible();

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('Company 360 emails log is collapsed; single Log talk remains', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Bachus Co ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    await expect(page.locator('#crm-log-email')).toHaveCount(1);
    await expect(page.locator('#crm-log-email')).toContainText(/Log talk|Gespräch|Protokollieren|Gespräch protokollieren/i);
    await expect(page.locator('#crm-identity')).toBeVisible();
    await expect(page.locator('#crm-identity-title')).toBeVisible();
    await expect(page.locator('#crm-identity-title')).toContainText(`Bachus Co ${suffix}`);
    await expect(page.locator('#crm-glance-summary .crm-stat-tile')).toHaveCount(4, { timeout: 20000 });
    // Emails roster lives under More (focus tabs) — open it before asserting.
    await page.locator('[data-crm-focus-tab="more"]').click();
    const emails = page.locator('#crm-emails');
    await expect(emails).toBeVisible({ timeout: 15000 });
    const shell = emails.locator(':scope > details');
    await expect(shell).toHaveCount(1);
    await expect(shell).not.toHaveAttribute('open', '');
  });
});
