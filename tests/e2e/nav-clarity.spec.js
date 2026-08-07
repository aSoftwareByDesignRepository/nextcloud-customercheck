/**
 * Bachus clarity — nav groups, owners, pipeline board scan, page jobs.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Nav clarity and deal overview', () => {
  test('sidebar groups and job titles are present', async ({ page }) => {
    await page.goto('/apps/customercheck/today');
    await expect(page.locator('#app-navigation .crm-nav__group-label').first()).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#app-navigation')).toContainText(/Do now|Jetzt erledigen|Jetzt handeln/i);
    await expect(page.locator('#app-navigation')).toContainText(/Deals & money|Deals & Geld|Geschäfte & Geld/i);
    await expect(page.locator('#app-navigation a[title]').first()).toBeVisible();
    await expect(page.locator('#crm-page-title')).toContainText(/Today|Heute/i);
    await expect(page.locator('.crm-page-header__lead')).toContainText(/workspace|Arbeitsbereich|Pick one|Folgen|finish|erledig/i);
  });

  test('companies list exposes owner column', async ({ page }) => {
    await page.goto('/apps/customercheck/companies');
    await expect(page.locator('table thead')).toContainText(/Owner|Verantwortlich|Eigentümer|Account-Owner/i);
    await expect(page.locator('[data-crm-account-owner], td[data-label]').first()).toBeVisible({ timeout: 15000 });
    const axe = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .disableRules(['color-contrast'])
      .analyze();
    expect(axe.violations, JSON.stringify(axe.violations, null, 2)).toEqual([]);
  });

  test('pipeline board columns expose count and sum', async ({ page }) => {
    await page.goto('/apps/customercheck/pipeline');
    const boardBtn = page.locator('[data-crm-pipeline-view="board"]').first();
    await boardBtn.click({ force: true });
    await expect(page.locator('#crm-pipeline-board')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.crm-kanban__column-count').first()).toBeVisible();
    await expect(page.locator('.crm-kanban__column-sum').first()).toBeVisible();
    await expect(page.locator('.crm-page-header__lead')).toContainText(/deal|Deal|owner|Owner|Firma|company|Unternehmen/i);
  });
});
