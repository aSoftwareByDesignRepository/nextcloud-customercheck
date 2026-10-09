// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Company 360 identity — people, address, and company edit must be obvious.
 */
test.describe('Company identity: people, address, change', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('add address inline, add person, change person, axe + mobile targets', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Identity Co ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    const identity = page.locator('#crm-identity');
    await expect(identity).toBeVisible();
    const glanceBeforeIdentity = await page.evaluate(() => {
      const glance = document.getElementById('crm-glance');
      const ident = document.getElementById('crm-identity');
      if (!glance || !ident) return false;
      return !!(glance.compareDocumentPosition(ident) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    expect(glanceBeforeIdentity).toBe(true);
    await expect(identity.locator('#crm-identity-title')).toContainText(`Identity Co ${suffix}`);
    await expect(page.locator('#crm-company-address-display')).toContainText(/No address yet|Noch keine Adresse/i);
    await expect(page.locator('#crm-company-address-display')).toBeVisible();
    await expect(page.locator('#crm-company-address-display')).not.toHaveClass(/crm-sr-only/);
    await expect(page.locator('#crm-identity .crm-identity-title-row .crm-badge')).toBeVisible();
    const identityCluster = await page.evaluate(() => {
      const card = document.getElementById('crm-identity');
      const title = document.getElementById('crm-identity-title');
      const badge = card ? card.querySelector('.crm-identity-title-row .crm-badge') : null;
      const change = document.getElementById('crm-change-company');
      if (!card || !title || !badge || !change) return null;
      const cardBox = card.getBoundingClientRect();
      const titleBox = title.getBoundingClientRect();
      const badgeBox = badge.getBoundingClientRect();
      const changeBox = change.getBoundingClientRect();
      return {
        badgeGap: badgeBox.left - titleBox.right,
        changeFromLeft: changeBox.left - cardBox.left,
        cardWidth: cardBox.width,
      };
    });
    expect(identityCluster).toBeTruthy();
    expect(identityCluster && identityCluster.badgeGap).toBeGreaterThanOrEqual(0);
    expect(identityCluster && identityCluster.badgeGap).toBeLessThan(64);
    expect(identityCluster && identityCluster.changeFromLeft).toBeLessThan((identityCluster?.cardWidth ?? 0) * 0.4);
    await expect(page.locator('#crm-change-company')).toBeVisible();
    await expect(page.locator('#crm-change-company')).toHaveAttribute('aria-controls', 'crm-company-name-form');
    await expect(page.locator('#crm-company-more-settings')).toHaveAttribute('href', /\/companies\/\d+\/edit/);
    await expect(page.locator('#crm-edit-address')).toBeVisible();
    await expect(page.locator('#crm-edit-address')).toContainText(/Add postal address|Postadresse hinzufügen/i);
    await expect(page.locator('#crm-identity [data-crm-trigger="crm-add-person"]')).toHaveCount(0);
    await expect(page.locator('[data-crm-focus-tab="people"]')).toBeVisible();
    await expect(page.locator('#crm-persons')).toBeHidden();
    await page.locator('[data-crm-focus-tab="people"]').click();
    await expect(page.locator('#crm-add-person')).toBeVisible();
    await expect(page.locator('#crm-persons')).toBeVisible();
    await expect(page.locator('#crm-identity-people')).toHaveCount(0);
    await expect(page.locator('#crm-persons .crm-empty-state')).toBeVisible();
    await expect(page.locator('#crm-address-form')).toBeHidden();

    await page.locator('#crm-edit-address').click();
    await expect(page.locator('#crm-address-form')).toBeVisible();
    await expect(page.locator('#crm-edit-address')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#crm-address-street')).toBeFocused();
    await page.locator('#crm-address-street').fill('Holtenauer Straße 267');
    await page.locator('#crm-address-zip').fill('24106');
    await page.locator('#crm-address-city').fill('Kiel');
    await page.locator('#crm-address-form button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('#crm-company-address-display')).toContainText('Holtenauer Straße 267');
    await expect(page.locator('#crm-company-address-display')).toContainText('24106 Kiel');
    await expect(page.locator('#crm-company-address-display')).not.toHaveClass(/crm-sr-only/);
    await expect(page.locator('#crm-company-address-display')).toBeVisible();
    await expect(page.locator('#crm-edit-address')).toContainText(/Change address|Adresse ändern/i);
    await expect(page.locator('#crm-address-form')).toBeHidden();

    await page.locator('#crm-add-person').click();
    const personForm = page.locator('#crm-person-form');
    await expect(personForm).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#crm-address-form')).toBeHidden();
    await personForm.locator('#crm-person-name').fill(`Ada ${suffix}`);
    await personForm.locator('#crm-person-email').fill(`ada-${suffix}@example.com`);
    await personForm.locator('#crm-person-advanced > summary').click();
    await personForm.locator('#crm-person-phone').fill('+49 431 0000');
    await personForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const personCard = page.locator('#crm-persons-table tbody tr').first();
    await expect(page.locator('#crm-persons-table')).toBeVisible();
    await expect(personCard).toBeVisible();
    await expect(personCard).toContainText(`Ada ${suffix}`);
    await expect(personCard).toContainText(`ada-${suffix}@example.com`);
    await expect(personCard).toContainText('+49 431 0000');
    await expect(personCard.locator('[data-crm-edit-person]')).toBeVisible();

    await expect(page.locator('#crm-persons-table .crm-person-more').first()).not.toHaveAttribute('open');
    await page.locator('#crm-persons-table .crm-person-more > summary').first().click();
    await expect(page.locator('#crm-persons-table .crm-person-more').first()).toHaveAttribute('open', '');
    await expect(page.locator('#crm-persons-table .crm-person-more__body').first()).toBeVisible();

    await personCard.locator('[data-crm-edit-person]').click();
    const editForm = page.locator('#crm-person-form');
    await expect(editForm).toBeVisible({ timeout: 10000 });
    await expect(editForm.locator('#crm-person-name')).toHaveValue(`Ada ${suffix}`);
    await expect(editForm.locator('#crm-person-email')).toHaveValue(`ada-${suffix}@example.com`);
    await editForm.locator('#crm-person-email').fill(`ada-new-${suffix}@example.com`);
    await editForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-persons-table tbody tr').first()).toContainText(`ada-new-${suffix}@example.com`);

    const desktopResults = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const desktopSerious = desktopResults.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(desktopSerious, JSON.stringify(desktopSerious, null, 2)).toEqual([]);

    await page.setViewportSize({ width: 375, height: 812 });
    await expect(page.locator('#crm-change-company')).toBeVisible();
    await expect(page.locator('#crm-edit-address')).toBeVisible();
    await expect(page.locator('#crm-add-person')).toBeVisible();
    for (const sel of ['#crm-change-company', '#crm-edit-address', '#crm-add-person']) {
      const box = await page.locator(sel).boundingBox();
      expect(box, sel).toBeTruthy();
      expect(box && box.height, sel).toBeGreaterThanOrEqual(44);
    }

    const mobileResults = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const mobileSerious = mobileResults.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(mobileSerious, JSON.stringify(mobileSerious, null, 2)).toEqual([]);
  });

  test('Change company renames inline; more settings still opens full edit', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Edit Path ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    await page.locator('#crm-change-company').click();
    await expect(page.locator('#crm-company-name-form')).toBeVisible();
    await expect(page.locator('#crm-identity-name')).toBeFocused();
    await expect(page.locator('#crm-person-form')).toBeHidden();
    await page.locator('#crm-identity-name').fill(`Renamed Path ${suffix}`);
    await page.locator('#crm-company-name-form button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-identity-title')).toContainText(`Renamed Path ${suffix}`);

    await page.locator('#crm-change-company').click();
    await page.locator('#crm-company-more-settings').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+\/edit/, { timeout: 30000 });
    await expect(page.locator('#crm-company-name')).toBeVisible();
    await expect(page.locator('#crm-company-street')).toBeVisible();
    await expect(page.locator('#crm-company-address')).toBeVisible();
    await expect(page.locator('#crm-company-advanced')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-company-status')).toBeHidden();
  });

  test('invalid person email stays on the page with an inline error', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Bad Mail ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    await page.locator('[data-crm-focus-tab="people"]').click();
    await page.locator('#crm-add-person').click();
    await expect(page.locator('#crm-person-form')).toBeVisible();
    await page.locator('#crm-person-name').fill(`Pat ${suffix}`);
    await page.locator('#crm-person-email').fill('not-an-email');
    await page.locator('#crm-person-form button[type="submit"]').click();
    await expect(page.locator('#crm-person-form-error')).toBeVisible();
    await expect(page.locator('#crm-person-form-error')).toContainText(/valid email|gültige E-Mail|gültige Mail/i);
    await expect(page.locator('#crm-person-form')).toBeVisible();
    await expect(page.locator('#crm-persons .crm-empty-state')).toBeVisible();
  });

  test('Escape returns focus; people hide on Deals', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Focus Path ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    await page.locator('#crm-change-company').click();
    await expect(page.locator('#crm-company-name-form')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#crm-company-name-form')).toBeHidden();
    await expect(page.locator('#crm-change-company')).toBeFocused();

    await page.locator('#crm-edit-address').click();
    await expect(page.locator('#crm-address-form')).toBeVisible();
    await page.locator('#crm-change-company').click();
    await expect(page.locator('#crm-address-form')).toBeHidden();
    await expect(page.locator('#crm-company-name-form')).toBeVisible();
    await page.locator('#crm-company-name-cancel').click();
    await expect(page.locator('#crm-change-company')).toBeFocused();

    await page.locator('[data-crm-focus-tab="people"]').click();
    await page.locator('#crm-add-person').click();
    await expect(page.locator('#crm-person-form')).toBeVisible();
    await page.locator('#crm-person-name').fill(`Ada ${suffix}`);
    await page.locator('#crm-person-form button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const personRow = page.locator('#crm-persons-table tbody tr').first();
    await expect(personRow).toBeVisible();
    await expect(page.locator('#crm-persons-table thead th')).toHaveCount(3);
    await expect(personRow.locator('.crm-person-more')).not.toHaveAttribute('open');
    await page.locator('[data-crm-focus-tab="deals"]').click();
    await expect(page.locator('#crm-deals')).toBeVisible();
    await expect(page.locator('#crm-persons-table')).toBeHidden();
    await expect(page.locator('#crm-identity-people')).toHaveCount(0);
    await page.locator('[data-crm-focus-tab="people"]').click();
    await expect(page.locator('#crm-persons-table')).toBeVisible();
    await expect(personRow).toBeVisible();
  });
});
