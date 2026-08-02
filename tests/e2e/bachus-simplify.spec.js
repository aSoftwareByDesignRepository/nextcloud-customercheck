// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Bachus radical simplify — Review / Conflicts / Settings / agreement create journeys.
 */
test.describe('Bachus extreme simplicity journeys', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Review: single disclosure, kind chips, progressive More options', async ({ page }) => {
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

    // Kind chip filters without dead end.
    await chips.filter({ hasText: /Shared mail|Freigegebene|Shared/i }).first().click();
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="review"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });
    await expect(page.locator('#crm-review-disclosure')).toBeVisible();

    const assignRow = page.locator('form.crm-review-assign').first();
    if (await assignRow.count()) {
      await expect(assignRow.locator('.crm-review-primary')).toBeVisible();
      await expect(assignRow.locator('button[type="submit"]')).toContainText(/Assign|Zuordnen/i);
      await expect(assignRow.locator('[data-crm-review-none]')).toBeVisible();
      const more = assignRow.locator('details.crm-review-more, details.crm-advanced').first();
      await expect(more).toBeVisible();
      await expect(more).not.toHaveAttribute('open', '');
      await more.locator('summary').click();
      await expect(assignRow.locator('[data-crm-review-snooze="7d"]')).toBeVisible();
    } else {
      await expect(page.locator('.crm-empty-state a[href*="settings"]')).toBeVisible();
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
    await page.waitForSelector('#crm-review-sources', { timeout: 30000 });
    await expect(page.locator('#crm-review-sources .crm-card__title')).toBeVisible();
    await expect(page.locator('#crm-review-sources-form')).toBeVisible();
    await expect(page.locator('#crm-mailbox-form #crm-review-sources')).toHaveCount(0);

    await page.goto('/apps/customercheck/agreements/new');
    await page.waitForSelector('#crm-agreement-form', { timeout: 30000 });
    await expect(page.locator('#crm-agreement-advanced')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-ag-label')).toBeVisible();
    await expect(page.locator('#crm-ag-amount')).toBeAttached();

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('Company 360 emails log is collapsed; single Log email remains', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Bachus Co ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await expect(page.locator('#crm-log-email')).toHaveCount(1);
    const emails = page.locator('#crm-emails');
    await expect(emails).toBeVisible();
    const shell = emails.locator(':scope > details');
    await expect(shell).toHaveCount(1);
    await expect(shell).not.toHaveAttribute('open', '');
  });
});
