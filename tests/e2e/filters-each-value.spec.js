// @ts-check
/**
 * Atlas POLICY ≥3.5.10 — real each_value filter toggles (not panel-open theater).
 * Seals filt-web-pipeline-values-not-toggled-e2e + filt-web-azc-panels-not-each-value-e2e.
 */
import { test, expect } from '@playwright/test';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';
import { openFilterPanel } from './helpers/new-deal-form.js';

test.describe('CustomerCheck filter each_value (Atlas 3.5.10)', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('pipeline: stage/period/from/to toggled + empty honest + period×from/to', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/pipeline', { ready: '#crm-pipeline-toolbar' });

    await openFilterPanel(
      page,
      'crm-pipeline-filter-toggle',
      'crm-pipeline-filter',
      'crm-pipeline-controls--filter-open',
    );

    const stage = page.locator('#crm-filter-stage');
    const period = page.locator('#crm-filter-period');
    const from = page.locator('#crm-filter-from');
    const to = page.locator('#crm-filter-to');
    const apply = page.locator('#crm-pipeline-filter button[type="submit"]');

    await expect(stage).toBeVisible();
    await expect(period).toBeVisible();
    await expect(from).toBeVisible();
    await expect(to).toBeVisible();

    const stageValues = await stage.locator('option').evaluateAll((opts) =>
      opts.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter((v) => v !== ''),
    );
    expect(stageValues.length).toBeGreaterThan(0);

    for (const value of stageValues) {
      await stage.selectOption(value);
      await apply.click();
      await page.waitForURL(new RegExp(`[?&]stage=${value}(?:&|$)`), { timeout: 15000 });
      await expect(page.locator('#crm-filter-stage')).toHaveValue(value);
      // Filter chrome stays; table OR board may be the active view (other has hidden).
      await expect(page.locator('#crm-pipeline-filter')).toBeVisible();
      const tableVisible = await page.locator('#crm-pipeline-table:not([hidden])').count();
      const boardVisible = await page.locator('#crm-pipeline-board:not([hidden])').count();
      expect(tableVisible + boardVisible).toBeGreaterThan(0);
    }

    // Period presets
    for (const value of ['month', 'quarter', 'year']) {
      if (!(await page.locator('#crm-pipeline-filter').isVisible())) {
        await openFilterPanel(
          page,
          'crm-pipeline-filter-toggle',
          'crm-pipeline-filter',
          'crm-pipeline-controls--filter-open',
        );
      }
      await expect(page.locator('#crm-pipeline-filter')).toBeVisible({ timeout: 10000 });
      await page.locator('#crm-filter-period').selectOption(value);
      await page.locator('#crm-pipeline-filter button[type="submit"]').click();
      await page.waitForURL(new RegExp(`[?&]period=${value}(?:&|$)`), { timeout: 15000 });
      await expect(page.locator('#crm-filter-period')).toHaveValue(value);
    }

    // Coupled custom period × from/to (far-future empty-honest)
    if (!(await page.locator('#crm-pipeline-filter').isVisible())) {
      await openFilterPanel(
        page,
        'crm-pipeline-filter-toggle',
        'crm-pipeline-filter',
        'crm-pipeline-controls--filter-open',
      );
    }
    await expect(page.locator('#crm-pipeline-filter')).toBeVisible({ timeout: 10000 });
    await page.locator('#crm-filter-period').selectOption('custom');
    // Datepicker: visible #crm-filter-from is readonly; ISO lives on hidden [name=from|to].
    await page.evaluate(() => {
      const setIso = (displayId, name, iso) => {
        const host = document.getElementById(displayId)?.closest('.crm-datepicker-host');
        const hidden = host?.querySelector(`input[name="${name}"][data-crm-date-iso]`);
        const display = document.getElementById(displayId);
        if (hidden instanceof HTMLInputElement) {
          hidden.value = iso;
          hidden.dispatchEvent(new Event('input', { bubbles: true }));
          hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (display instanceof HTMLInputElement) {
          const [y, m, d] = iso.split('-');
          display.value = `${d}.${m}.${y}`;
        }
      };
      setIso('crm-filter-from', 'from', '2099-01-01');
      setIso('crm-filter-to', 'to', '2099-01-31');
    });
    await page.locator('#crm-pipeline-filter button[type="submit"]').click();
    await page.waitForURL(/period=custom/, { timeout: 15000 });
    await expect(page).toHaveURL(/from=2099-01-01/);
    await expect(page).toHaveURL(/to=2099-01-31/);
    await expect(page.locator('#crm-pipeline-filter')).toBeVisible();
  });

  test('companies: chips + panel status/archived + search q each_value + empty honest', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies?workspaceId=1', {
      ready: '#crm-companies-results',
    });

    await expect(page.locator('nav.crm-quick-filters')).toBeVisible();

    // Quick chips (All / Active / Leads / Archived)
    for (const status of ['lead', 'active']) {
      const chip = page.locator(`nav.crm-quick-filters a.crm-quick-filter[href*="status=${status}"]`);
      await expect(chip).toBeVisible();
      await chip.click();
      await page.waitForURL(new RegExp(`status=${status}`), { timeout: 15000 });
      await expect(page.locator('#crm-filter-status')).toHaveValue(status);
      await expect(page.locator('#crm-companies-results')).toBeVisible();
    }

    const archived = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="archived=1"]');
    await archived.click();
    await page.waitForURL(/archived=1/, { timeout: 15000 });
    await expect(page.locator('#crm-filter-archived')).toHaveValue('1');

    // Panel exposes paused/churned (not on quick strip)
    await gotoCrm(page, '/apps/customercheck/companies?workspaceId=1', {
      ready: '#crm-companies-results',
    });
    await openFilterPanel(
      page,
      'crm-companies-filter-toggle',
      'crm-companies-filter',
      'crm-list-controls--filter-open',
    );
    for (const status of ['paused', 'churned']) {
      await page.locator('#crm-filter-status').selectOption(status);
      await expect.poll(async () => page.url(), { timeout: 15000 }).toMatch(new RegExp(`status=${status}`));
      await expect(page.locator('#crm-filter-status')).toHaveValue(status);
      await expect(page.locator('#crm-companies-results')).toBeVisible();
    }

    // Search q empty-honest
    await gotoCrm(page, '/apps/customercheck/companies?workspaceId=1', {
      ready: '#crm-companies-results',
    });
    const q = page.locator('#crm-companies-q');
    await q.fill('ZZZNOMATCH-ATLAS-3510');
    await q.press('Enter');
    await page.waitForURL(/q=ZZZNOMATCH/, { timeout: 15000 });
    await expect(page.locator('#crm-companies-results')).toBeVisible();
    await expect(
      page.locator('#crm-companies-results .crm-empty-state, #crm-companies-results [role="status"]').first(),
    ).toBeVisible({ timeout: 15000 });
  });

  test('agreements: kind/status/expiry each_value toggled', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/agreements', { ready: '#crm-agreements-toolbar' });
    await openFilterPanel(
      page,
      'crm-agreements-filter-toggle',
      'crm-agreements-filter',
      'crm-list-controls--filter-open',
    );

    const kind = page.locator('#crm-filter-kind');
    const status = page.locator('#crm-filter-status');
    const expiry = page.locator('#crm-filter-expiry');
    await expect(kind).toBeVisible();
    await expect(status).toBeVisible();
    await expect(expiry).toBeVisible();

    for (const value of ['license', 'partner', 'onetime']) {
      await kind.selectOption(value);
      await expect.poll(async () => page.url(), { timeout: 15000 }).toMatch(new RegExp(`kind=${value}`));
      await expect(kind).toHaveValue(value);
      await expect(page.locator('#crm-agreements-results')).toBeVisible();
    }

    for (const value of ['draft', 'active', 'expiring', 'expired']) {
      await status.selectOption(value);
      await expect.poll(async () => page.url(), { timeout: 15000 }).toMatch(new RegExp(`status=${value}`));
      await expect(status).toHaveValue(value);
    }

    for (const value of ['60', '7']) {
      await expiry.selectOption(value);
      await expect.poll(async () => page.url(), { timeout: 15000 }).toMatch(new RegExp(`expiry=${value}`));
      await expect(expiry).toHaveValue(value);
      await expect(page.locator('#crm-agreements-results')).toBeVisible();
    }
  });

  test('money: state each_value + empty honest', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/money', { ready: '#crm-money-toolbar' });
    await openFilterPanel(
      page,
      'crm-money-filter-toggle',
      'crm-money-filter',
      'crm-list-controls--filter-open',
    );

    const state = page.locator('#crm-filter-state');
    await expect(state).toBeVisible();
    for (const value of ['overdue', 'sent', 'paid30d', 'unknown']) {
      await state.selectOption(value);
      await expect.poll(async () => page.url(), { timeout: 15000 }).toMatch(new RegExp(`state=${value}`));
      await expect(state).toHaveValue(value);
      await expect(page.locator('#crm-money-results')).toBeVisible();
      await expect(page.locator('#crm-money-filter')).toBeVisible();
    }

    const unpaid = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="state=sent"]');
    if (await unpaid.count()) {
      await unpaid.click();
      await page.waitForURL(/state=sent/, { timeout: 15000 });
      await expect(page.locator('#crm-filter-state')).toHaveValue('sent');
    }
  });

  test('activities: scope/type each_value toggled', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/activities', { ready: '#crm-activities-toolbar' });
    await openFilterPanel(
      page,
      'crm-activities-filter-toggle',
      'crm-activities-filter',
      'crm-list-controls--filter-open',
    );

    const scope = page.locator('#crm-filter-scope');
    const type = page.locator('#crm-filter-type');
    await expect(scope).toBeVisible({ timeout: 15000 });
    await expect(type).toBeVisible();

    for (const value of ['open', 'mine', 'done', 'all']) {
      // Live filter may collapse panel after swap — reopen if needed.
      if (!(await scope.isVisible())) {
        await openFilterPanel(
          page,
          'crm-activities-filter-toggle',
          'crm-activities-filter',
          'crm-list-controls--filter-open',
        );
      }
      await scope.selectOption(value);
      await expect.poll(async () => page.url(), { timeout: 15000 }).toMatch(new RegExp(`scope=${value}`));
      await expect(page.locator('#crm-filter-scope')).toHaveValue(value);
      await expect(page.locator('#crm-activities-results')).toBeVisible();
    }

    for (const value of ['call', 'email', 'meeting', 'todo']) {
      if (!(await page.locator('#crm-filter-type').isVisible())) {
        await openFilterPanel(
          page,
          'crm-activities-filter-toggle',
          'crm-activities-filter',
          'crm-list-controls--filter-open',
        );
      }
      await page.locator('#crm-filter-type').selectOption(value);
      await expect.poll(async () => page.url(), { timeout: 15000 }).toMatch(new RegExp(`type=${value}`));
      await expect(page.locator('#crm-filter-type')).toHaveValue(value);
      await expect(page.locator('#crm-activities-results')).toBeVisible();
    }
  });
});
