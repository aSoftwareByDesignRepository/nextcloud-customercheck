// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Country combobox — the whole ISO 3166-1 list must be selectable without
 * knowing a code: search by localized name, commit to a 2-letter value,
 * survive round-trip on create and inline 360 edit (BR/"Brasil" regression).
 */
test.describe('Country picker: search, commit, round-trip', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('search selects Brazil on create, persists, re-opens on inline edit; axe', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });

    const search = page.locator('#crm-company-country-search');
    const hidden = page.locator('#crm-company-country');
    const list = page.locator('#crm-company-country-list');

    // ARIA combobox wiring: labelled search input controls the listbox.
    await expect(search).toHaveAttribute('role', 'combobox');
    await expect(search).toHaveAttribute('aria-controls', 'crm-company-country-list');
    await expect(page.locator('label[for="crm-company-country-search"]')).toBeVisible();
    await expect(list).toHaveAttribute('role', 'listbox');
    // The whole ISO list ships in markup — BR must be reachable, not curated.
    expect(await list.locator('li[role="option"]').count()).toBeGreaterThan(200);
    await expect(list.locator('li[data-code="BR"]')).toHaveCount(1);

    // Search filters on code too — 'br' hits BR in every admin locale;
    // non-matches leave the DOM so keyboard nav cannot land on them.
    await search.click();
    await expect(search).toHaveAttribute('aria-expanded', 'true');
    await search.fill('br');
    const remaining = list.locator('li[data-code]');
    expect(await remaining.count()).toBeGreaterThan(0);
    expect(await remaining.count()).toBeLessThan(12);
    await list.locator('li[data-code="BR"]').click();
    await expect(hidden).toHaveValue('BR');
    await expect(search).not.toHaveValue('br');
    await expect(search).toHaveAttribute('aria-expanded', 'false');

    // Keyboard leg: filter to a single-code match, ArrowDown + Enter commits it.
    await page.locator('#crm-company-country-clear').click();
    await expect(hidden).toHaveValue('');
    await search.fill('cz');
    await search.press('ArrowDown');
    await search.press('Enter');
    await expect(hidden).toHaveValue('CZ');
    await expect(search).toHaveAttribute('aria-expanded', 'false');

    // Commit the real country and save the company.
    await page.locator('#crm-company-country-clear').click();
    await search.fill('br');
    await list.locator('li[data-code="BR"]').click();
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Country Co ${suffix}`);
    await page.locator('#crm-company-street').fill('Av. Paulista 1000');
    await page.locator('#crm-company-zip').fill('01310');
    await page.locator('#crm-company-city').fill('São Paulo');
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    // 360 view shows a localized country name (any admin locale), not a code.
    const addrDisplay = page.locator('#crm-company-address-display');
    await expect(addrDisplay).toContainText(/brazil|brasilien|brasil|brazilië|brasile/i);

    // Inline edit reopens the same picker with BR committed.
    await page.locator('#crm-edit-address').click();
    const inlineSearch = page.locator('#crm-address-country-search');
    const inlineHidden = page.locator('#crm-address-country');
    await expect(inlineHidden).toHaveValue('BR');
    await expect(inlineSearch).toHaveValue(/brazil|brasilien|brasil|brazilië|brasile/i);

    // Accessibility audit of the identity/address region.
    const results = await new AxeBuilder({ page })
      .include('#crm-identity')
      .analyze();
    expect(results.violations).toEqual([]);
  });

  test('mobile viewport 375px: no h-scroll, 44px target, dropdown stays on-screen', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });

    // Baseline: the form page must not overflow horizontally on mobile.
    const pageScroll = await page.evaluate(() => ({
      doc: document.documentElement.scrollWidth,
      inner: window.innerWidth,
    }));
    expect(pageScroll.doc).toBeLessThanOrEqual(pageScroll.inner + 1);

    const search = page.locator('#crm-company-country-search');
    const box = await search.boundingBox();
    expect(box.height).toBeGreaterThanOrEqual(44);
    expect(box.width).toBeGreaterThanOrEqual(44);

    // Open + filter: the 250-row dropdown must stay within the viewport
    // (shared chrome caps height via min(12rem, 35vh) <640px).
    await search.click();
    await search.fill('br');
    const list = page.locator('#crm-company-country-list');
    const listBox = await list.boundingBox();
    expect(listBox.x).toBeGreaterThanOrEqual(0);
    expect(listBox.x + listBox.width).toBeLessThanOrEqual(376);
    // Option rows keep the ≥44px touch target and stay scroll-reachable.
    const optionBox = await list.locator('li[data-code="BR"]').boundingBox();
    expect(optionBox.height).toBeGreaterThanOrEqual(44);
    await list.locator('li[data-code="BR"]').click();
    await expect(page.locator('#crm-company-country')).toHaveValue('BR');
    const after = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(after).toBeLessThanOrEqual(376);
  });

  test('viewport sweep 320–1280: no horizontal overflow on the picker page', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    for (const width of [320, 375, 414, 768, 1024, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await gotoCrm(page, '/apps/customercheck/companies/new');
      await page.waitForSelector('#crm-company-country-search', { timeout: 30000 });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, `horizontal overflow at ${width}px`).toBeLessThanOrEqual(1);
      // The combobox must stay openable + filterable at every width.
      const search = page.locator('#crm-company-country-search');
      await search.click();
      await search.fill('br');
      await expect(page.locator('#crm-company-country-list li[data-code="BR"]')).toBeVisible();
      await page.locator('#crm-company-country-clear').click();
    }
  });

  test('settings value-map picker renders and commits inside the learn form', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/settings/mailbox');
    await page.waitForSelector('#crm-value-maps', { timeout: 30000 });
    const details = page.locator('#crm-value-maps');
    if (!(await details.evaluate((el) => el.open))) {
      await details.locator('summary').click();
    }
    const search = page.locator('#crm-vm-canonical-search');
    await expect(search).toBeVisible();
    await search.fill('br');
    await page.locator('#crm-vm-canonical-list li[data-code="BR"]').click();
    await expect(page.locator('#crm-vm-canonical')).toHaveValue('BR');
  });

  test('unmatched free text restores the committed country on blur (forgiving input)', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });

    const search = page.locator('#crm-company-country-search');
    const hidden = page.locator('#crm-company-country');
    // Default DE committed — typing gibberish must not corrupt the value.
    await expect(hidden).toHaveValue('DE');
    await search.fill('atlantis');
    await page.locator('#crm-company-name').click();
    await expect(hidden).toHaveValue('DE');
    await expect(search).not.toHaveValue('atlantis');
    await expect(search).not.toHaveValue('');

    // Exact ISO code typed by hand resolves to the country on blur.
    await search.fill('BR');
    await page.locator('#crm-company-name').click();
    await expect(hidden).toHaveValue('BR');
  });
});
