// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Bachus whole-app granny journeys — Today, Money, Activities, Import, Team, Pipeline.
 */
test.describe('Bachus whole-app effortless flows', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  async function axeMain(page) {
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  }

  test('Today uses plain section titles and has no Chase jargon', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/today');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });

    await expect(page.getByText(/^My Chase$/i)).toHaveCount(0);
    await expect(page.getByText(/^KAM touches$/i)).toHaveCount(0);
    await expect(page.getByText(/CEO commitments/i)).toHaveCount(0);

    const plain = page.getByRole('heading', {
      name: /Follow up on deals|Deals nachverfolgen|Customers to contact|Kunden kontaktieren|My promises|Meine Zusagen|Teammates who need help|Teammitglieder/i,
    });
    const empty = page.locator('.crm-empty-state, .crm-callout--success');
    await expect(plain.or(empty).first()).toBeVisible({ timeout: 15000 });
    await axeMain(page);
  });

  test('Money and Activities empty states expose primary CTAs', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/money');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const moneyEmpty = page.locator('.crm-empty-state');
    if (await moneyEmpty.count()) {
      await expect(moneyEmpty.getByRole('link', { name: /Open agreements|Vereinbarungen|Open companies|Unternehmen/i }).first()).toBeVisible();
    }
    await axeMain(page);

    await page.goto('/apps/customercheck/activities');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.locator('table thead th', { hasText: /^Origin$|^Ursprung$/i })).toHaveCount(0);
    const actEmpty = page.locator('.crm-empty-state');
    if (await actEmpty.count()) {
      await expect(actEmpty.getByRole('link', { name: /Open companies|Unternehmen|Open Today|Heute/i }).first()).toBeVisible();
    }
    await axeMain(page);
  });

  test('Import paste is human and Team manager tools stay collapsed', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/import');
    await page.waitForSelector('#crm-import-csv', { timeout: 30000 });
    const ph = await page.locator('#crm-import-csv').getAttribute('placeholder');
    expect(ph || '').not.toMatch(/customerId,companyName/);
    await expect(page.locator('#crm-import-accept-unambiguous')).toBeAttached();
    await expect(page.locator('#crm-import-accept-unambiguous')).toContainText(/Import clear rows|Klare Zeilen|klaren Zeilen/i);
    await axeMain(page);

    await page.goto('/apps/customercheck/team');
    await page.waitForSelector('[data-crm-persona-mount="team"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="team"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });
    await expect(page.getByText(/Open chase|Commit pipeline/i)).toHaveCount(0);
    const shell = page.locator('#capacity details.crm-advanced, details.crm-team-capacity-shell').first();
    await expect(shell).toBeVisible();
    const needsHelp = await page.locator('[data-crm-persona-mount="team"]').getByText(/Needs help|Braucht Hilfe/i).count();
    if (needsHelp > 0) {
      await expect(shell).toHaveAttribute('open', '');
    } else {
      await expect(shell).not.toHaveAttribute('open', '');
    }
    await axeMain(page);
  });

  test('Pipeline quote age is human days and stage move labels exist', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/pipeline');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.getByText(/Quote \d+ d(?!\w)|Quote \d+ T\b/i)).toHaveCount(0);
    await expect(page.locator('#crm-pipeline-new-deal, [data-crm-edit-chase], .crm-empty-state').first()).toBeVisible();
    await axeMain(page);
  });

  test('Nav uses Overview / This week / Deals and agreement form stays plain', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/today');
    await page.waitForSelector('#app-navigation', { timeout: 30000 });
    const nav = page.locator('#app-navigation');
    await expect(nav.getByRole('link', { name: /^(Overview|Übersicht|Überblick)$/i })).toBeVisible();
    await expect(nav.getByRole('link', { name: /^(This week|Diese Woche)$/i })).toBeVisible();
    await expect(nav.getByRole('link', { name: /^(Deals|Geschäfte)$/i })).toBeVisible();
    await expect(nav.getByRole('link', { name: /^Pulse$/i })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: /^Pipeline$/i })).toHaveCount(0);

    await page.goto('/apps/customercheck/agreements/new');
    await page.waitForSelector('#crm-agreement-form', { timeout: 30000 });
    await expect(page.locator('label[for="crm-ag-kind"]')).toContainText(/Type|Typ/i);
    await expect(page.locator('label[for="crm-ag-product"]')).toContainText(/Product|Produkt/i);
    await expect(page.locator('#crm-ag-product option').first()).not.toContainText(/\([a-z0-9-]{2,}\)/);
    await axeMain(page);
  });

  test('My customers and settings avoid Gap/WIP/webhook jargon', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/my-accounts');
    await page.waitForSelector('[data-crm-persona-mount="my-accounts"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="my-accounts"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });
    const accounts = page.locator('[data-crm-persona-mount="my-accounts"]');
    await expect(accounts.getByText(/^Gap$/i)).toHaveCount(0);
    await expect(accounts.getByText(/^Covered$/i)).toHaveCount(0);
    await axeMain(page);

    await page.goto('/apps/customercheck/settings');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    await expect(page.getByText(/^Slug$/i)).toHaveCount(0);
    await expect(page.getByText(/Lead webhook|Website order webhook|Lead reply SLA/i)).toHaveCount(0);
    await expect(page.getByText(/First reply within|Erste Antwort/i).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: /Weekly promise check|Wöchentliche Zusagen/i })).toBeVisible();
    const mailbox = page.locator('#section-workspace-mailbox');
    const prefs = page.locator('#section-preferences, [id*="preferences"]').first();
    if (await mailbox.count() && await prefs.count()) {
      const mBox = await mailbox.boundingBox();
      const pBox = await prefs.boundingBox();
      if (mBox && pBox) expect(mBox.y).toBeLessThan(pBox.y);
    }
    await axeMain(page);
  });

  test('Team Coach CTA and Conflicts keep-saved default are plain', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/team');
    await page.waitForSelector('[data-crm-persona-mount="team"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="team"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });
    await expect(page.getByText(/^Gaps$/i)).toHaveCount(0);
    await expect(page.getByText(/1:1 prep|No open chase items/i)).toHaveCount(0);
    await expect(page.locator('#crm-team-board')).toContainText(/Coach|On track|Needs help|Wer Hilfe|Im Plan|Coachen/i);
    await axeMain(page);

    await page.goto('/apps/customercheck/conflicts');
    await page.waitForSelector('[data-crm-persona-mount="conflicts"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="conflicts"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });
    await expect(page.locator('#crm-conflicts-lead')).toContainText(/Keep what’s saved|Gespeicherte|short reason|kurze Begründung|Resolve|Lösen/i);
    await expect(page.getByText(/at least 10 characters|mind\. 10/i)).toHaveCount(0);
    await axeMain(page);
  });
});
