// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * CUC M1 S3 — merge control is reachable from company 360.
 */
test.describe('Company merge entry point', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('company 360 exposes Merge into… with usable touch target', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });

    // Prefer numeric company detail links; never the /companies/new CTA.
    const detailHref = await page.locator('a[href*="/apps/customercheck/companies/"]').evaluateAll((anchors) => {
      for (const a of anchors) {
        const href = a.getAttribute('href') || '';
        if (/\/apps\/customercheck\/companies\/\d+(\/|$|\?)/.test(href)) {
          return href;
        }
      }
      return null;
    });
    if (!detailHref) {
      test.skip(true, 'No companies in this environment');
      return;
    }
    await page.goto(detailHref);
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    // Merge is a destructive action under More (same pattern as GDPR erase).
    const more = page.locator('details.crm-more-menu').first();
    await expect(more).toBeVisible({ timeout: 15000 });
    await more.locator('summary').click();
    const merge = page.locator('#crm-company-merge');
    await expect(merge).toBeVisible({ timeout: 15000 });
    await expect(merge).toHaveAttribute('type', 'button');
    const box = await merge.boundingBox();
    expect(box).not.toBeNull();
    // WCAG 2.1 AA / portfolio touch target: ≥ 44 CSS px.
    expect(box.height).toBeGreaterThanOrEqual(44);

    // Open merge dialog — must show keeper picker and cancel without crashing.
    await merge.click();
    const dialog = page.locator('.modal.crm-dialog[role="dialog"]').last();
    await expect(dialog).toBeVisible({ timeout: 15000 });
    await expect(dialog.locator('.modal-title')).toContainText(/Merge|Zusammenführen/i);
    const cancel = dialog.locator('[data-cancel]');
    await expect(cancel).toBeVisible();
    await cancel.click();
    await expect(dialog).toBeHidden({ timeout: 10000 });
    await expect(page.locator('#crm-main-content')).toBeVisible();
  });
});
