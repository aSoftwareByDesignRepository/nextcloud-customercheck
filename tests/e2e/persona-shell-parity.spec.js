// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Persona pages must share Today/Companies shell: nav, header, suite cards/tables.
 */
test.describe('Persona shell parity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Pulse uses CRM shell, jump chips, stats, and suite tables', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/pulse?workspaceId=1');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });

    await expect(page.locator('#customercheck-app')).toBeVisible();
    await expect(page.locator('#app-content.crm-app.crm-app--pulse')).toBeVisible();
    await expect(page.locator('#app-navigation.crm-nav')).toBeVisible();
    await expect(page.locator('#crm-page-title')).toContainText(/Overview|Übersicht|Überblick|Pulse/i);
    await expect(page.locator('.crm-page-header__lead')).toBeVisible();

    await expect(page.locator('[data-crm-persona-mount="pulse"] [role="alert"]')).toHaveCount(0, { timeout: 20000 });
    await expect(page.locator('#crm-pulse-metrics')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#crm-pulse-metrics')).toContainText(/At a glance|Auf einen Blick/i);
    await expect(page.locator('#crm-pulse-money-title')).toBeVisible();
    await expect(page.locator('#crm-pulse-work-title')).toBeVisible();
    await expect(page.locator('#crm-pulse-metrics .crm-today-stat').first()).toBeVisible();
    await expect(page.locator('#crm-pulse-metrics')).not.toContainText(/Not available yet|Noch nicht verfügbar|Health metrics/i);
    await expect(page.locator('#crm-pulse-metrics .crm-today-stat__label', { hasText: /^(Attention|Missing links|Aufmerksamkeit|Fehlende)/i })).toHaveCount(0);
    await expect(page.locator('.crm-quick-filters[aria-label="Jump to section"], .crm-quick-filters').first()).toBeVisible();
    await expect(page.locator('#crm-pulse-attention table.table, #crm-pulse-attention .crm-callout').first()).toBeVisible();
    await expect(page.locator('#crm-pulse-debt table.table, #crm-pulse-debt .crm-callout').first()).toBeVisible();
    // Literal machine-key leak check — 'honesty_blocked' must never render.
    await expect(page.getByText('honesty_blocked')).toHaveCount(0);
    await expect(page.locator('#crm-pulse-metrics')).not.toContainText('honesty_blocked');

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    const pulseAxe = await new AxeBuilder({ page })
      .include('#crm-pulse-metrics')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const pulseSerious = pulseAxe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(pulseSerious, JSON.stringify(pulseSerious, null, 2)).toEqual([]);
  });

  test('Brief and Strategy keep CRM shell chrome', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/brief?workspaceId=1');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('#app-content.crm-app.crm-app--brief')).toBeVisible();
    // Structural: nav labels are translated, the /brief route is not.
    await expect(page.getByRole('navigation').locator('a[href*="/brief"]')).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="brief"] .crm-card, [data-crm-persona-mount="brief"] .crm-today-stat').first()).toBeVisible({ timeout: 20000 });

    await page.goto('/apps/customercheck/strategy?workspaceId=1');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('#app-content.crm-app.crm-app--strategy')).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="strategy"] [role="alert"]')).toHaveCount(0, { timeout: 15000 });
  });
});
