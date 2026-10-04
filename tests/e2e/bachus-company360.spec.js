// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';
import { dismissNextStepNudge, fillNextStepDueDate, submitNewDealAndDismiss, waitForNewDealForm } from './helpers/new-deal-form.js';

/**
 * Bachus company 360 — create → detail → person → deal with forgiving forms + a11y.
 */
test.describe('Bachus company 360 effortless flows', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('create company, coach, add person (name only), add deal (title only), date picker', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    await expect(page.locator('#crm-company-name-help')).toBeVisible();
    // Literal jargon ban: "Slug" is a fixed technical term — locale-independent.
    await expect(page.getByText('Slug', { exact: true })).toHaveCount(0);
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Bachus Easy ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await expect(page.locator('#crm-welcome-coach')).toBeVisible();
    await expect(page.locator('#crm-welcome-coach')).toContainText(/Next steps|Nächste Schritte/i);
    await expect(page.locator('#crm-welcome-coach .crm-welcome-coach__step')).toHaveCount(3);
    await expect(page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-person"]')).toBeVisible();
    await expect(page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-deal"]')).toBeVisible();
    await expect(page.locator('#crm-welcome-coach [data-crm-trigger="crm-schedule-callback"]')).toBeVisible();
    await expect(page.locator('#crm-persons .crm-empty-state [data-crm-trigger="crm-add-person"]')).toHaveCount(0);
    await expect(page.locator('#crm-360-role-title')).toHaveCount(0);
    // One coach only — no duplicate Schnellstart card above the welcome coach.
    await expect(page.locator('#crm-quickstart-company-detail')).toHaveCount(0);

    // Coach CTA opens Add person (name required only).
    await page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-person"]').click();
    let form = page.locator('#crm-person-form');
    await expect(form).toBeVisible({ timeout: 10000 });
    await expect(form.locator('#crm-person-name')).toBeVisible();
    await expect(form.locator('#crm-person-email')).toBeVisible();
    await expect(form.locator('#crm-person-advanced')).toBeVisible();
    await expect(form.locator('#crm-person-advanced')).not.toHaveAttribute('open', '');
    await expect(form.locator('#crm-person-phone')).toBeAttached();
    await form.locator('#crm-person-name').fill(`Pat ${suffix}`);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-persons-table')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#crm-persons-table')).toContainText(`Pat ${suffix}`);
    await expect(page.locator('#crm-persons-table thead th')).toHaveCount(3);
    await expect(page.locator('#crm-persons-table thead th', { hasText: /Address book|Adressbuch/i })).toHaveCount(0);
    await expect(page.locator('#crm-persons-table .crm-person-more').first()).toBeVisible();
    await expect(page.locator('#crm-persons-table .crm-person-more').first()).not.toHaveAttribute('open');
    await page.locator('#crm-persons-table .crm-person-more > summary').first().click();
    await expect(page.locator('#crm-persons-table .crm-person-more').first()).toHaveAttribute('open', '');
    await expect(page.locator('#crm-persons-table .crm-person-more__body').first()).toContainText(/Address book|Adressbuch|Last touch|Letzter Kontakt/i);
    await expect(page.locator('#crm-persons-table details.crm-more-menu')).toHaveCount(0);
    await expect(page.locator('#crm-persons-table .crm-table-actions .crm-btn--primary').first()).toBeVisible();
    await expect(page.locator('#crm-persons-table [data-crm-buying-role]').first()).toBeVisible();

    await expect(page.locator('#crm-deals .crm-card__lead')).toContainText(/Quotes and opportunities|Angebote und Chancen/i);
    await expect(page.locator('#crm-deals .crm-card__lead')).not.toContainText(/Pipeline opportunities/i);
    await expect(page.locator('#crm-glance-summary')).not.toContainText(/^Gap$/i);
    await expect(page.locator('#crm-glance')).toBeVisible();
    // SF-01 (coverage gap → Money warning) is proven in vitest + Zeus contracts;
    // gap is key/partner-only — standard-tier create stays neutral by design.

    // Deal: title-first in shared new-deal dialog; notes in Notes section.
    await page.locator('[data-crm-focus-tab="today"]').click();
    await page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-deal"]').click();
    form = await waitForNewDealForm(page);
    await expect(form.locator('[name="title"]')).toBeVisible();
    await expect(form.locator('[name="notes"]')).toBeVisible();
    await expect(form.locator('[name="personId"]')).toBeVisible();
    await expect(form.locator('[name="leadSource"]')).toBeVisible();
    await expect(form.locator('details.crm-new-deal__more')).not.toHaveAttribute('open', '');
    await expect(form.locator('[name="valueEur"]')).toBeAttached();
    await form.locator('[name="title"]').fill(`Quote ${suffix}`);
    await form.locator('[name="notes"]').fill(`Team note ${suffix}`);
    await submitNewDealAndDismiss(page, form);
    await page.locator('[data-crm-focus-tab="deals"]').click();
    await expect(page.locator('#crm-deals-table')).toContainText(`Quote ${suffix}`, { timeout: 15000 });
    await expect(page.locator('#crm-deals-table')).toContainText(`Team note ${suffix}`);
    await expect(page.locator('#crm-welcome-coach')).toHaveCount(0);

    // Edit deal works on 360 (notes persist) — not a dead button.
    await page.locator('[data-crm-focus-tab="deals"]').click();
    await expect(page.locator('#crm-deals-table')).toBeVisible({ timeout: 10000 });
    await page.locator('#crm-deals-table [data-crm-edit-chase]').first().click();
    form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await expect(form.locator('[name="notes"]')).toHaveValue(`Team note ${suffix}`);
    await form.locator('[name="notes"]').fill(`Updated note ${suffix}`);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-deals-table')).toContainText(`Updated note ${suffix}`, { timeout: 15000 });

    // Stop reminders lives under More — one primary action per row.
    await expect(page.locator('#crm-deals-table .crm-table-actions > [data-crm-dismiss-followups]')).toHaveCount(0);
    await expect(page.locator('#crm-deals-table .crm-more-menu [data-crm-dismiss-followups]')).toHaveCount(1);

    // Reminder due date uses native date picker; type stays under More.
    await page.locator('[data-crm-focus-tab="today"]').click();
    await page.locator('#crm-add-activity').click();
    form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await expect(form.locator('details.crm-advanced')).not.toHaveAttribute('open', '');
    await expect(form.locator('[name="type"]')).toBeAttached();
    const today = new Date().toISOString().slice(0, 10);
    await form.locator('[name="title"]').fill(`Call ${suffix}`);
    await fillNextStepDueDate(form, today);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-activities')).toBeVisible();
    const row = page.locator('#crm-activities .crm-todo-list__item').first();
    await expect(row).toBeVisible();
    await expect(row.locator('.crm-todo-list__meta')).toBeVisible();
    const dueText = (await row.locator('.crm-todo-list__meta').innerText()).trim();
    expect(dueText === '' || dueText === '—').toBeFalsy();
    await expect(row.locator('[data-crm-complete-activity]')).toBeVisible();
    await expect(row.locator('details.crm-more-menu')).toHaveCount(0);

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('pipeline new deal is title-first and axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/pipeline');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });

    const newDeal = page.locator('#crm-pipeline-new-deal');
    if (!(await newDeal.count())) {
      test.info().annotations.push({ type: 'note', description: 'No new-deal control (read-only?)' });
      return;
    }
    await newDeal.click();
    const form = page.locator('form[id^="crm-new-deal-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await expect(form.locator('.crm-deal-company-picker')).toBeVisible();
    await expect(form.locator('.crm-company-list, .crm-company-choice:not([hidden])').first()).toBeVisible();
    await expect(form.locator('[name="title"]')).toBeVisible();
    await expect(form.locator('details.crm-advanced')).not.toHaveAttribute('open', '');
    await expect(form.locator('.crm-advanced__body')).toBeAttached();
    // Literal jargon bans — fixed product strings, locale-independent.
    for (const jargon of ['Products / SKUs', 'Produkte/SKUs']) {
      await expect(form.getByText(jargon)).toHaveCount(0);
    }
    await form.locator('[data-cancel]').click();

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});
