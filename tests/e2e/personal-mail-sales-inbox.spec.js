// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Personal email sync / Sales Inbox — CUC-PERSONAL-MAIL-1 FAC + a11y smoke.
 */
test.describe('Personal email sync / Sales Inbox', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Sales Inbox shell: Bachus tabs, disclosure, empty CTA, axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/sales-inbox');
    await page.waitForSelector('#crm-sales-inbox-root[data-crm-bachus-mail="1"]', { timeout: 30000 });

    await expect(page.locator('#crm-sales-inbox-disclosure')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-disclosure')).not.toBeEmpty();
    // Account filter stays hidden until multiple accounts exist (Bachus).
    await expect(page.locator('#crm-sales-inbox-account-wrap')).toBeHidden();
    await expect(page.locator('#crm-sales-tab-mine')).toBeVisible();
    await expect(page.locator('#crm-sales-tab-team')).toBeVisible();
    await expect(page.locator('#crm-sales-tab-unmatched')).toBeVisible();
    await expect(page.locator('#tools-subnav a[href*="/settings/my-email"]')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-settings')).toHaveCount(0);
    await expect(page.locator('#crm-sales-inbox-empty-cta')).toHaveCount(1);
    await expect(page.locator('#crm-sales-compose-cc-toggle')).toHaveCount(1);
    await expect(page.locator('#crm-sales-compose-to')).toHaveCount(1);
    await expect(page.locator('label[for="crm-sales-compose-to"]')).toBeAttached();
    // Must not use ambiguous "To" (DE "Bis"); compose label is "Send to".
    const toLabel = await page.locator('label[for="crm-sales-compose-to"]').innerText();
    expect(toLabel.toLowerCase()).not.toMatch(/^bis$/i);
    await expect(page.locator('#crm-sales-compose-hint')).toBeAttached();
    // Shared-folders banner is hidden until API returns shared folders (US-004 AC-3).
    await expect(page.locator('#crm-sales-inbox-shared-folders')).toHaveCount(1);
    await expect(page.locator('#crm-sales-inbox-auth-alert')).toHaveCount(1);

    await page.locator('#crm-sales-tab-team').click();
    await expect(page.locator('#crm-sales-tab-team')).toHaveAttribute('aria-selected', 'true');
    await page.locator('#crm-sales-tab-unmatched').click();
    await expect(page.locator('#crm-sales-tab-unmatched')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-assign-panel')).toHaveCount(0);
    await expect(page.locator('#crm-sales-inbox-list-tools')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-q')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-mail-filters')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#crm-sales-inbox-empty, #crm-sales-inbox-list .crm-mail-thread-row').first()).toBeVisible({ timeout: 20000 });
    if (await page.locator('#crm-sales-inbox-list .crm-mail-thread-row').count()) {
      await expect(page.locator('#crm-sales-inbox-cols')).toBeVisible();
      await expect(page.locator('#crm-sales-inbox-cols [data-crm-inbox-sort="sent"]')).toBeVisible();
    }
    await expect(page.locator('#crm-sales-inbox-list')).toBeVisible();
    await page.locator('#crm-sales-tab-mine').click();
    await expect(page.locator('#crm-sales-tab-mine')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-list-tools')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-mail-filters')).toBeHidden();

    await expect(page.locator('#crm-sales-inbox-list')).toHaveAttribute('role', 'listbox');

    const results = await new AxeBuilder({ page })
      .include('#crm-sales-inbox-root')
      .disableRules(['color-contrast'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });

  test('My email settings: Bachus OAuth-first strip present', async ({ page }) => {
    await page.setViewportSize({ width: 1100, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/settings/my-email#crm-ws-personal-mail-mine');
    await page.waitForSelector('#crm-ws-personal-mail-mine, #section-my-email', { timeout: 30000 });

    const mine = page.locator('#crm-ws-personal-mail-mine');
    await expect(mine).toBeVisible();

    if (await page.locator('#crm-personal-mail-connect-form').count()) {
      await expect(page.locator('#crm-personal-mail-connect-form')).toHaveAttribute('data-crm-bachus-pm', '1');
      await expect(page.locator('#crm-pm-dsgvo-wrap')).toBeAttached();
      await expect(page.locator('#crm-personal-mail-imap')).toBeAttached();
      // When OAuth buttons are present, IMAP stays collapsed; when none, IMAP opens so Connect is visible.
      const oauthVisible = await page.locator('#crm-personal-mail-oauth:not([hidden]) button').count();
      if (oauthVisible > 0) {
        await expect(page.locator('#crm-personal-mail-imap')).not.toHaveAttribute('open');
      } else if (await page.locator('#crm-personal-mail-connect-form:not([hidden])').count()) {
        await expect(page.locator('#crm-personal-mail-imap')).toHaveAttribute('open');
      }
    }
  });

  test('OAuth error query is consumed by settings JS contract', async ({ page }) => {
    await page.setViewportSize({ width: 900, height: 700 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/settings/my-email');
    await page.waitForLoadState('networkidle');
    const { readFileSync } = await import('node:fs');
    const { join, dirname } = await import('node:path');
    const { fileURLToPath } = await import('node:url');
    const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('consumePersonalMailQueryFeedback');
    expect(js).toContain('personalMailError');
    const components = readFileSync(join(root, 'js/common/components.js'), 'utf8');
    expect(components).toMatch(/typedField\.value\.trim\(\)\.toUpperCase\(\)/);
  });

  test('share disclosure JS contract requires checkbox ack', async ({ page }) => {
    await page.setViewportSize({ width: 900, height: 700 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/settings/mailbox');
    await page.waitForLoadState('networkidle');

    const hasSettingsScript = await page.evaluate(() => {
      return Array.from(document.scripts).some((s) => {
        const src = String(s.src || '');
        return src.includes('settings') || src.includes('customercheck');
      });
    });
    expect(hasSettingsScript).toBeTruthy();

    // Source contract from disk (NC may gate raw /js/pages/*.js behind auth HTML).
    const { readFileSync } = await import('node:fs');
    const { join, dirname } = await import('node:path');
    const { fileURLToPath } = await import('node:url');
    const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('requireCheckbox: true');
    expect(js).toContain('I understand these emails will be visible to teammates');
    expect(js).toContain('requireTypedConfirm: !!inboxSent');
    expect(js).toContain("typedConfirmPhrase: 'SHARE'");
  });
});
