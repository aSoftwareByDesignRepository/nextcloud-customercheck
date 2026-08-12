// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Bachus To-do card: one Add, checklist Done, quiet promises/phone extras.
 */
test.describe('To-do card extreme simplicity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('add → checklist Done; promises/phone collapsed; axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Todo Simple ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    const section = page.locator('#crm-next-steps');
    await section.scrollIntoViewIfNeeded();
    await expect(section.locator('#crm-add-activity')).toBeVisible();
    await expect(section.locator('.crm-card__header details.crm-more-menu')).toHaveCount(0);
    await expect(section.locator('#crm-activities .crm-empty-state [data-crm-trigger="crm-add-activity"]')).toHaveCount(0);
    await expect(page.locator('#crm-promises')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-open-tasks')).not.toHaveAttribute('open', '');

    await page.locator('#crm-add-activity').click();
    const form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await expect(form.locator('details.crm-advanced')).not.toHaveAttribute('open', '');
    await expect(form.locator('[name="alsoOnPhone"]')).toBeAttached();
    await expect(form.locator('[name="toldCustomer"]')).toBeAttached();
    await form.locator('[name="title"]').fill(`Call granny ${suffix}`);
    const today = new Date().toISOString().slice(0, 10);
    await form.locator('[name="dueDate"]').fill(today);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const row = page.locator('#crm-activities .crm-todo-list__item').first();
    await expect(row).toBeVisible({ timeout: 15000 });
    await expect(row.locator('.crm-todo-list__title')).toContainText(`Call granny ${suffix}`);
    const done = row.locator('[data-crm-complete-activity]');
    await expect(done).toBeVisible();
    const box = await done.boundingBox();
    expect(box, 'Done target laid out').toBeTruthy();
    expect(box.height).toBeGreaterThanOrEqual(44);
    await expect(row.locator('details.crm-more-menu')).toHaveCount(0);

    await page.locator('#crm-promises > summary').click();
    await expect(page.locator('#crm-promises')).toHaveAttribute('open', '');
    await expect(page.locator('#crm-add-promise')).toBeVisible();
    await page.locator('#crm-add-promise').click();
    const promiseForm = page.locator('form[id^="crm-prompt-"]');
    await expect(promiseForm).toBeVisible({ timeout: 10000 });
    await promiseForm.locator('[name="title"]').fill(`Quoted Friday ${suffix}`);
    await promiseForm.locator('[name="dueOn"]').fill(today);
    await promiseForm.locator('button[type="submit"]').click();
    await expect(page.locator('#crm-promises-body .crm-todo-list__item')).toContainText(`Quoted Friday ${suffix}`, {
      timeout: 15000,
    });
    await expect(page.locator('#crm-promises-body [data-crm-promise-done]')).toBeVisible();

    const results = await new AxeBuilder({ page })
      .include('#crm-next-steps')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('mobile viewport keeps giant Done and single Add', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Todo Mobile ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await page.locator('#crm-add-activity').click();
    const form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await form.locator('[name="title"]').fill(`Mobile call ${suffix}`);
    await form.locator('[name="dueDate"]').fill(new Date().toISOString().slice(0, 10));
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const done = page.locator('#crm-activities [data-crm-complete-activity]').first();
    await expect(done).toBeVisible({ timeout: 15000 });
    const box = await done.boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(44);
    expect(box.height).toBeGreaterThanOrEqual(44);
  });
});
