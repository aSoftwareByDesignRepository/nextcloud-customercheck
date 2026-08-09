/**
 * AC-CC-21…23 — Contacts soft-link surfaces on company 360.
 */
import { test, expect } from '@playwright/test';

test.describe('Contacts link (Command Center)', () => {
  test('people roster exposes last touch and address-book columns', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Contacts Co ${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await page.locator('#crm-persons').scrollIntoViewIfNeeded();
    await page.locator('#crm-add-person').click({ force: true });
    const form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await form.locator('[name="name"]').fill(`Ada ${suffix}`);
    await form.locator('[name="email"]').fill(`ada-${suffix}@example.com`);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('#crm-persons-table')).toBeVisible();
    await expect(page.locator('#crm-persons-table thead')).toContainText(/Last touch|Letzter Kontakt/i);
    await expect(page.locator('#crm-persons-table thead')).toContainText(/Address book|Adressbuch/i);
    await expect(page.locator('#crm-persons-table tbody tr').first()).toContainText(/No touch yet|Noch kein Kontakt/i);
    await expect(page.locator('#crm-persons-table tbody tr').first()).toContainText(/Not linked|Nicht verknüpft/i);

    // Cockpit Log email is wired (AC communication actions).
    await expect(page.locator('#crm-log-email[data-crm-log-email]')).toBeVisible();
  });

  test('platform strip and contacts suggest degrade cleanly', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/apps/customercheck/companies');
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    let openRow = page.locator('#crm-main-content table tbody tr .crm-table-actions a.crm-btn--primary').first();
    if ((await openRow.count()) === 0) {
      test.skip(true, 'No company yet');
    }
    await openRow.click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    await expect(page.locator('#crm-platform-links')).toBeAttached();
    await expect(page.locator('#crm-persons')).toBeVisible();
  });
});
