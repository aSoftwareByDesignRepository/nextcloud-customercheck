// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';
import { dismissNextStepNudge, fillNextStepDueDate, submitNewDealAndDismiss, waitForNewDealForm } from './helpers/new-deal-form.js';

/**
 * Shared new-deal form — pipeline + company 360 parity, post-create next step.
 */
test.describe('New deal form (shared module)', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('company 360 add deal uses full dialog with contact and lead source', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await gotoCrm(page, '/apps/customercheck/companies/new', { ready: '#crm-company-form' });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Deal Form Co ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-person"]').click();
    let personForm = page.locator('#crm-person-form');
    await expect(personForm).toBeVisible({ timeout: 10000 });
    await personForm.locator('#crm-person-name').fill(`Contact ${suffix}`);
    await personForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-persons-table')).toContainText(`Contact ${suffix}`, { timeout: 15000 });

    await page.locator('[data-crm-focus-tab="today"]').click();
    await page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-deal"]').click();

    const form = await waitForNewDealForm(page);
    await expect(form).toHaveClass(/crm-form--new-deal/);
    await expect(form.locator('[name="title"]')).toBeVisible();
    await expect(form.locator('[name="personId"]')).toBeVisible();
    await expect(form.locator('[name="leadSource"]')).toBeVisible();
    await expect(form.locator('[name="forecastBucket"]')).toBeAttached();
    await expect(form.locator('[name="forecastBucket"] option[value=""]')).toHaveCount(1);
    await expect(form.locator('[name="forecastBucket"] option[value="pipeline"]')).toHaveCount(1);
    await expect(form.locator('[name="forecastBucket"] option[value="upside"]')).toHaveCount(1);
    await expect(form.locator('[name="forecastBucket"] option[value="commit"]')).toHaveCount(1);
    await expect(form.locator('[name="forecastBucket"] option[value="certain"]')).toHaveCount(1);
    await form.locator('details.crm-new-deal__more summary').click();
    await expect(form.locator('[name="forecastBucket"] option[value="pipeline"]')).toHaveText(/25%/);
    await expect(form.locator('[name="forecastBucket"] option[value="certain"]')).toHaveText(/100%/);
    await expect(form.locator('.crm-form-section__legend').first()).toBeVisible();
    await expect(form.locator('.crm-deal-company-picker')).toHaveCount(0);
    await expect(form.locator('[name="companyId"][type="hidden"]')).toBeAttached();

    await form.locator('[name="title"]').fill(`360 Deal ${suffix}`);
    await form.locator('[name="notes"]').fill(`Note ${suffix}`);
    const leadSource = form.locator('[name="leadSource"]');
    const options = await leadSource.locator('option').allTextContents();
    expect(options.length).toBeGreaterThan(1);
    await leadSource.selectOption({ index: 1 });
    await submitNewDealAndDismiss(page, form);

    await page.locator('[data-crm-focus-tab="deals"]').click();
    await expect(page.locator('#crm-deals-table')).toContainText(`360 Deal ${suffix}`, { timeout: 15000 });
    await expect(page.locator('#crm-deals-table')).toContainText(`Note ${suffix}`);
  });

  test('post-create next step adds activity to Today board', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await gotoCrm(page, '/apps/customercheck/companies/new', { ready: '#crm-company-form' });
    const suffix = Date.now().toString(36);
    await page.locator('#crm-company-name').fill(`Next Step Co ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });

    await page.locator('#crm-welcome-coach [data-crm-trigger="crm-add-deal"]').click();
    const form = await waitForNewDealForm(page);
    await form.locator('[name="title"]').fill(`Follow-up deal ${suffix}`);
    const createDeal = page.waitForResponse(
      (r) => r.request().method() === 'POST' && /\/deals(\?|$)/.test(r.url()) && r.status() < 400,
      { timeout: 30000 },
    );
    await form.locator('button[type="submit"]').click();
    await createDeal;

    const nextForm = page.locator('dialog.crm-native-dialog[open] form.crm-next-step-form');
    await expect(nextForm).toBeVisible({ timeout: 10000 });
    await expect(nextForm.locator('[name="title"]')).toBeVisible();
    await expect(nextForm.locator('input.datepicker-input, input[name="dueDate"]').first()).toBeAttached();
    const stepTitle = `Call back ${suffix}`;
    await nextForm.locator('[name="title"]').fill(stepTitle);
    const today = new Date().toISOString().slice(0, 10);
    await fillNextStepDueDate(nextForm, today);
    await nextForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('#crm-activities')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#crm-activities')).toContainText(stepTitle, { timeout: 15000 });
  });

  test('pipeline create deal with skip next step is axe clean', async ({ page }) => {
    test.setTimeout(120000);
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/pipeline');

    const newDeal = page.locator('#crm-pipeline-new-deal');
    if (!(await newDeal.count())) {
      test.info().annotations.push({ type: 'note', description: 'No new-deal control (read-only?)' });
      return;
    }
    await newDeal.click();
    const form = await waitForNewDealForm(page);
    const companyItem = form.locator('.crm-company-list__pick').first();
    await expect(companyItem).toBeVisible({ timeout: 15000 });
    await companyItem.click();
    const suffix = Date.now().toString(36);
    await form.locator('[name="title"]').fill(`Pipeline smoke ${suffix}`);
    await submitNewDealAndDismiss(page, form);
    await expect(page.locator('dialog.crm-native-dialog[open]')).toHaveCount(0, { timeout: 15000 });
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 15000 });
    await page.waitForLoadState('networkidle');

    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});
