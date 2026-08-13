// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Bachus Review mail triage: read before assign, one-click suggestion, no chrome pile-up.
 */
test.describe('Bachus Review mail extreme simplicity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  async function openReviewReady(page) {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/review?kind=shared_mail');
    await page.waitForSelector('[data-crm-persona-mount="review"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="review"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });
  }

  test('mobile Review: visible search toolbar, preview reachable, axe clean', async ({ page }) => {
    await openReviewReady(page);

    await expect(page.locator('#crm-review-disclosure')).toHaveCount(1);
    await expect(page.locator('[data-crm-persona-mount="review"] .crm-quick-filters button')).toHaveCount(7);

    await expect(page.locator('details.crm-review-tools')).toHaveCount(0);
    await expect(page.locator('.crm-review-toolbar').first()).toBeVisible();
    await expect(page.locator('#crm-review-q')).toBeVisible();
    await expect(page.locator('#crm-review-include-archived')).toBeAttached();
    await page.locator('.crm-review-toolbar__extra > summary').first().click();
    await expect(page.locator('#crm-review-include-archived')).toBeVisible();
    await expect(page.locator('.crm-review-board').first()).toBeVisible();
    await expect(page.locator('.crm-mail-thread.crm-review-list, .crm-review-table').first()).toBeVisible();
    await expect(page.locator('[data-crm-review-sort]').first()).toBeVisible();
    await expect(page.locator('[data-crm-review-sort="status"]')).toHaveCount(0);
    const reviewRow = page.locator('li.crm-review-row').first();
    if (await reviewRow.count()) {
      await reviewRow.click();
      await expect(page.locator('[data-crm-review-reader]')).toBeVisible();
      await expect(page.locator('[data-crm-review-reader] .crm-mail-reader__subject, [data-crm-review-reader] .crm-review-zone__label').first()).toBeVisible();
    }

    const assignRow = page.locator('[data-crm-review-reader] form.crm-review-assign').first();
    const empty = page.locator('.crm-empty-state');
    if (await assignRow.count()) {
      await expect(page.locator('li.crm-review-row, tr.crm-review-row').first()).toBeVisible();
      await expect(assignRow.locator('.crm-review-zone--decide, .crm-review-primary').first()).toBeVisible();
      const previewWrap = page.locator('[data-crm-mail-preview][data-crm-mail-auto-open]').first();
      if (await previewWrap.count()) {
        const preview = previewWrap.locator('[data-crm-mail-preview-toggle]');
        await expect(preview).toBeVisible();
        await expect(preview).toHaveCSS('min-height', /4[4-9]px|5\dpx/);
        // Opening a row auto-loads the message — no second click.
        await expect(preview).toHaveAttribute('aria-expanded', 'true', { timeout: 20000 });
        const controls = await preview.getAttribute('aria-controls');
        expect(controls).toBeTruthy();
        await expect(page.locator('#' + controls)).toBeVisible();
        await expect(page.locator('.crm-mail-preview__hint')).toHaveCount(0);
        const answer = previewWrap.locator('[data-crm-mail-reply]').first();
        await expect(answer).toBeVisible();
        await expect(answer).toHaveCSS('min-height', /4[4-9]px|5\dpx/);
      }
      await expect(assignRow.locator('[data-crm-review-none]')).toBeVisible();
      await expect(assignRow.locator('[data-crm-review-discard]')).toBeVisible();
      const quick = assignRow.locator('[data-crm-review-quick-assign]');
      if (await quick.count()) {
        await expect(quick).toBeVisible();
        const box = await quick.boundingBox();
        expect(box && box.height >= 44).toBeTruthy();
      } else {
        const pick = assignRow.locator('.crm-review-pick-company');
        await expect(pick).toBeVisible();
        await expect(assignRow.locator('details.crm-review-pick-company')).toHaveCount(0);
        const primary = assignRow.locator('button[type="submit"].crm-btn--primary').first();
        await expect(primary).toBeVisible();
        const box = await primary.boundingBox();
        expect(box && box.height >= 44).toBeTruthy();
      }
    } else {
      await expect(empty.first()).toBeVisible();
      await expect(empty.locator('a[href*="settings"]')).toBeVisible();
    }

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('desktop Review: search stays visible, bulk bar stays quiet until selection', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/review');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('[data-crm-persona-mount="review"]', { timeout: 45000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="review"]');
      return mount && mount.getAttribute('aria-busy') !== 'true' && mount.children.length > 0;
    }, null, { timeout: 45000 });

    await expect(page.locator('.crm-review-toolbar').first()).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#crm-review-q')).toBeVisible();
    await page.locator('#crm-review-q').fill('zzz-no-such-mail-subject-xyz');
    await page.locator('form.crm-review-search button[type="submit"]').click();
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="review"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 45000 });
    // After search, toolbar + query remain visible (no progressive dump / dead end).
    await expect(page.locator('.crm-review-toolbar').first()).toBeVisible();
    await expect(page.locator('#crm-review-q')).toHaveValue('zzz-no-such-mail-subject-xyz');

    const bulk = page.locator('[data-crm-review-bulk]');
    if (await bulk.count()) {
      await expect(bulk).toBeHidden();
      const select = page.locator('li.crm-review-row:not([hidden]) [data-crm-review-select]').first();
      if (await select.count()) {
        await select.evaluate((el) => {
          if (!(el instanceof HTMLInputElement)) return;
          el.checked = true;
          el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await expect(bulk).toBeVisible();
        await expect(page.locator('[data-crm-review-bulk-remove]')).toBeEnabled();
      }
    }
  });
});
