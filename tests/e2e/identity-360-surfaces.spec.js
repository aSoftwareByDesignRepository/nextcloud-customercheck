// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * SHARED-IDENTITY AC-C-04 — 360 primary create/link CTAs + keyboard + axe on identity section.
 */
test.describe('CUC identity 360 surfaces', () => {
  test.skip(!process.env.NC_ADMIN_USER || !process.env.NC_ADMIN_PASS, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test.beforeAll(async ({ request }) => {
    const base = process.env.NC_BASE_URL || 'http://localhost:8081';
    const user = process.env.NC_ADMIN_USER;
    const pass = process.env.NC_ADMIN_PASS;
    const res = await request.get(`${base}/ocs/v1.php/cloud/user`, {
      headers: {
        'OCS-APIRequest': 'true',
        Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64'),
      },
    });
    test.skip(res.status() === 401 || res.status() === 403, `Admin credentials rejected by OCS (${res.status()})`);
  });

  test('company 360 shows identity section with text states, 44px CTAs, keyboard, axe', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    const stamp = Date.now().toString(36);
    await page.goto('/apps/customercheck/companies/new', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#crm-company-name')).toBeVisible({ timeout: 30000 });
    await page.locator('#crm-company-name').fill('Identity E2E ' + stamp);
    await page.locator('#crm-company-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) {
        form.requestSubmit();
      }
    });
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 60000 });

    const identity = page.locator('#crm-identity-links');
    const bundle = page.locator('#crm-linked-bundle');
    await expect(bundle).toBeVisible({ timeout: 30000 });
    // Cold path: open the Linked apps host that wraps identity CTAs.
    await bundle.locator('> summary').click();
    await expect(bundle).toHaveAttribute('open', '');
    await expect(identity).toBeVisible();
    await expect(page.locator('details#crm-identity-links')).toHaveCount(0);

    const statusText = await identity.locator('.crm-identity-row__head').first().innerText();
    expect(/Linked|Not linked|Broken|Unavailable|Verknüpft|Nicht verknüpft/i.test(statusText)).toBeTruthy();
    await expect(identity.locator('.crm-identity-row__app', { hasText: /ProjectCheck/i })).toBeVisible();
    await expect(identity.getByText(/Nicht mit ProjectCheck verknüpft|Not linked to ProjectCheck/i)).toHaveCount(0);
    const createPc = identity.locator('[data-crm-identity-create="pc"]');
    const linkPc = identity.locator('[data-crm-identity-link="pc"]');
    const primary = (await createPc.count()) ? createPc.first() : ((await linkPc.count()) ? linkPc.first() : null);
    if (primary) {
      await expect(primary).toBeVisible();
      const box = await primary.boundingBox();
      expect(box).not.toBeNull();
      expect((box?.height || 0)).toBeGreaterThanOrEqual(44);
      await primary.focus();
      await expect(primary).toBeFocused();
      await page.keyboard.press('Tab');
    } else {
      // Sibling unavailable: muted copy still present (degrade, not blank).
      await expect(identity).toContainText(/Unavailable|not available|Nicht verfügbar|nicht verfügbar/i);
    }

    const axe = await new AxeBuilder({ page })
      .include('#crm-linked-bundle')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);

    await page.setViewportSize({ width: 320, height: 720 });
    await expect(identity).toBeVisible();
  });
});
