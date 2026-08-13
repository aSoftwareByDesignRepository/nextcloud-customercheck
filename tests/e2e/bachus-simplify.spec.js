// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Bachus radical simplify — Review / Conflicts / Settings / agreement create journeys.
 */
test.describe('Bachus extreme simplicity journeys', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Review: single disclosure, kind chips, visible search toolbar', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/review');
    await page.waitForSelector('[data-crm-persona-mount="review"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="review"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });

    await expect(page.locator('#crm-review-disclosure')).toHaveCount(1);
    await expect(page.locator('#crm-review-board-title')).toHaveCount(0);
    const chips = page.locator('[data-crm-persona-mount="review"] .crm-quick-filters button');
    await expect(chips.first()).toBeVisible();
    await expect(chips).toHaveCount(7);

    // Search toolbar + Thunderbird list/reader (not a card dump of actions).
    const toolbar = page.locator('[data-crm-persona-mount="review"] .crm-review-toolbar').first();
    await expect(toolbar).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="review"] details.crm-review-tools')).toHaveCount(0);
    await expect(page.locator('#crm-review-q')).toBeVisible();
    await expect(page.locator('#crm-review-include-archived')).toBeAttached();
    await page.locator('[data-crm-persona-mount="review"] .crm-review-toolbar__extra > summary').click();
    await expect(page.locator('#crm-review-include-archived')).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="review"] .crm-review-board').first()).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="review"] .crm-mail-thread, [data-crm-persona-mount="review"] .crm-review-table').first()).toBeVisible();
    await expect(page.locator('[data-crm-review-sort]').first()).toBeVisible();
    await expect(page.locator('[data-crm-review-sort="status"]')).toHaveCount(0);
    const reviewRow = page.locator('[data-crm-persona-mount="review"] li.crm-review-row').first();
    if (await reviewRow.count()) {
      await reviewRow.click();
      await expect(page.locator('[data-crm-review-reader] .crm-mail-reader__subject, [data-crm-review-reader] .crm-review-zone__label').first()).toBeVisible();
      const dealMore = page.locator('[data-crm-review-reader] details.crm-review-deal-more').first();
      const dealField = page.locator('[data-crm-review-reader] .crm-review-deal-field').first();
      if (await dealMore.count()) {
        await dealMore.locator('summary').click();
        await expect(dealField).toBeVisible();
        const later = page.locator('[data-crm-review-reader] details.crm-review-more').first();
        if (await later.count()) {
          await expect(later.locator('[data-crm-review-deal]')).toHaveCount(0);
        }
      } else if (await dealField.count()) {
        await expect(dealField).toBeVisible();
      }
    }

    // Kind chip filters without dead end (EN or DE labels).
    const sharedChip = chips.filter({ hasText: /Shared mail|Gemeinsame|Freigegebene|Shared/i }).first();
    if (await sharedChip.count()) {
      await sharedChip.click();
      await page.waitForFunction(() => {
        const mount = document.querySelector('[data-crm-persona-mount="review"]');
        return mount && mount.getAttribute('aria-busy') !== 'true';
      }, null, { timeout: 30000 });
      await expect(page.locator('#crm-review-disclosure')).toBeVisible();
    } else {
      await chips.first().click();
      await page.waitForFunction(() => {
        const mount = document.querySelector('[data-crm-persona-mount="review"]');
        return mount && mount.getAttribute('aria-busy') !== 'true';
      }, null, { timeout: 30000 });
    }

    const assignRow = page.locator('[data-crm-review-reader] form.crm-review-assign').first();
    if (await assignRow.count()) {
      await expect(assignRow.locator('.crm-review-primary')).toBeVisible();
      await expect(page.locator('li.crm-review-row, tr.crm-review-row').first()).toBeVisible();
      const autoPreview = page.locator('[data-crm-mail-preview][data-crm-mail-auto-open] [data-crm-mail-preview-toggle]').first();
      if (await autoPreview.count()) {
        await expect(autoPreview).toHaveAttribute('aria-expanded', 'true', { timeout: 20000 });
      }
      const quick = assignRow.locator('[data-crm-review-quick-assign]');
      if (await quick.count()) {
        await expect(quick).toBeVisible();
        await expect(quick).toContainText(/Assign to|Zuordnen zu/i);
        await expect(assignRow.locator('details.crm-review-pick-other')).not.toHaveAttribute('open', '');
      } else {
        const pick = assignRow.locator('.crm-review-pick-company');
        await expect(pick).toBeVisible();
        await expect(assignRow.locator('details.crm-review-pick-company')).toHaveCount(0);
        await expect(assignRow.locator('button[type="submit"]')).toContainText(/Assign|Zuordnen/i);
      }
      await expect(assignRow.locator('[data-crm-review-none]')).toBeVisible();
      await expect(assignRow.locator('[data-crm-review-discard]')).toBeVisible();
      const more = assignRow.locator('details.crm-review-more').first();
      await expect(more).toBeVisible();
      await expect(more).not.toHaveAttribute('open', '');
      await more.locator('summary').click();
      await expect(assignRow.locator('[data-crm-review-snooze="7d"]')).toBeVisible();
      await expect(assignRow.locator('[data-crm-review-followup]')).toHaveCount(0);
    } else {
      await expect(page.locator('.crm-empty-state a[href*="settings"]')).toBeVisible();
    }

    const bulk = page.locator('[data-crm-review-bulk]');
    if (await bulk.count()) {
      await expect(bulk).toBeHidden();
    }

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
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
    await expect(page.getByText(/three-way compare|last-write-wins/i)).toHaveCount(0);

    const empty = page.locator('.crm-empty-state');
    const card = page.locator('[data-crm-conflict-id]').first();
    if (await empty.count()) {
      await expect(empty.getByRole('link', { name: /Today|Heute/i })).toBeVisible();
    } else {
      await expect(card).toBeVisible();
      await expect(card.getByText(/Keep what’s saved|Behalte|Keep what/i).first()).toBeVisible();
      const manual = card.locator('.crm-conflict-manual').first();
      await expect(manual).toBeHidden();
      await card.getByText(/Type a different|Anderen Wert|different value/i).first().click();
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

    await page.goto('/apps/customercheck/settings#crm-review-sources');
    await page.waitForURL(/\/settings\/review-sources/, { timeout: 30000 });
    await page.waitForSelector('#crm-review-sources', { timeout: 30000 });
    await expect(page.locator('#crm-review-sources .crm-card__title')).toBeVisible();
    await expect(page.locator('#crm-review-sources-form')).toBeVisible();
    await expect(page.locator('#crm-ws-status')).toBeVisible();
    await expect(page.locator('#crm-ws-error')).toBeAttached();
    await expect(page.locator('#crm-mailbox-form #crm-review-sources')).toHaveCount(0);
    await expect(page.locator('#crm-review-file-folder-pick')).toBeVisible();
    await expect(page.locator('#crm-review-file-folder')).toHaveAttribute('type', 'hidden');
    await expect(page.locator('input#crm-review-file-folder[type="number"]')).toHaveCount(0);
    await expect(page.getByText(/Never type a raw folder file id|Nie eine rohe Ordner-Datei-ID/i)).toBeVisible();
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

    await expect(page.locator('#crm-log-email')).toHaveCount(1);
    await expect(page.locator('#crm-log-email')).toContainText(/Log talk|Gespräch|Protokollieren|Gespräch protokollieren/i);
    await expect(page.locator('#crm-identity')).toBeVisible();
    await expect(page.locator('#crm-identity h2')).toHaveCount(0);
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
