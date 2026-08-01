// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Workspace switcher UX — labelled disclosure, SSR context, WCAG AA.
 */
test.describe('CustomerCheck workspace switcher', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('sidebar shows labelled workspace context without a naked select', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/pulse');
    await page.waitForSelector('#customercheck-app', { timeout: 30000 });
    await page.waitForSelector('[data-crm-workspace-switch]', { timeout: 30000 });

    const switcher = page.locator('[data-crm-workspace-switch]');
    await expect(switcher).toBeVisible();
    await expect(page.locator('#crm-workspace-switch-label')).toHaveText(/Workspace|Arbeitsbereich/i);
    await expect(page.locator('select[data-crm-workspace-switch]')).toHaveCount(0);
    await expect(page.locator('.app-brand__subtitle')).toContainText(/Deals, customers & money|Deals, Kunden & Geld|Geschäfte, Kunden & Geld/i);

    const active = await switcher.getAttribute('data-crm-active-workspace');
    expect(Number(active)).toBeGreaterThan(0);
    await expect(page.locator('#crm-workspace-label')).not.toBeEmpty();

    const multi = await switcher.evaluate((el) => el.classList.contains('crm-workspace-switcher--multi'));
    if (multi) {
      const trigger = page.locator('.crm-workspace-switcher__trigger');
      await expect(trigger).toBeVisible();
      await expect(trigger).toHaveAttribute('aria-expanded', 'false');
      await trigger.click();
      await expect(trigger).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator('#crm-workspace-menu')).toBeVisible();
      await expect(page.locator('#crm-workspace-menu .crm-workspace-switcher__option').first()).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    } else {
      await expect(page.locator('.crm-workspace-switcher__current')).toBeVisible();
      await expect(page.locator('.crm-workspace-switcher__trigger')).toHaveCount(0);
    }

    const accessibility = await new AxeBuilder({ page })
      .include('#app-navigation')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    expect(accessibility.violations, JSON.stringify(accessibility.violations, null, 2)).toEqual([]);
  });
});
