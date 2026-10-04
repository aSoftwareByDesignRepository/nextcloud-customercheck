// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Bachus Linked apps: one Link apps card with identity CTAs + status roster (no twin setup card).
 */
test.describe('Linked apps bundle extreme simplicity', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('cold company shows one Link apps card with CTAs; no twin setup card; axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Linked Bundle ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    const bundle = page.locator('#crm-linked-bundle');
    await expect(bundle).toBeVisible();
    await expect(bundle).not.toHaveAttribute('open', '');
    await bundle.locator('> summary').click();
    await expect(bundle).toHaveAttribute('open', '');

    await expect(bundle).toHaveClass(/crm-linked-bundle--setup/);
    await expect(page.locator('#crm-identity-links')).toHaveCount(1);
    await expect(page.locator('#crm-identity-links')).toBeVisible();
    // Identity lives inside the linked bundle — not a second collapsed twin.
    await expect(page.locator('details#crm-identity-links')).toHaveCount(0);
    await expect(bundle.locator('#crm-identity-links [data-crm-identity-create="pc"], #crm-identity-links [data-crm-identity-link="pc"]')).toHaveCount(2);
    await expect(bundle.locator('.crm-linked-status__row')).toHaveCount(3);
    await expect(bundle.locator('.crm-linked-bundle__setup')).toHaveCount(0);
    await expect(bundle.locator('.crm-card--nested')).toHaveCount(0);
    await expect(bundle.locator('.crm-identity-row__app', { hasText: /ProjectCheck/i })).toHaveCount(1);
    await expect(bundle.locator('.crm-identity-row__app', { hasText: /TicketCheck/i })).toHaveCount(1);
    // No duplicate “Not linked” prose under the badge — literal jargon bans.
    for (const jargon of ['Nicht mit ProjectCheck verknüpft', 'Not linked to ProjectCheck']) {
      await expect(bundle.getByText(jargon)).toHaveCount(0);
    }

    const results = await new AxeBuilder({ page })
      .include('#crm-linked-bundle')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('mobile: primary create CTA stays tappable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Linked Mobile ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await page.locator('#crm-linked-bundle > summary').click();
    const cta = page.locator('#crm-identity-links [data-crm-identity-create="pc"], #crm-identity-links [data-crm-identity-link="pc"]').first();
    await expect(cta).toBeVisible();
    const box = await cta.boundingBox();
    expect(box?.height || 0).toBeGreaterThanOrEqual(44);
  });
});
