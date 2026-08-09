// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Single-organisation mode — workspace context host only (no switcher chrome).
 */
test.describe('CustomerCheck single-organisation shell', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('sidebar exposes active workspace context without a switcher', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/pulse');
    await page.waitForSelector('#customercheck-app', { timeout: 30000 });
    await page.waitForSelector('[data-crm-workspace-switch]', { timeout: 30000 });

    const context = page.locator('[data-crm-workspace-switch]');
    await expect(context).toHaveCount(1);
    await expect(page.locator('select[data-crm-workspace-switch]')).toHaveCount(0);
    await expect(page.locator('.crm-workspace-switcher__trigger')).toHaveCount(0);
    await expect(page.locator('#crm-workspace-menu')).toHaveCount(0);
    await expect(page.locator('.app-brand__subtitle')).toContainText(/Deals, customers & money|Deals, Kunden & Geld|Geschäfte, Kunden & Geld/i);
    await expect(page.getByRole('link', { name: /Team & mailbox|Team & Postfach|Team & mailbox/i })).toBeVisible();

    const active = await context.getAttribute('data-crm-active-workspace');
    expect(Number(active)).toBeGreaterThan(0);

    const accessibility = await new AxeBuilder({ page })
      .include('#app-navigation')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    expect(accessibility.violations, JSON.stringify(accessibility.violations, null, 2)).toEqual([]);
  });
});
