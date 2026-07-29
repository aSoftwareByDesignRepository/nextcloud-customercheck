// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * DutyCheck-style Quick start how-tos on CustomerCheck pages.
 * Page header leads stay visible after tips are dismissed (data origin / why).
 */
test.describe('Page Quick start how-tos', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Today and Companies show dismissible Quick start cards', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    // Fresh tips for this browser context
    await page.addInitScript(() => {
      try {
        Object.keys(localStorage)
          .filter((k) => k.startsWith('crm:hint:'))
          .forEach((k) => localStorage.removeItem(k));
      } catch (_) { /* ignore */ }
    });

    await page.goto('/apps/customercheck/today?workspaceId=1');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const todayTips = page.locator('#crm-quickstart-today');
    await expect(todayTips).toBeVisible({ timeout: 15000 });
    await expect(todayTips.getByRole('heading', { name: /Quick start|Schnellstart/i })).toBeVisible();
    await expect(todayTips).toContainText(/Scan the queues|Queues prüfen|Take one action|Eine Aktion/i);
    await expect(page.locator('.crm-page-header__lead')).toContainText(/workspace|Arbeitsbereich/i);
    await todayTips.getByRole('button', { name: /Hide tips|Tipps ausblenden/i }).click();
    await expect(todayTips).toBeHidden();
    // Provenance must survive tip dismiss
    await expect(page.locator('.crm-page-header__lead')).toContainText(/workspace|Arbeitsbereich|chase|Chase/i);

    await page.goto('/apps/customercheck/companies?workspaceId=1');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const companiesTips = page.locator('#crm-quickstart-companies');
    await expect(companiesTips).toBeVisible({ timeout: 15000 });
    await expect(companiesTips).toContainText(/Search or filter|Suchen oder filtern|customer registry|Kundenverzeichnis/i);
    await expect(page.locator('.crm-page-header__lead')).toContainText(/workspace|Arbeitsbereich|registry|Verzeichnis/i);
  });

  test('Team quickstart explains coaching and capacity', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.addInitScript(() => {
      try {
        Object.keys(localStorage)
          .filter((k) => k.startsWith('crm:hint:'))
          .forEach((k) => localStorage.removeItem(k));
      } catch (_) { /* ignore */ }
    });
    await page.goto('/apps/customercheck/team?workspaceId=1');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const tips = page.locator('#crm-quickstart-team');
    await expect(tips).toBeVisible({ timeout: 15000 });
    await expect(tips).toContainText(/who needs help|wer Hilfe braucht|Cover while away|Vertretung|Move or escalate|Verschieben oder eskalieren/i);
    await expect(page.locator('.crm-page-header__lead')).toContainText(/green|Grün|amber|Amber/i);
    await tips.getByRole('button', { name: /Hide tips|Tipps ausblenden/i }).click();
    await expect(tips).toBeHidden();
    await expect(page.locator('.crm-page-header__lead')).toContainText(/green|Grün|amber|Amber|workspace|Arbeitsbereich/i);
  });
});
