// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Unassigned mail: same Inbox chrome as Personal/Team — list, under-row reader, Reply, Assign.
 */
test.describe('Bachus Inbox Unassigned mail simplicity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  async function openUnassigned(page) {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/review?kind=shared_mail');
    await page.waitForURL(/\/sales-inbox/, { timeout: 30000 });
    await expect(page.locator('#crm-sales-tab-unmatched')).toHaveAttribute('aria-selected', 'true', { timeout: 30000 });
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-root[data-crm-inbox-unified="1"]')).toBeVisible();
    await page.waitForFunction(() => {
      const status = document.getElementById('crm-sales-inbox-status');
      return status && !/Loading/i.test(status.textContent || '');
    }, null, { timeout: 30000 });
  }

  test('mobile Unassigned: search, list, under-row decide + Reply, axe clean', async ({ page }) => {
    await openUnassigned(page);

    await expect(page.locator('#crm-sales-inbox-disclosure')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-list-tools')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-q')).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="review"]')).toHaveCount(0);
    await expect(page.locator('#crm-sales-inbox-assign-panel')).toHaveCount(0);
    await expect(page.locator('#crm-sales-inbox-list')).toHaveAttribute('role', 'listbox');
    // Desktop column-sort chrome is attached but CSS-hidden on phone; assert presence, not visibility.
    const fromSort = page.locator('#crm-sales-inbox-cols [data-crm-inbox-sort="from"]');
    await expect(fromSort).toBeAttached();
    if (await fromSort.isVisible()) {
      await fromSort.click();
      await expect(fromSort).toHaveAttribute('aria-pressed', 'true');
    }

    const row = page.locator('#crm-sales-inbox-list .crm-mail-thread-row').first();
    if (await row.count()) {
      await row.click();
      await expect(page.locator('#crm-sales-inbox-reader')).toBeVisible();
      await expect(page.locator('#crm-sales-inbox-open-assign')).toBeVisible({ timeout: 20000 });
      await expect(page.locator('#crm-sales-inbox-reply')).toBeVisible();
      await page.locator('#crm-sales-inbox-open-assign').click();
      await expect(page.locator('#crm-sales-inbox-decide')).toBeVisible({ timeout: 20000 });
      const underRow = await page.evaluate(() => {
        const selected = document.querySelector('#crm-sales-inbox-list .crm-mail-thread-row.is-selected');
        const host = document.getElementById('crm-sales-inbox-reader-host');
        const reader = document.getElementById('crm-sales-inbox-reader');
        return !!(
          selected
          && host
          && reader
          && !reader.hidden
          && selected.nextElementSibling === host
          && host.contains(reader)
          && host.classList.contains('crm-sales-inbox__detail')
        );
      });
      expect(underRow).toBe(true);
      await row.click();
      await expect(page.locator('#crm-sales-inbox-list .crm-mail-thread-row.is-selected')).toHaveCount(0);
    } else {
      await expect(page.locator('#crm-sales-inbox-empty')).toBeVisible();
    }

    const results = await new AxeBuilder({ page })
      .include('#crm-sales-inbox-root')
      .disableRules(['color-contrast'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });

  test('legacy /review redirects into unified Unassigned', async ({ page }) => {
    await page.setViewportSize({ width: 1100, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/review?kind=shared_mail');
    await page.waitForURL(/\/sales-inbox/, { timeout: 30000 });
    await expect(page).toHaveURL(/tab=unmatched/);
    await expect(page).toHaveURL(/kind=mail/);
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible();
    await expect(page.locator('#crm-sales-tab-unmatched')).toHaveAttribute('aria-selected', 'true');
  });

  test('desktop Unassigned keeps one chrome language', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/sales-inbox?tab=unmatched&kind=mail');
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-sales-inbox-list-tools')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-q')).toBeVisible();
    await expect(page.locator('#crm-sales-compose-host')).toBeAttached();
    await expect(page.locator('#crm-sales-inbox-decide')).toBeAttached();
    await expect(page.locator('#crm-sales-inbox-open-assign')).toBeAttached();
    await expect(page.locator('#crm-sales-inbox-reply')).toHaveText(/Reply|Antworten/i);
  });
});
