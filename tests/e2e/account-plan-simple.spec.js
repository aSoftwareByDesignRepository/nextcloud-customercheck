// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Bachus Account plan: goal + big move textarea, Done on moves, axe clean.
 */
test.describe('Account plan extreme simplicity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('add move via textarea, mark done, axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Plan Move ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    const plan = page.locator('#crm-account-plan');
    await expect(plan).toBeVisible();
    await page.locator('#crm-account-plan > summary').click();
    await expect(plan).toHaveAttribute('open', '');
    await expect(page.locator('#crm-account-plan-form')).toBeVisible({ timeout: 20000 });

    await expect(page.locator('#crm-account-plan .crm-card__lead')).toContainText(
      /Where this customer should go|Wohin soll es mit diesem Kunden/i,
    );
    await expect(page.locator('#crm-plan-goal')).toBeVisible();
    const goalBox = await page.locator('#crm-plan-goal').boundingBox();
    expect(goalBox?.height || 0).toBeGreaterThanOrEqual(72);

    // Priority/owner demoted under details
    await expect(page.locator('#crm-plan-priority')).toBeAttached();
    await expect(page.locator('.crm-plan-more')).toBeVisible();
    await expect(page.locator('.crm-plan-more')).not.toHaveAttribute('open', '');

    const title = page.locator('#crm-map-step-title');
    await expect(title).toHaveAttribute('rows', '3');
    const titleTag = await title.evaluate((el) => el.tagName);
    expect(titleTag).toBe('TEXTAREA');
    await title.fill(`Book QBR with CFO\nfor ${suffix}`);
    await page.locator('#crm-map-step-form button[type="submit"]').click();

    const move = page.locator('.crm-plan-moves__list .crm-todo-list__item').first();
    await expect(move).toBeVisible({ timeout: 15000 });
    await expect(move.locator('.crm-todo-list__title')).toContainText(`Book QBR with CFO for ${suffix}`);
    const done = move.locator('[data-crm-map-step-done]');
    await expect(done).toBeVisible();
    const doneBox = await done.boundingBox();
    expect(doneBox?.height || 0).toBeGreaterThanOrEqual(44);
    await done.click();
    await expect(page.locator('.crm-plan-moves__list .crm-todo-list__item--closed')).toHaveCount(1, { timeout: 15000 });

    const results = await new AxeBuilder({ page })
      .include('#crm-account-plan')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('mobile: move textarea and add button stay tappable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Plan Mobile ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    await page.locator('#crm-account-plan > summary').click();
    await expect(page.locator('#crm-map-step-form')).toBeVisible({ timeout: 20000 });
    const add = page.locator('#crm-map-step-form button[type="submit"]');
    const box = await add.boundingBox();
    expect(box?.height || 0).toBeGreaterThanOrEqual(44);
  });
});
