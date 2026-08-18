// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';

/**
 * Strategy / Plans board — card shell, filters, open plan deep-link, axe clean.
 */
test.describe('Strategy board extreme simplicity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('board shell, filters, open plan, axe + mobile targets', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    // Ensure at least one plan exists via company 360.
    await gotoCrm(page, '/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Strategy Board ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    const companyUrl = page.url();

    await page.locator('[data-crm-focus-tab="more"]').click();
    await expect(page.locator('#crm-account-plan-form')).toBeVisible({ timeout: 20000 });
    await page.locator('#crm-plan-goal').fill(`Win renewal for ${suffix}`);
    await page.locator('#crm-account-plan .crm-account-plan__more > summary').click();
    await page.locator('#crm-plan-priority').selectOption('p1');
    await page.locator('#crm-account-plan-form button[type="submit"]').click();
    await expect(page.locator('#crm-account-plan-body')).not.toContainText(/Could not save|konnte nicht/i);

    await gotoCrm(page, '/apps/customercheck/strategy?workspaceId=1');
    const mount = page.locator('[data-crm-persona-mount="strategy"]');
    await expect(mount).toBeVisible({ timeout: 30000 });
    await expect(mount.locator('[role="alert"]')).toHaveCount(0, { timeout: 20000 });
    await expect(page.locator('#crm-strategy-board')).toBeVisible();
    await expect(page.locator('#crm-strategy-board-title')).toContainText(/Account plans|Account-Pläne/i);
    await expect(mount.locator('.crm-quick-filters')).toBeVisible();
    await expect(mount.locator('.crm-table--strategy')).toBeVisible({ timeout: 20000 });
    await expect(mount).toContainText(`Win renewal for ${suffix}`);
    await expect(mount).toContainText(/High|Hoch/i);

    const openPlan = mount.locator('a.crm-btn', { hasText: /Open plan|Plan öffnen/i }).first();
    await expect(openPlan).toBeVisible();
    const href = await openPlan.getAttribute('href');
    expect(href || '').toMatch(/#crm-account-plan$/);

    await mount.locator('[data-crm-strategy-filter-btn="high"]').click();
    await expect(mount.locator('.crm-table--strategy tbody tr.crm-strategy-row').first()).toBeVisible();
    await expect(mount.locator('[data-crm-strategy-filter-btn="high"]')).toHaveAttribute('aria-pressed', 'true');

    const edit = mount.locator('[data-edit-plan]').first();
    if (await edit.count()) {
      await edit.click();
      const form = mount.locator('form[data-plan-form]');
      await expect(form).toBeVisible();
      await expect(form.locator('label.form-label').first()).toBeVisible();
      await expect(form.locator('.crm-plan-more')).toBeVisible();
      const cancel = form.locator('[data-cancel-plan]');
      await cancel.click();
      await expect(form).toBeHidden();
    }

    const results = await new AxeBuilder({ page })
      .include('#crm-strategy-root')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    await page.setViewportSize({ width: 390, height: 844 });
    await gotoCrm(page, '/apps/customercheck/strategy?workspaceId=1');
    await expect(mount.locator('.crm-table--strategy')).toBeVisible({ timeout: 20000 });
    const primary = mount.locator('a.crm-btn--primary').first();
    await expect(primary).toBeVisible();
    const box = await primary.boundingBox();
    expect(box?.height || 0).toBeGreaterThanOrEqual(44);

    // Deep link still lands on company plan section.
    await page.goto(companyUrl.split('#')[0] + '#crm-account-plan');
    await expect(page.locator('#crm-account-plan')).toBeVisible({ timeout: 20000 });
  });
});
