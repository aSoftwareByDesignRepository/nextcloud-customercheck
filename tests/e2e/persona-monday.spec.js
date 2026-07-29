// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Persona Monday path — Pulse → Brief → Today → Team (product §2.1 / §21.2).
 */
test.describe('Persona Monday operating path', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  async function openPersona(page, path) {
    await page.goto('/apps/customercheck' + path);
    await page.waitForSelector('#app-content, #crm-main-content, .crm-page-stack', { timeout: 30000 });
    await expect(page.locator('.crm-page-stack').first()).toBeVisible({ timeout: 15000 });
  }

  test('Pulse → Brief → Today → Team surfaces load with granny-readable chrome', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await openPersona(page, '/pulse');
    await expect(page.locator('#app-content.crm-app')).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('heading', { name: /At a glance|Auf einen Blick/i }).first()).toBeVisible({ timeout: 15000 });
    // Legal honesty is not Pulse chrome — no tax/GoBD lectures on the ops glance.
    await expect(page.locator('[data-crm-persona-mount="pulse"]')).not.toContainText(/GoBD|Ops overview — not tax|tax filing|Steuererklärung|Not available yet|Noch nicht verfügbar|honesty_blocked|Health metrics/i);
    await expect(page.locator('.crm-page-header')).not.toContainText(/GoBD|tax filing|Steuererklärung|not tax/i);

    await page.goto('/apps/customercheck/money');
    await page.waitForSelector('.crm-page-header', { timeout: 30000 });
    await expect(page.locator('.crm-page-header')).not.toContainText(/GoBD|tax filing|Steuererklärung|day-to-day ops|Ops overview/i);
    await expect(page.locator('.crm-page-header__lead')).toContainText(/invoice states|Rechnungszustände/i);
    // Wait for workspace race to settle — Pulse must not stay on "Select a workspace".
    await expect(page.getByText(/Select a workspace first|Wähle zuerst einen Arbeitsbereich/i)).toHaveCount(0, { timeout: 15000 });
    // NC 34 removed OC.Util.escapeHTML — persona boot must not crash.
    await expect(page.locator('[data-crm-persona-mount="pulse"] [role="alert"]')).toHaveCount(0);
    await expect(page.locator('[data-crm-persona-mount="pulse"]')).not.toContainText(/escapeHTML is not a function/i);
    await expect(page.locator('#crm-pulse-metrics, [data-crm-persona-mount="pulse"] .crm-today-stat').first()).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-crm-persona-mount="pulse"]')).toContainText(/Attention|Aufmerksamkeit|Companies|Kunden|Missing links|Fehlende Verknüpf/i);

    await openPersona(page, '/strategy');
    await expect(page.locator('[data-crm-persona-mount="strategy"] [role="alert"]')).toHaveCount(0, { timeout: 15000 });
    await expect(page.locator('[data-crm-persona-mount="strategy"]')).not.toContainText(/escapeHTML is not a function/i);

    await openPersona(page, '/brief');
    const briefBody = await page.locator('.crm-page-stack').first().innerText();
    expect(briefBody.toLowerCase()).not.toMatch(/expected cash.*pipeline|pipeline.*expected cash/);

    await openPersona(page, '/today');

    await openPersona(page, '/team');
    await expect(page.locator('#capacity, [data-crm-persona-mount="team"], .crm-page-stack').first()).toBeVisible();
    await expect(page.getByRole('heading', { name: /Who needs help|Wer braucht Hilfe/i }).first()).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('heading', { name: /Capacity tools|Kapazitäts-Werkzeuge/i }).first()).toBeVisible();
    await expect(page.locator('[data-crm-persona-mount="team"] [role="alert"]')).toHaveCount(0);
    await expect(page.locator('[data-crm-persona-mount="team"]')).not.toContainText(/No team rows to show|Keine Team-Zeilen/i);
    await expect(page.locator('[data-crm-team-capacity]')).toBeVisible();
    // Capacity tools mount after board fetch — cover / reassign / escalate cards for coaches.
    await expect(page.locator('[data-crm-team-capacity]')).toContainText(/Cover while away|Vertretung|Move work|Arbeit an|Escalate|eskalieren|viewing your own row|eigene Zeile/i, { timeout: 20000 });
    await expect(page.locator('[data-crm-persona-mount="team"]')).toContainText(/People|Personen|Need help|Brauchen Hilfe|No teammates|keine Teammitglieder|On track|Im Plan|Needs help|Braucht Hilfe/i, { timeout: 20000 });

    await page.goto('/apps/customercheck/workload');
    await page.waitForURL(/\/apps\/customercheck\/team/, { timeout: 15000 });
    await expect(page.url()).toMatch(/team/);
  });

  test('My Accounts and mail triage expose shared disclosure', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await openPersona(page, '/my-accounts');

    await openPersona(page, '/mail-triage');
    await expect(page.getByText(/shared mailbox|gemeinsamen Postfach/i).first()).toBeVisible({ timeout: 15000 });
  });
});
