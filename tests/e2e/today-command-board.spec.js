// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';

/**
 * Today Command Board — suite tables, role dashboard, jump chips, a11y.
 */
test.describe('Today command board UX', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('renders role dashboard, jump chips, and suite tables', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/today');

    const firstRun = page.locator('.crm-empty-state__actions a', { hasText: /Add company/i });
    const calm = page.locator('.crm-callout--success');
    const roleDash = page.locator('.crm-today-role');
    const table = page.locator('.crm-command-section table.table.table--hover').first();

    await expect(firstRun.or(calm).or(roleDash).first()).toBeVisible({ timeout: 15000 });

    if (await roleDash.isVisible().catch(() => false)) {
      await expect(page.locator('#crm-today-role-title')).toContainText(/Your focus/i);
      await expect(page.locator('.crm-today-stat__value').first()).toBeVisible();
      await expect(page.locator('.crm-quick-filters[aria-label="Jump to queue"]')).toBeVisible();

      await expect(table).toBeVisible();
      await expect(table.locator('thead th', { hasText: /^What$/i })).toBeVisible();
      await expect(table.locator('thead th', { hasText: /^Company$/i })).toBeVisible();
      await expect(table.locator('thead th', { hasText: /^Due$/i })).toBeVisible();
      await expect(table.locator('thead th', { hasText: /^Action$/i })).toBeVisible();

      const firstChip = page.locator('.crm-quick-filters[aria-label="Jump to queue"] .crm-quick-filter').first();
      await expect(firstChip).toBeVisible();
      const href = await firstChip.getAttribute('href');
      expect(href).toMatch(/^#crm-cmd-/);
      await firstChip.click();
      const target = page.locator(href);
      await expect(target).toBeVisible();

      // Action cells are buttons or primary CTA links — not a wall of underlined middot rows.
      const actionCell = table.locator('tbody tr').first().locator('.crm-table-actions');
      await expect(actionCell.locator('a.crm-btn, button.crm-btn, .crm-muted')).toHaveCount(1);

      const longLink = table.locator('a.crm-list__row-link');
      await expect(longLink).toHaveCount(0);
    }

    // Admin / manager sees Team in nav (may sit below the fold in a scrollable sidebar).
    const teamLink = page.locator('#app-navigation').getByRole('link', { name: 'Team' });
    await teamLink.scrollIntoViewIfNeeded();
    await expect(teamLink).toBeVisible();
  });

  test('today passes axe serious/critical and keeps touch targets', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/today');

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    await page.setViewportSize({ width: 375, height: 812 });
    await gotoCrm(page, '/apps/customercheck/today');
    const overflow = await page.locator('#crm-main-content').evaluate((el) => el.scrollWidth > el.clientWidth + 2);
    expect(overflow).toBeFalsy();

    const chip = page.locator('.crm-quick-filter').first();
    if (await chip.isVisible().catch(() => false)) {
      const box = await chip.boundingBox();
      expect(box).toBeTruthy();
      expect(box.height).toBeGreaterThanOrEqual(44);
    }

    const actionBtn = page.locator('.crm-command-section .crm-table-actions .crm-btn').first();
    if (await actionBtn.isVisible().catch(() => false)) {
      const box = await actionBtn.boundingBox();
      expect(box).toBeTruthy();
      expect(box.height).toBeGreaterThanOrEqual(44);
    }
  });

  test('inline Done marks the table row without a confirm dialog', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await gotoCrm(page, '/apps/customercheck/companies/new', { ready: '#crm-company-form' });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Today Done ${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await page.locator('#crm-add-activity').click();
    const form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await form.locator('[name="title"]').fill(`Today call ${suffix}`);
    await form.locator('[name="type"]').selectOption('call');
    const today = new Date().toISOString().slice(0, 10);
    await form.locator('[name="dueDate"]').fill(today);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await gotoCrm(page, '/apps/customercheck/today');
    const doneBtn = page.locator('[data-crm-complete-activity]').first();
    await expect(doneBtn).toBeVisible({ timeout: 15000 });
    await doneBtn.click();
    await expect(page.locator('.crm-today-row--done .crm-badge--success', { hasText: /^Done$/i }).first()).toBeVisible({
      timeout: 10000,
    });
  });
});
