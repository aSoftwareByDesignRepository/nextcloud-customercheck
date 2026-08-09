// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Bachus Import journey — paste → preview stats → import ready → companies CTA.
 * Proves broken-%s meta is gone, tiles work, and axe stays clean.
 */
test.describe('Bachus Import effortless journey', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  async function axeMain(page) {
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  }

  test('CSV paste preview shows human stats and imports a ready row', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/import');
    await page.waitForSelector('#crm-import-root', { timeout: 30000 });

    await expect(page.locator('#crm-import-source-title')).toContainText(/Where should we look|Wo sollen wir|schauen/i);
    await expect(page.locator('.crm-import-tile').first()).toBeVisible();
    await expect(page.locator('#crm-import-drop')).toBeVisible();
    await expect(page.locator('#crm-import-file')).toBeAttached();
    await expect(page.locator('#crm-import-review')).toBeHidden();
    await expect(page.getByText(/%s rows/i)).toHaveCount(0);

    const suffix = Date.now().toString(36);
    const company = `Bachus Import ${suffix}`;
    await page.locator('#crm-import-csv').fill(company);
    await page.locator('#crm-import-scan').click();

    await expect(page.locator('#crm-import-review')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-import-stats')).toBeVisible();
    await expect(page.locator('#crm-import-stat-ready')).not.toHaveText('0');
    await expect(page.locator('#crm-import-meta')).not.toContainText(/%s/);
    await expect(page.locator('#crm-import-table')).toContainText(company);
    await expect(page.locator('#crm-import-accept-unambiguous')).toBeEnabled();
    await expect(page.locator('#crm-import-accept-unambiguous')).toContainText(/Import|Übernehmen|importieren/i);

    await axeMain(page);

    await page.locator('#crm-import-accept-unambiguous').click();
    await expect(page.locator('#crm-import-summary')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-import-summary-body')).toContainText(/Added|Hinzugefügt|Linked|Verknüpft|Open companies|Unternehmen/i);
    await expect(page.locator('#crm-import-summary-body a.crm-btn--primary')).toBeVisible();
    await axeMain(page);
  });

  test('JSON from mail shape previews and imports', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/import');
    await page.waitForSelector('#crm-import-csv', { timeout: 30000 });

    const suffix = Date.now().toString(36);
    const company = `Mail Json ${suffix}`;
    const payload = JSON.stringify({
      rows: [{
        companyName: company,
        contactEmail: `mail-${suffix}@example.test`,
        product: 'azc',
        mobileSeats: 2,
        validUntil: '2027-12-31',
        amountEurCents: 12000,
      }],
    });
    await page.locator('#crm-import-csv').fill(payload);
    await page.locator('#crm-import-scan').click();

    await expect(page.locator('#crm-import-review')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-import-stats')).toBeVisible();
    await expect(page.locator('#crm-import-table')).toContainText(company);
    await expect(page.locator('#crm-import-accept-unambiguous')).toBeEnabled();
    await page.locator('#crm-import-accept-unambiguous').click();
    await expect(page.locator('#crm-import-summary')).toBeVisible({ timeout: 30000 });
    await axeMain(page);
  });

  test('sales-log CSV header with contactEmail previews', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/import');
    await page.waitForSelector('#crm-import-csv', { timeout: 30000 });

    const suffix = Date.now().toString(36);
    const company = `Sales Log ${suffix}`;
    const csv = [
      'product,customerId,companyName,contactEmail,invoiceId,mobileSeats,terminalDevices,validUntil,amountEur',
      `azc,sales-log-${suffix},${company},ops-${suffix}@example.test,INV-${suffix},3,1,2027-06-01,88.00`,
    ].join('\n');
    await page.locator('#crm-import-csv').fill(csv);
    await page.locator('#crm-import-scan').click();

    await expect(page.locator('#crm-import-review')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-import-table')).toContainText(company);
    await expect(page.locator('#crm-import-stat-ready')).not.toHaveText('0');
    await expect(page.locator('#crm-import-errors')).toBeHidden();
    await axeMain(page);
  });

  test('narrow viewport keeps source tiles and actions usable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/import');
    await page.waitForSelector('#crm-import-csv', { timeout: 30000 });

    const tile = page.locator('.crm-import-tile').first();
    await expect(tile).toBeVisible();
    const box = await tile.boundingBox();
    expect(box).toBeTruthy();
    expect(box.height).toBeGreaterThanOrEqual(44);

    const scan = page.locator('#crm-import-scan');
    await expect(scan).toBeVisible();
    const scanBox = await scan.boundingBox();
    expect(scanBox).toBeTruthy();
    expect(scanBox.height).toBeGreaterThanOrEqual(44);

    await page.locator('#crm-import-csv').fill('Mobile Import Co');
    await scan.click();
    await expect(page.locator('#crm-import-review')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-import-accept-unambiguous')).toBeVisible();
    await axeMain(page);
  });
});
