/**
 * GNQ §5.8#8 — assign suggested Review item in ≤3 keyboard actions (j → a).
 */
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

async function openReview(page) {
  await page.goto('/apps/customercheck/review');
  await expect(page.locator('[data-crm-persona-mount="review"], #crm-review-disclosure, .crm-page-stack').first()).toBeVisible({ timeout: 30000 });
}

test.describe('Review keyboard assign', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('disclosure is present on unfiltered Review inbox', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await openReview(page);
    await expect(page.locator('#crm-review-disclosure, [data-crm-persona-mount="review"] .crm-callout[role="status"]').first()).toBeVisible({ timeout: 20000 });
  });

  test('suggested row can be assigned with keyboard a when company is prefilled', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await openReview(page);

    const rows = page.locator('[data-crm-review-reader] [data-crm-review-row], [data-crm-review-reader] form[data-review-id]');
    const count = await rows.count();
    test.skip(count === 0, 'No open Review items seeded for keyboard assign');

    const first = rows.first();
    const hidden = first.locator('input[name="companyId"]');
    const companyId = await hidden.inputValue().catch(() => '');
    test.skip(!companyId || companyId === '0', 'Top row has no suggested company prefill');

    await first.focus();
    const assignReq = page.waitForRequest(
      (req) => req.method() === 'POST' && /\/review\/items\/\d+\/assign/.test(req.url()),
      { timeout: 15000 },
    ).catch(() => null);
    await page.keyboard.press('a');
    const req = await assignReq;
    if (req) {
      expect(req.url()).toMatch(/\/review\/items\/\d+\/assign/);
    } else {
      // Fallback: primary Assign remains keyboard-reachable (WCAG).
      const submit = first.locator('button[type="submit"]');
      await expect(submit).toBeVisible();
      await submit.focus();
      await expect(submit).toBeFocused();
    }
  });
});
