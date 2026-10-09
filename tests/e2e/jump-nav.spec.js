// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

test.describe('Company detail focus tabs', () => {
  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('five tabs show one panel; hash opens the right tab', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC credentials');
    const creds = process.env.NC_ADMIN_USER ? credsFromEnv('ADMIN') : credsFromEnv('EMPLOYEE');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await login(page, creds);

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Focus Tab Co ${suffix}`);
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await page.locator('#crm-company-slug').fill(`focus-tab-co-${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());
    await page.waitForSelector('[data-crm-360-focus="1"]', { timeout: 30000 });

    const focus = page.locator('[data-crm-360-focus="1"]');
    await expect(focus.locator('[data-crm-focus-tab]')).toHaveCount(5);
    await expect(focus.locator('[data-crm-focus-tab="today"][aria-selected="true"]')).toBeVisible();
    await expect(focus.locator('[data-crm-focus-tab="people"]')).toBeVisible();
    await expect(page.locator('#crm-360-panel-deals')).toHaveAttribute('hidden', '');
    await expect(page.locator('#crm-360-panel-people')).toHaveAttribute('hidden', '');
    await expect(page.locator('#crm-communication')).toBeVisible();

    await focus.locator('[data-crm-focus-tab="people"]').click();
    await expect(focus.locator('[data-crm-focus-tab="people"][aria-selected="true"]')).toBeVisible();
    await expect(page.locator('#crm-persons')).toBeVisible();
    await expect(page.locator('#crm-360-panel-today')).toHaveAttribute('hidden', '');

    await focus.locator('[data-crm-focus-tab="deals"]').click();
    await expect(focus.locator('[data-crm-focus-tab="deals"][aria-selected="true"]')).toBeVisible();
    await expect(page.locator('#crm-deals')).toBeVisible();
    await expect(page.locator('#crm-360-panel-today')).toHaveAttribute('hidden', '');

    await page.evaluate(() => {
      window.location.hash = 'crm-next-steps';
    });
    await expect(page).toHaveURL(/#crm-next-steps$/);
    await expect(focus.locator('[data-crm-focus-tab="today"][aria-selected="true"]')).toBeVisible();
    await expect(page.locator('#crm-next-steps')).toBeVisible();

    // Deep link from outside the focus root (glance / hash) must open Money.
    await page.evaluate(() => {
      window.location.hash = 'crm-tickets';
    });
    await expect(page).toHaveURL(/#crm-tickets$/);
    await expect(focus.locator('[data-crm-focus-tab="money"][aria-selected="true"]')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#crm-tickets')).toBeVisible();
    await expect(page.locator('[data-crm-360-focus="1"]')).toHaveAttribute('data-crm-focus-ready', '1');

    // Manual tab switch must clear a stale deep-link so reload cannot snap back.
    await focus.locator('[data-crm-focus-tab="today"]').click();
    await expect(focus.locator('[data-crm-focus-tab="today"][aria-selected="true"]')).toBeVisible();
    await expect(page).not.toHaveURL(/#crm-tickets$/);
    await expect(page.locator('#crm-communication')).toBeVisible();

    // Roving tabindex: arrows move selection in stable Today→People→Deals→Money→More order.
    await focus.locator('[data-crm-focus-tab="today"]').focus();
    await page.keyboard.press('ArrowRight');
    await expect(focus.locator('[data-crm-focus-tab="people"][aria-selected="true"]')).toBeVisible();
    await expect(focus.locator('[data-crm-focus-tab="people"]')).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect(focus.locator('[data-crm-focus-tab="deals"][aria-selected="true"]')).toBeVisible();
    await expect(focus.locator('[data-crm-focus-tab="deals"]')).toBeFocused();
    await page.keyboard.press('End');
    await expect(focus.locator('[data-crm-focus-tab="more"][aria-selected="true"]')).toBeVisible();
    await expect(page.locator('#crm-account-plan')).toBeVisible();

    await page.locator('#crm-timeline > summary').click();
    await expect(page.locator('#crm-timeline')).toHaveAttribute('open', '');

    await expect(page.locator('#crm-tickets')).toHaveCount(1);

    // Narrow viewport: tabs wrap; 48px targets stay tappable.
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(focus.locator('[data-crm-focus-tab="more"]')).toBeVisible();
    await focus.locator('[data-crm-focus-tab="money"]').click();
    await expect(page.locator('#crm-billing')).toBeVisible();
  });
});
