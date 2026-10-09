// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Brief / Mail triage must use suite tables (not orphan crm-list walls).
 * Pulse attention must not leak snake_case kinds.
 */
test.describe('Persona suite tables + human labels', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Brief last week and this week use suite tables', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/brief?workspaceId=1');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('#app-content.crm-app.crm-app--brief')).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="brief"] [role="alert"]')).toHaveCount(0, { timeout: 20000 });
    await expect(page.locator('[data-crm-brief-last]')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('[data-crm-brief-last] table.table, [data-crm-brief-last] .crm-callout').first()).toBeVisible();
    await expect(page.locator('[data-crm-brief-this] table.table, [data-crm-brief-this] .crm-callout').first()).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="brief"] ul.crm-list')).toHaveCount(0);
    await expect(page.locator('#crm-brief-add')).toBeVisible();
    await expect(page.locator('#crm-brief-add')).toContainText(/Add a commitment|Zusage hinzufügen|Commitment hinzufügen/i);
    await expect(page.locator('#crm-brief-add')).not.toContainText(/^Confirm commitment$|^New commitment$/i);
    const title = page.locator('#crm-brief-title');
    if (await title.count()) {
      await expect(title).toBeVisible();
      await expect(page.locator('label[for="crm-brief-title"]')).toContainText(/What will you finish|Was wirst du (erledigen|fertigstellen)/i);
      await expect(page.locator('#crm-brief-confirm-submit')).toBeVisible();
    }

    const results = await new AxeBuilder({ page })
      .include('[data-crm-persona-mount="brief"]')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    const addAxe = await new AxeBuilder({ page })
      .include('#crm-brief-add')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const addSerious = addAxe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(addSerious, JSON.stringify(addSerious, null, 2)).toEqual([]);
  });

  test('Review inbox uses suite table shell (mail-triage redirects into Inbox Unassigned)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/mail-triage?workspaceId=1');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page).toHaveURL(/\/sales-inbox/);
    await expect(page).toHaveURL(/tab=unmatched/);
    await expect(page.locator('#app-content.crm-app.crm-app--sales-inbox, #crm-sales-inbox-root').first()).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#crm-sales-inbox-list, #crm-sales-inbox-empty').first()).toBeVisible({ timeout: 20000 });
    await expect(page.locator('[data-crm-persona-mount="review"]')).toHaveCount(0);

    const results = await new AxeBuilder({ page })
      .include('#crm-sales-inbox-root')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('Pulse attention detail never shows raw plan_ or renewal_hot codes', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/pulse?workspaceId=1');
    await page.waitForSelector('#crm-pulse-metrics', { timeout: 30000 });
    const mount = page.locator('[data-crm-persona-mount="pulse"]');
    await expect(mount).not.toContainText(/\brenewal_hot\b/);
    await expect(mount).not.toContainText(/\bplan_stalled\b/);
    await expect(mount).not.toContainText(/\bplan_at_risk\b/);
    await expect(mount).not.toContainText(/\boverdue_invoice\b/);
    await expect(mount).not.toContainText(/\bhonesty_blocked\b/);
  });
});
