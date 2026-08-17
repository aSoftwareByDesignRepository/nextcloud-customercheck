// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Extreme simplicity — name-only create, one Log email, collapsed expert chrome.
 */
test.describe('Granny-simple company 360', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('name-only create, single log email, people lean, account plan collapsed', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    await expect(page.locator('#crm-company-advanced')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-company-status')).toBeHidden();
    await expect(page.locator('#crm-company-address')).toBeVisible();
    await expect(page.locator('#crm-company-street')).toBeVisible();
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Granny Co ${suffix}`);
    await page.locator('#crm-company-street').fill('Holtenauer Straße 267');
    await page.locator('#crm-company-zip').fill('24106');
    await page.locator('#crm-company-city').fill('Kiel');
    await page.locator('#crm-company-country').selectOption('DE');
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await expect(page.locator('#crm-company-address-display')).toBeVisible();
    await expect(page.locator('#crm-company-address-display')).toContainText('Holtenauer Straße 267');
    await expect(page.locator('#crm-company-address-display')).toContainText('24106');
    await expect(page.locator('#crm-company-address-display')).toContainText('Kiel');
    await expect(page.locator('#crm-change-company')).toBeVisible();
    await expect(page.locator('#crm-edit-address')).toBeVisible();
    await expect(page.locator('#crm-edit-address')).toContainText(/Change address|Adresse ändern/i);
    await expect(page.locator('#crm-identity-people')).toBeVisible();
    await expect(page.locator('#crm-add-person')).toBeVisible();
    await expect(page.locator('#crm-identity [data-crm-trigger="crm-add-person"]')).toBeVisible();
    await expect(page.locator('#crm-welcome-coach')).toBeVisible();
    await expect(page.locator('#crm-welcome-coach .crm-welcome-coach__steps')).toBeVisible();
    await expect(page.locator('#crm-welcome-coach .crm-welcome-coach__step')).toHaveCount(3);
    await expect(page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-person"]')).toBeVisible();
    await expect(page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-deal"]')).toBeVisible();
    // People share Today with the coach — no duplicate Add person in that empty state.
    await expect(page.locator('#crm-persons .crm-empty-state [data-crm-trigger="crm-add-person"]')).toHaveCount(0);
    // Deals are a separate focus tab — Add deal must remain reachable there.
    await page.locator('[data-crm-focus-tab="deals"]').click();
    await expect(page.locator('#crm-add-deal')).toBeVisible();
    await expect(page.locator('#crm-deals .crm-empty-state [data-crm-trigger="crm-add-deal"]')).toBeVisible();
    await page.locator('[data-crm-focus-tab="today"]').click();
    await expect(page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-deal"]')).toBeVisible();
    await expect(page.locator('#crm-360-role-title')).toHaveCount(0);
    await expect(page.locator('#crm-quickstart-company-detail')).toHaveCount(0);
    // Fresh company: no email yet → coach step 3 is Call tomorrow (never a dead-end Log email).
    await expect(page.locator('#crm-welcome-coach [data-crm-trigger="crm-schedule-callback"]')).toBeVisible();
    await expect(page.locator('#crm-welcome-coach [data-crm-trigger="crm-log-email"]')).toHaveCount(0);
    await expect(page.locator('#crm-log-email')).toBeVisible();
    await expect(page.locator('#crm-log-email')).toHaveCount(1);
    await expect(page.locator('#crm-schedule-callback')).toContainText(/Call tomorrow|Morgen anrufen/i);
    await expect(page.locator('#crm-add-promise')).toBeVisible();
    await expect(page.locator('#crm-new-task')).toBeVisible();
    await expect(page.locator('#crm-promises')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-promises .crm-next-steps__hint')).toContainText(/What you told the customer|Was ihr dem Kunden zugesagt habt|Was du dem Kunden gesagt hast/);
    await expect(page.locator('#crm-promises .crm-next-steps__hint')).not.toContainText(/WIP/i);
    await expect(page.getByText(/Evidence \(what was delivered|Nachweis \(was wurde geliefert/i)).toHaveCount(0);
    await expect(page.locator('#crm-next-steps .crm-card__header details.crm-more-menu')).toHaveCount(0);
    await expect(page.locator('#crm-add-activity')).toBeVisible();
    await expect(page.locator('#crm-activities .crm-empty-state [data-crm-trigger="crm-add-activity"]')).toHaveCount(0);

    await page.locator('[data-crm-focus-tab="more"]').click();
    await expect(page.locator('#crm-account-plan')).toBeVisible();
    await expect(page.locator('#crm-account-plan')).not.toHaveAttribute('open', '');
    await page.locator('#crm-account-plan > summary').click();
    await expect(page.locator('#crm-account-plan')).toHaveAttribute('open', '');
    await expect(page.locator('#crm-account-plan-form')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#crm-account-plan-body')).not.toContainText(/Loading account plan|Account-Plan wird geladen/i);
    await expect(page.locator('#crm-plan-goal')).toBeVisible();
    await expect(page.locator('#crm-map-step-title')).toBeVisible();
    await expect(page.locator('#crm-map-step-title')).toHaveJSProperty('tagName', 'TEXTAREA');
    await expect(page.locator('.crm-plan-more')).toBeVisible();
    await page.locator('.crm-plan-more > summary').click();
    await expect(page.locator('#crm-plan-priority')).toBeVisible();
    await expect(page.locator('#crm-account-plan-form button[type="submit"]')).toBeVisible();

    await page.locator('[data-crm-focus-tab="today"]').click();
    // Add a person via the coach (single CTA owner — empty state has no duplicate).
    await page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-person"]').click();
    const personForm = page.locator('#crm-person-form');
    await expect(personForm).toBeVisible({ timeout: 10000 });
    await personForm.locator('#crm-person-name').fill(`Granny Person ${suffix}`);
    await personForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const personsTable = page.locator('#crm-persons-table');
    await expect(personsTable).toBeVisible({ timeout: 15000 });
    await expect(personsTable.locator('thead th')).toHaveCount(3);
    await expect(personsTable.locator('thead th', { hasText: /^Buying role$/i })).toHaveCount(0);
    await expect(personsTable.locator('thead th', { hasText: /Last touch|Letzter Kontakt/i })).toHaveCount(0);
    await expect(personsTable.locator('.crm-person-more').first()).toBeVisible();
    await expect(personsTable.locator('.crm-person-more').first()).toHaveAttribute('open', '');
    await expect(personsTable.locator('.crm-person-more__body').first()).toBeVisible();
    await expect(personsTable.locator('.crm-person-more__body').first()).toContainText(/Last touch|Letzter Kontakt|Role in buying|Rolle beim Kauf/i);
    await expect(personsTable.locator('[data-crm-edit-person]').first()).toBeVisible();
    await expect(page.locator('#crm-identity-people .crm-identity-person').first()).toBeVisible();
    await expect(personsTable.locator('details.crm-more-menu')).toHaveCount(0);
    const primary = personsTable.locator('tbody tr').first().locator('.crm-table-actions .crm-btn--primary').first();
    await expect(primary).toBeVisible();
    await expect(primary).toContainText(/Log talk|Gespräch|Open in Contacts|Kontakte/i);
    await expect(primary).toHaveAttribute('data-crm-trigger', 'crm-log-email');
    // Skip opening Log talk here — the modal blocks focus-tab clicks; header #crm-log-email is enough.

    const focus = page.locator('[data-crm-360-focus="1"]');
    await expect(focus).toBeVisible();
    await expect(focus.locator('[data-crm-focus-tab]')).toHaveCount(4);
    await expect(focus.locator('[data-crm-focus-tab="today"][aria-selected="true"]')).toBeVisible();
    await expect(page.locator('#crm-360-panel-deals')).toHaveAttribute('hidden', '');
    await expect(page.locator('#crm-communication')).toBeVisible();
    await expect(page.locator('#crm-open-tasks')).toBeVisible();
    await expect(page.locator('#crm-open-tasks')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-add-activity')).toContainText(/Add to-do|Aufgabe hinzufügen|To-do hinzufügen/i);
    await expect(page.locator('#crm-deals')).toBeAttached();
    await focus.locator('[data-crm-focus-tab="deals"]').click();
    await expect(page.locator('#crm-deals')).toBeVisible();
    await focus.locator('[data-crm-focus-tab="more"]').click();
    await expect(page.locator('#crm-prepare-meeting')).toBeAttached();
    await expect(page.locator('#crm-timeline')).toBeAttached();
    await expect(page.locator('#crm-prepare-meeting')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-timeline')).not.toHaveAttribute('open', '');
    // Role cue stays hidden while the welcome coach is teaching.
    await expect(page.locator('#crm-360-role-title')).toHaveCount(0);
    await focus.locator('[data-crm-focus-tab="today"]').click();
    await expect(page.locator('#crm-welcome-coach')).toBeVisible();
    await expect(page.locator('details#crm-identity-links')).toHaveCount(0);
    await focus.locator('[data-crm-focus-tab="money"]').click();
    await expect(page.locator('#crm-linked-bundle')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-identity-links')).toBeAttached();

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('brief last-week verify exposes one primary Done with default evidence', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/brief');
    await page.waitForSelector('[data-crm-persona-mount="brief"]', { timeout: 30000 });
    await page.waitForFunction(() => {
      const mount = document.querySelector('[data-crm-persona-mount="brief"]');
      return mount && mount.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 30000 });

    const verify = page.locator('.crm-brief-verify').first();
    await expect(page.locator('#crm-brief-wip-title, .crm-card__title').first()).toBeVisible();
    await expect(page.locator('.crm-card__lead').first()).toContainText(/This week|Diese Woche|promises|Zusagen|Promises left|Freie Zusagen|Slots left|Freie Plätze|Your week is full|Deine Woche|View only|Nur lesen/i);
    await expect(page.locator('.crm-card__lead').first()).not.toContainText(/\bWIP\b/);
    await expect(page.getByText(/Money customers still owe|Geld, das Kunden noch schulden/i).first()).toBeVisible();
    await expect(page.getByText(/Overdue AR|Überfällige AR/i)).toHaveCount(0);

    if (await verify.count() === 0) {
      test.info().annotations.push({ type: 'note', description: 'No last-week commitments needing verify — chrome still loads' });
      return;
    }
    await expect(verify.locator('button[data-action="done"].crm-btn--primary')).toBeVisible();
    await expect(verify.locator('.crm-brief-verify__more')).toBeVisible();
    await expect(verify.locator('select[data-evidence-for]')).toBeHidden();
    await expect(verify.locator('button[data-action="missed"]')).toBeHidden();
    await verify.locator('.crm-brief-verify__more > summary').click();
    await expect(verify.locator('select[data-evidence-for]')).toBeVisible();
    await expect(verify.locator('select[data-evidence-for]')).toHaveValue('activity_done');
    await expect(verify.locator('button[data-action="missed"]')).toBeVisible();
  });

  test('company edit keeps Advanced closed and axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Edit Calm ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    const companyId = page.url().match(/companies\/(\d+)/)?.[1];
    expect(companyId).toBeTruthy();

    await page.goto(`/apps/customercheck/companies/${companyId}/edit`);
    await page.waitForSelector('#crm-company-form[data-mode="edit"]', { timeout: 30000 });
    await expect(page.locator('.crm-company-essentials')).toBeVisible();
    await expect(page.locator('#crm-company-name')).toBeVisible();
    await expect(page.locator('#crm-company-name-help')).toBeVisible();
    await expect(page.locator('#crm-company-advanced')).not.toHaveAttribute('open', '');
    await expect(page.locator('#crm-company-status')).toBeHidden();
    await expect(page.locator('#crm-company-owner')).toBeHidden();
    await expect(page.locator('#crm-company-street')).toBeVisible();
    await expect(page.locator('#crm-company-address')).toBeVisible();
    await page.locator('#crm-company-advanced > summary').click();
    await expect(page.locator('#crm-company-advanced')).toHaveAttribute('open', '');
    await expect(page.locator('#crm-company-status')).toBeVisible();

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});
