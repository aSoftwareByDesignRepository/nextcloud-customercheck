// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

test.describe('Simplified core UX journeys', () => {
  test('today first-run, companies list, one-click done, simple log email', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    // Either first-run CTAs, calm empty, or suite attention tables — never a dead end.
    const firstRun = page.locator('.crm-empty-state__actions a', { hasText: /Add company/i });
    const calm = page.locator('.crm-callout--success');
    const table = page.locator('.crm-command-section table.table, .crm-today-role');
    await expect(firstRun.or(calm).or(table).first()).toBeVisible({ timeout: 15000 });

    await page.goto('/apps/customercheck/companies');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('table thead th', { hasText: /^Name$/i })).toBeVisible();
    await expect(page.locator('table thead th', { hasText: /^Slug$/i })).toHaveCount(0);

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Simple Co ${suffix}`);
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await page.locator('#crm-company-slug').fill(`simple-co-${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    // Edit primary; Export visible; Erase tucked under More.
    await expect(page.locator('#crm-page-actions a.crm-btn--primary, .header-actions a.crm-btn--primary').first()).toContainText(/Edit/i);
    await expect(page.locator('#crm-company-gdpr-export')).toBeVisible();
    const more = page.locator('#crm-page-actions details.crm-more-menu, .crm-page-actions-source details.crm-more-menu').first();
    await expect(more).toBeVisible();
    await more.locator('summary').click();
    await expect(page.locator('#crm-company-gdpr-erase')).toBeVisible();

    // Jump bar: primary chips + More (Command Center section inventory).
    const jump = page.locator('[data-crm-jump-nav="1"]');
    await expect(jump.locator('.crm-jump-nav__list').first().locator('.crm-jump-nav__link')).toHaveCount(5);
    await expect(jump.locator('.crm-jump-nav__more')).toBeVisible();
    await expect(jump.locator('.crm-jump-nav__link')).toHaveCount(14);

    // New agreement keeps company context.
    const newAg = page.locator('a', { hasText: /New agreement/i }).first();
    await expect(newAg).toHaveAttribute('href', /companyId=\d+/);

    // Add person + simplified log email.
    await page.locator('#crm-add-person').click();
    let form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await form.locator('[name="name"]').fill('Ada');
    await form.locator('[name="email"]').fill(`ada-${suffix}@example.com`);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await page.locator('#crm-log-email').click();
    form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await expect(form.locator('[name="personId"]')).toBeVisible();
    await expect(form.locator('[name="subject"]')).toBeVisible();
    await expect(form.locator('[name="fromAddr"]')).toHaveCount(0);
    await form.locator('[name="subject"]').fill('Hello Ada');
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-emails table tbody tr').first()).toContainText('Hello Ada');

    // Add activity + one-click Done (no confirm dialog).
    await page.locator('#crm-add-activity').click();
    form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await form.locator('[name="title"]').fill('Call Ada');
    await form.locator('[name="type"]').selectOption('call');
    const today = new Date().toISOString().slice(0, 10);
    await form.locator('[name="dueDate"]').fill(today);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const doneBtn = page.locator('[data-crm-complete-activity]').first();
    await expect(doneBtn).toBeVisible();

    await page.waitForSelector('#crm-main-content', { timeout: 15000 });
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    await doneBtn.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-activities')).toBeVisible();
  });
});
