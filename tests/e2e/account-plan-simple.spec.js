// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Bachus Account plan: goal + big move textarea, Done on moves, axe clean.
 */
test.describe('Account plan extreme simplicity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('add move via textarea, mark done, axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Plan Move ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    const plan = page.locator('#crm-account-plan');
    await page.locator('[data-crm-focus-tab="more"]').click();
    await expect(plan).toBeVisible();
    const steps = page.locator('#crm-account-plan .crm-account-plan__guide > .crm-welcome-coach__step');
    await expect(page.locator('#crm-account-plan .crm-welcome-coach__steps')).toBeVisible({ timeout: 20000 });
    await expect(steps).toHaveCount(3);
    const firstStep = await steps.nth(0).boundingBox();
    const thirdStep = await steps.nth(2).boundingBox();
    expect(firstStep && thirdStep).toBeTruthy();
    expect(Math.abs((firstStep?.y || 0) - (thirdStep?.y || 0))).toBeLessThan(24);
    expect((thirdStep?.x || 0)).toBeGreaterThan((firstStep?.x || 0) + 80);
    await expect(page.locator('#crm-account-plan-form')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#crm-account-plan .crm-account-plan__stack')).toBeVisible();
    await expect(page.locator('#crm-account-plan .crm-account-plan__panel--goal')).toBeVisible();
    await expect(page.locator('#crm-account-plan .crm-account-plan__panel--moves')).toBeVisible();

    await expect(page.locator('#crm-account-plan .crm-card__lead')).toContainText(
      /Where this customer should go|Wohin dieser Kunde soll/i,
    );
    await expect(page.locator('#crm-plan-goal')).toBeVisible();
    const goalBox = await page.locator('#crm-plan-goal').boundingBox();
    expect(goalBox?.height || 0).toBeGreaterThanOrEqual(72);

    // Priority/owner demoted under details
    await expect(page.locator('#crm-plan-priority')).toBeAttached();
    await expect(page.locator('#crm-account-plan .crm-account-plan__more')).toBeVisible();
    await expect(page.locator('#crm-account-plan .crm-account-plan__more')).not.toHaveAttribute('open', '');

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
    createdCompanies.push(page.url());
    await page.locator('[data-crm-focus-tab="more"]').click();
    await expect(page.locator('#crm-map-step-form')).toBeVisible({ timeout: 20000 });
    const steps = page.locator('#crm-account-plan .crm-account-plan__guide > .crm-welcome-coach__step');
    await expect(steps).toHaveCount(3);
    const firstStep = await steps.nth(0).boundingBox();
    const thirdStep = await steps.nth(2).boundingBox();
    expect(firstStep && thirdStep).toBeTruthy();
    expect((thirdStep?.y || 0)).toBeGreaterThan((firstStep?.y || 0) + 24);
    const add = page.locator('#crm-map-step-form button[type="submit"]');
    const box = await add.boundingBox();
    expect(box?.height || 0).toBeGreaterThanOrEqual(44);
  });

  test('Save plan persists the goal even with a calendar review date', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Plan Save ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    await page.locator('[data-crm-focus-tab="more"]').click();
    const form = page.locator('#crm-account-plan-form');
    await expect(form).toBeVisible({ timeout: 20000 });
    await expect(form).toHaveAttribute('novalidate', '');

    const goal = page.locator('#crm-plan-goal');
    await goal.fill(`Renew seats this year ${suffix}`);
    const more = page.locator('#crm-account-plan .crm-account-plan__more');
    if (!(await more.getAttribute('open'))) {
      await more.locator('summary').click();
    }
    const review = page.locator('#crm-plan-review');
    await expect(review).toBeVisible();
    await expect(page.locator('#crm-account-plan-form .crm-datepicker-host')).toBeVisible();

    const putWait = page.waitForResponse((r) => r.url().includes('/account-plan') && r.request().method() === 'PUT');
    await form.locator('button[type="submit"]').click();
    const put = await putWait;
    expect(put.ok(), 'account-plan PUT must succeed').toBeTruthy();
    const putBody = put.request().postDataJSON();
    expect(putBody.goal).toBe(`Renew seats this year ${suffix}`);
    expect(
      putBody.nextAssignOn === '' || /^\d{4}-\d{2}-\d{2}$/.test(String(putBody.nextAssignOn || '')),
      'nextAssignOn must be ISO YYYY-MM-DD or empty, not European display',
    ).toBeTruthy();
    await expect(page.locator('#crm-plan-save-status')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#crm-plan-save-status')).toContainText(/Account plan saved|Account-Plan gespeichert/i);
    await expect(page.locator('#crm-account-plan-form button[type="submit"]')).toHaveAttribute('data-plan-just-saved', '1');
    await expect(form).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#crm-plan-goal')).toHaveValue(`Renew seats this year ${suffix}`, { timeout: 20000 });
    await expect(form).not.toHaveAttribute('aria-busy', 'true');
    await expect(form.locator('button[type="submit"]')).toBeEnabled();
    const stampAfterFirst = await page.locator('#crm-account-plan-form').getAttribute('data-plan-updated-at');
    expect(stampAfterFirst && stampAfterFirst.length > 8).toBeTruthy();

    const form2 = page.locator('#crm-account-plan-form');
    await page.locator('#crm-plan-goal').fill(`Renew seats this year ${suffix} v2`);
    const put2Wait = page.waitForResponse((r) => r.url().includes('/account-plan') && r.request().method() === 'PUT');
    await form2.locator('button[type="submit"]').click();
    const put2 = await put2Wait;
    expect(put2.ok(), 'second account-plan PUT (optimistic lock) must succeed').toBeTruthy();
    await expect(page.locator('#crm-plan-goal')).toHaveValue(`Renew seats this year ${suffix} v2`, { timeout: 20000 });
    await expect(page.locator('#crm-plan-save-status')).toBeVisible();
    await expect(page.locator('#crm-plan-save-status')).toContainText(/Account plan saved|Account-Plan gespeichert/i);
  });
});
