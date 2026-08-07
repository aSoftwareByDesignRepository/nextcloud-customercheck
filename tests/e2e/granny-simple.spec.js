// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Extreme simplicity — name-only create, one Log email, collapsed expert chrome.
 */
test.describe('Granny-simple company 360', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('name-only create, single log email, people lean, account plan collapsed', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    await expect(page.locator('#crm-company-advanced')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-company-status')).toBeAttached();
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Granny Co ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await expect(page.locator('#crm-welcome-coach')).toBeVisible();
    await expect(page.locator('#crm-persons .crm-empty-state [data-crm-trigger="crm-add-person"]')).toBeVisible();
    await expect(page.locator('#crm-deals .crm-empty-state [data-crm-trigger="crm-add-deal"]')).toBeVisible();
    await expect(page.locator('#crm-log-email')).toBeVisible();
    await expect(page.locator('#crm-log-email')).toHaveCount(1);
    await expect(page.locator('#crm-schedule-callback')).toContainText(/Call tomorrow|Morgen anrufen/i);
    await expect(page.locator('#crm-account-plan')).toBeVisible();
    await expect(page.locator('#crm-account-plan')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-promises .crm-card__lead')).toContainText(/What you told the customer|Was ihr dem Kunden zugesagt habt/);
    await expect(page.locator('#crm-promises .crm-card__lead')).not.toContainText(/WIP/i);

    const personsTable = page.locator('#crm-persons-table');
    if (await personsTable.count()) {
      await expect(personsTable.locator('thead th')).toHaveCount(3);
      await expect(personsTable.locator('thead th', { hasText: /^Buying role$/i })).toHaveCount(0);
    } else {
      await expect(page.locator('#crm-persons .crm-empty-state')).toBeVisible();
    }

    const jump = page.locator('[data-crm-jump-nav="1"]');
    await expect(jump.locator('.crm-jump-nav__list').first().locator('.crm-jump-nav__link')).toHaveCount(5);
    await expect(jump.getByRole('link', { name: /Communication|Kommunikation|Gespräche/i }).first()).toBeVisible();
    await expect(jump.getByRole('link', { name: /Deals/i }).first()).toBeVisible();
    await expect(jump.locator('.crm-jump-nav__link')).toHaveCount(14);

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('brief last-week verify exposes one primary Done with default evidence', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/brief');
    await page.waitForSelector('[data-crm-persona-mount="brief"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="brief"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });

    const verify = page.locator('.crm-brief-verify').first();
    await expect(page.locator('#crm-brief-wip-title, .crm-card__title').first()).toBeVisible();
    await expect(page.locator('.crm-card__lead').first()).toContainText(/This week|Diese Woche|promises|Zusagen|Promises left|Freie Zusagen|Slots left|Freie Plätze|Your week is full|Deine Woche|View only|Nur lesen/i);
    await expect(page.locator('.crm-card__lead').first()).not.toContainText(/\bWIP\b/);
    await expect(page.getByText(/Money customers still owe|Geld, das Kunden noch schulden/i).first()).toBeVisible();
    await expect(page.getByText(/Overdue AR|Überfällige AR/i)).toHaveCount(0);

    if (await verify.count() === 0) {
      test.info().annotations.push({ type: 'note', description: 'No last-week commitments needing verify — chrome still loads' });
      return;
    }
    await expect(verify.locator('select[data-evidence-for]')).toHaveValue('activity_done');
    await expect(verify.locator('button[data-action="done"].crm-btn--primary')).toBeVisible();
    await expect(verify.locator('.crm-brief-verify__more')).toBeVisible();
    await expect(verify.locator('button[data-action="missed"]')).toBeHidden();
  });
});
