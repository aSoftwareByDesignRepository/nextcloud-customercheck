// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Extreme-simplicity user journeys — 1-click filters, progressive 360, forgiving create.
 */
test.describe('CustomerCheck extreme simplicity journeys', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('create company with name only (auto slug) and sees progressive 360', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });

    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Simple Co ${suffix}`);
    await expect(page.locator('#crm-company-advanced')).toBeVisible();
    // Slug stays collapsed — submit must auto-fill
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await expect(page.locator('#crm-identity')).toBeVisible();
    await expect(page.locator('#crm-identity-links')).toBeAttached();
    await expect(page.locator('#crm-persons')).toBeVisible();
    await expect(page.locator('#crm-activities')).toBeVisible();
    await page.locator('[data-crm-focus-tab="deals"]').click();
    await expect(page.locator('#crm-contracts')).toBeVisible();
    await page.locator('[data-crm-focus-tab="money"]').click();
    await expect(page.locator('#crm-linked-bundle')).toBeVisible();
    await expect(page.locator('#crm-linked-bundle')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-tickets')).toHaveCount(1);

    // Expand Linked apps: identity CTAs live here — not a second setup card.
    await page.locator('#crm-linked-bundle > summary').click();
    await expect(page.locator('#crm-linked-bundle')).toHaveAttribute('open', '');
    await expect(page.locator('#crm-linked-bundle .crm-linked-status__row')).toHaveCount(3);
    await expect(page.locator('#crm-linked-bundle #crm-identity-links')).toBeVisible();
    await expect(page.locator('details#crm-identity-links')).toHaveCount(0);
    await expect(page.locator('#crm-linked-bundle .crm-linked-bundle__setup')).toHaveCount(0);
    await expect(page.locator('#crm-linked-bundle .crm-card--nested')).toHaveCount(0);

    await page.locator('[data-crm-focus-tab="more"]').click();
    await expect(page.locator('#crm-timeline')).toBeAttached();

    // Focus tabs + hash opens the Money panel and linked-apps bundle
    await page.evaluate(() => {
      window.location.hash = 'crm-tickets';
    });
    await expect(page.locator('[data-crm-focus-tab="money"][aria-selected="true"]')).toBeVisible();
    await expect(page.locator('#crm-linked-bundle')).toHaveJSProperty('open', true);
    await expect(page.locator('#crm-tickets')).toBeVisible();
  });

  test('one-click company status chip filters without Apply', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });

    const chips = page.locator('.crm-quick-filters .crm-quick-filter');
    await expect(chips).toHaveCount(4);
    await page.locator('.crm-quick-filter[href*="status=active"]').click();
    await page.waitForURL(/status=active/, { timeout: 15000 });
    await expect(page.locator('.crm-quick-filter[href*="status=active"]')).toHaveAttribute('aria-current', 'page');
  });

  test('money and agreements chips are one click', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/money');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await page.locator('.crm-quick-filter[href*="state=overdue"]').click();
    await page.waitForURL(/state=overdue/, { timeout: 15000 });

    await page.goto('/apps/customercheck/agreements');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await page.locator('.crm-quick-filter[href*="kind=license"]').click();
    await page.waitForURL(/kind=license/, { timeout: 15000 });
  });

  test('human-readable status labels on company form', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const options = await page.locator('#crm-company-status option').allTextContents();
    // EN or DE UI locale — assert values, not a single language.
    expect(options.join(' ')).toMatch(/Lead/i);
    expect(options.join(' ')).toMatch(/Active|Aktiv/i);
    expect(options.join(' ')).toMatch(/Paused|Pausiert/i);
    expect(options.join(' ')).toMatch(/Churned|Aufgewühlt|Abgewandert/i);
  });

  test('company 360 and today pass axe serious/critical', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    let results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    let serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`A11y Co ${suffix}`);
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await page.locator('#crm-company-slug').fill(`a11y-co-${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('mobile viewport keeps primary targets large and no horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const chip = page.locator('.crm-quick-filter').first();
    await expect(chip).toBeVisible();
    const box = await chip.boundingBox();
    expect(box).toBeTruthy();
    expect(box.height).toBeGreaterThanOrEqual(44);
    const overflow = await page.locator('#crm-main-content').evaluate((el) => el.scrollWidth > el.clientWidth + 2);
    expect(overflow).toBeFalsy();
  });
});
