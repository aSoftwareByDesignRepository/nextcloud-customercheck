// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

test.describe('Company detail jump nav', () => {
  test('chip bar jumps to sections and marks current', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC credentials');
    const creds = process.env.NC_ADMIN_USER ? credsFromEnv('ADMIN') : credsFromEnv('EMPLOYEE');
    await page.setViewportSize({ width: 1280, height: 900 });
    // Prefer instant scroll so assertions do not race CSS smooth scrolling.
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await login(page, creds);

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Jump Nav Co ${suffix}`);
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await page.locator('#crm-company-slug').fill(`jump-nav-co-${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    await page.waitForSelector('[data-crm-jump-nav="1"]', { timeout: 30000 });

    const nav = page.locator('[data-crm-jump-nav="1"]');
    await expect(nav).toHaveClass(/crm-jump-nav--bar/);
    await expect(nav.locator('.crm-jump-nav__label')).toHaveText(/On this page/i);
    await expect(nav.locator('.crm-jump-nav__list').first()).toBeVisible();
    await expect(nav.locator('.crm-jump-nav__link')).toHaveCount(9);

    // Mid-page target can sit under the sticky bar; bottom sections may only max-scroll.
    const activitiesLink = nav.locator('.crm-jump-nav__link[href="#crm-activities"]');
    await activitiesLink.click();
    await expect(activitiesLink).toHaveAttribute('aria-current', 'location');
    await expect(page).toHaveURL(/#crm-activities$/);

    await page.waitForFunction(() => {
      const root = document.querySelector('#app-content');
      const section = document.getElementById('crm-activities');
      if (!(root instanceof HTMLElement) || !(section instanceof HTMLElement)) {
        return false;
      }
      const offset = section.getBoundingClientRect().top - root.getBoundingClientRect().top;
      const maxScroll = root.scrollHeight - root.clientHeight;
      const atMax = maxScroll > 0 && root.scrollTop >= maxScroll - 2;
      return root.scrollTop > 0 && ((offset >= -20 && offset < 220) || atMax);
    }, null, { timeout: 5000 });

    const more = nav.locator('.crm-jump-nav__more');
    await more.evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    const timelineLink = nav.locator('.crm-jump-nav__link[href="#crm-timeline"]');
    await expect(timelineLink).toBeVisible();
    await timelineLink.click({ force: true });
    await expect(timelineLink).toHaveAttribute('aria-current', 'location');
    await expect(page).toHaveURL(/#crm-timeline$/);
    await page.waitForFunction(() => {
      const root = document.querySelector('#app-content');
      if (!(root instanceof HTMLElement)) {
        return false;
      }
      const maxScroll = root.scrollHeight - root.clientHeight;
      return maxScroll <= 0 || root.scrollTop >= maxScroll - 2;
    }, null, { timeout: 5000 });

    await expect(page.locator('#crm-tickets')).toHaveCount(1);
  });
});
