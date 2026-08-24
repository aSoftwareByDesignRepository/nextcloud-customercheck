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
      await expect(moneyEmpty.getByRole('link', { name: /Open agreements|Vereinbarungen|Open companies|Unternehmen|Show all money|Alles Geld/i }).first()).toBeVisible();
    }
    await axeMain(page);

    await page.goto('/apps/customercheck/money?state=overdue');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const filteredEmpty = page.locator('.crm-empty-state').filter({ hasText: /Nothing in this group|Nichts in dieser Gruppe/i });
    if (await filteredEmpty.count()) {
      await expect(filteredEmpty.getByRole('link', { name: /Show all money|Alles Geld|Open agreements|Vereinbarungen/i }).first()).toBeVisible();
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
    await page.waitForSelector('#crm-import-paste-details', { timeout: 30000 });
    await page.locator('#crm-import-paste-details').evaluate((el) => { el.open = true; });
    await page.waitForSelector('#crm-import-csv', { timeout: 30000 });
    const ph = await page.locator('#crm-import-csv').getAttribute('placeholder');
    expect(ph || '').not.toMatch(/customerId,companyName/);
    await expect(page.locator('.crm-import-tile').first()).toBeVisible();
    await expect(page.locator('#crm-import-drop')).toBeVisible();
    await expect(page.locator('#crm-import-file')).toBeAttached();

    // Radio sits beside title (same row), not stacked above it.
    const aligned = await page.locator('.crm-import-tile').first().evaluate((tile) => {
      const radio = tile.querySelector('input[type="radio"]');
      const title = tile.querySelector('.crm-import-tile__title');
      if (!(radio instanceof HTMLElement) || !(title instanceof HTMLElement)) return false;
      const r = radio.getBoundingClientRect();
      const t = title.getBoundingClientRect();
      const sameRow = Math.abs((r.top + r.height / 2) - (t.top + t.height / 2)) < 12;
      const leftOfTitle = r.right <= t.left + 2;
      const style = getComputedStyle(tile);
      return sameRow && leftOfTitle && style.flexDirection === 'row';
    });
    expect(aligned).toBe(true);

    await expect(page.locator('#crm-import-accept-unambiguous')).toBeAttached();
    await expect(page.locator('#crm-import-accept-unambiguous')).toContainText(/Import now|Jetzt importieren|Importar|Importer|Importeren|Importuj|Importa/i);
    await expect(page.locator('#crm-import-scan')).toContainText(/Check my list|Liste prüfen|Vorschau|prüfen/i);
    await expect(page.locator('#crm-import-meta')).not.toContainText(/%s/);
    await expect(page.locator('nav.crm-breadcrumb')).not.toContainText(/Semmelbrösel/i);
    await axeMain(page);

    await page.goto('/apps/customercheck/team');
    await page.waitForSelector('[data-crm-persona-mount="team"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="team"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });
    await expect(page.getByText(/Open chase|Commit pipeline/i)).toHaveCount(0);
    const shell = page.locator('details.crm-team-capacity-shell#capacity, details.crm-team-capacity-shell').first();
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
    await expect(page.locator('#crm-page-actions #crm-my-accounts-add, #crm-my-accounts-add').first()).toBeVisible();
    await expect(page.locator('#crm-page-actions #crm-my-accounts-add, #crm-my-accounts-add').first()).toContainText(/Add customer|Kunde hinzufügen|Kunden hinzufügen/i);
    await axeMain(page);

    // Workspace settings are split (SETTINGS-PAGES-STANDARD): mailbox first, promises on /settings/promises.
    await page.goto('/apps/customercheck/settings/mailbox');
    await page.waitForSelector('#section-workspace-mailbox', { timeout: 30000 });
    await expect(page.getByText(/^Slug$/i)).toHaveCount(0);
    await expect(page.getByText(/Lead webhook|Website order webhook|Lead reply SLA/i)).toHaveCount(0);
    await expect(page.getByText(/First reply within|Erste Antwort/i).first()).toBeVisible();
    await expect(page.locator('#section-workspace-mailbox.crm-mailbox-settings')).toBeVisible();
    await expect(page.locator('#crm-ws-limits-card')).toBeVisible();
    await expect(page.locator('#crm-ws-mailbox-card')).toBeVisible();
    await expect(page.locator('#crm-mailbox-form.crm-form--stack')).toBeVisible();
    // Bachus: primary Inbox fieldset + Mail servers under progressive disclosure.
    await expect(page.locator('#crm-mailbox-form > fieldset.crm-fieldset')).toHaveCount(1);
    await expect(page.locator('#crm-mailbox-servers')).toBeVisible();
    await expect(page.locator('#crm-mailbox-servers')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-mailbox-form fieldset.crm-fieldset')).toHaveCount(2);
    await expect(page.locator('#crm-mailbox-save')).toBeDisabled();
    await expect(page.locator('#crm-mailbox-form')).toHaveAttribute('aria-busy', 'false', { timeout: 30000 });
    const settingsNav = page.locator('#crm-settings-pages');
    await expect(settingsNav.locator('a[href*="/settings/mailbox"]')).toBeVisible();
    await expect(settingsNav.locator('a[href*="/settings/my-email"]')).toBeVisible();
    await expect(settingsNav.locator('a[href*="/settings/promises"]')).toBeVisible();
    const hasPresent = await page.evaluate(() => !!(window.CustomerCheckMailboxSettingsPresent && typeof window.CustomerCheckMailboxSettingsPresent.buildMailboxListRows === 'function'));
    expect(hasPresent).toBeTruthy();
    await axeMain(page);

    await page.goto('/apps/customercheck/settings/promises');
    await page.waitForSelector('#section-promises-roll-call', { timeout: 30000 });
    await expect(page.locator('#section-promises-heading')).toBeVisible();
    await expect(page.locator('#section-promises-heading')).toContainText(/Weekly promise check|Wöchentliche Zusagen/i);
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

  test('List tables expose an obvious Open/Edit button on every data row', async ({ page }) => {
    test.setTimeout(120000);
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    async function assertRowPrimaryWhenPresent(path, emptyOk) {
      await page.goto(path, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#crm-main-content', { timeout: 30000 });
      if (path.includes('/pipeline')) {
        const tableView = page.locator('[data-crm-pipeline-view="table"]');
        if (await tableView.count()) {
          await tableView.click();
          await expect(page.locator('#crm-pipeline-table')).toBeVisible({ timeout: 10000 });
          await expect(page.locator('#crm-pipeline-table')).not.toHaveAttribute('hidden', '');
        }
      }
      const dataRows = page.locator('table.table:visible tbody tr').filter({
        has: page.locator('td.crm-table-actions-col, td.actions-cell, .crm-table-actions'),
      });
      const n = await dataRows.count();
      if (n === 0) {
        if (emptyOk) {
          const emptyChrome = page.locator(
            '#crm-main-content .crm-empty-state:visible, #crm-main-content .crm-callout--info:visible, #crm-pipeline-new-deal:visible, #crm-pipeline-empty-new:visible',
          );
          await expect(emptyChrome.first()).toBeVisible({ timeout: 10000 });
          return;
        }
      }
      for (let i = 0; i < Math.min(n, 5); i += 1) {
        const row = dataRows.nth(i);
        // Skip pure empty-state colspan rows (count is sync — avoid auto-wait traps).
        const emptyCount = await row.locator('.crm-empty-state, .crm-callout').count();
        if (emptyCount) continue;
        const primary = row.locator('.crm-table-actions .crm-btn--primary').first();
        await expect(primary, `missing primary CTA on ${path} row ${i}`).toBeVisible();
        const box = await primary.boundingBox();
        expect(box, `primary CTA not laid out on ${path}`).toBeTruthy();
        expect(box.height).toBeGreaterThanOrEqual(36);
      }
      await axeMain(page);
    }

    await assertRowPrimaryWhenPresent('/apps/customercheck/companies', true);
    await assertRowPrimaryWhenPresent('/apps/customercheck/agreements', true);
    await assertRowPrimaryWhenPresent('/apps/customercheck/money', true);
    await assertRowPrimaryWhenPresent('/apps/customercheck/activities', true);
    await assertRowPrimaryWhenPresent('/apps/customercheck/pipeline', true);
    await assertRowPrimaryWhenPresent('/apps/customercheck/today', true);
  });
});
