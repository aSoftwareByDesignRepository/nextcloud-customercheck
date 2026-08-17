// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

test.describe('Who wrote what (emails overview)', () => {
  test('company 360 teaches the workflow and can log a message', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Comms Co ${suffix}`);
    await page.locator('#crm-company-advanced').evaluate((el) => {
      if (el instanceof HTMLDetailsElement) el.open = true;
    });
    await page.locator('#crm-company-slug').fill(`comms-co-${suffix}`);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await page.locator('[data-crm-focus-tab="more"]').click();
    const emails = page.locator('#crm-emails');
    await expect(emails).toBeVisible({ timeout: 15000 });
    await expect(emails.locator('#crm-emails-title')).toContainText(/Who wrote what|Email log|E-Mail-Protokoll|Protokoll/i);
    const howto = emails.locator('.crm-howto__summary');
    if (await howto.count()) {
      await expect(howto).toContainText(/How this works|funktioniert/i);
    }
    await expect(emails.locator('.crm-emails__summary')).toContainText(/Last logged contact|Letzter protokollierter Kontakt|Kontakt/i);

    await page.locator('#crm-identity [data-crm-trigger="crm-add-person"]').click();
    const personForm = page.locator('#crm-person-form');
    await expect(personForm).toBeVisible({ timeout: 10000 });
    await personForm.locator('#crm-person-name').fill('Ada');
    await personForm.locator('#crm-person-email').fill(`ada-${suffix}@example.com`);
    await personForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await page.locator('[data-crm-focus-tab="today"]').click();
    await page.locator('#crm-log-email').click();
    const logForm = page.locator('form[id^="crm-prompt-"]');
    await expect(logForm).toBeVisible({ timeout: 10000 });
    const personField = logForm.locator('[name="personId"]');
    if (await personField.count()) {
      await expect(personField).toBeVisible();
    }
    await expect(logForm.locator('[name="subject"]')).toBeVisible();
    await expect(logForm.locator('[name="fromAddr"]')).toHaveCount(0);
    await logForm.locator('[name="subject"]').fill('Renewal follow-up');
    const snippet = logForm.locator('[name="snippet"]');
    if (await snippet.count()) {
      const advanced = logForm.locator('details.crm-advanced');
      if (await advanced.count()) {
        await advanced.locator('summary').click();
      }
      if (await snippet.isVisible()) {
        await snippet.fill('Promised quote next week');
      }
    }
    await logForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await page.locator('[data-crm-focus-tab="more"]').click();
    await expect(page.locator('#crm-emails .crm-emails__summary-value')).toContainText(/Ada|ada-/i);
    await expect(page.locator('#crm-emails table tbody tr').first()).toContainText('Renewal follow-up');
  });
});
