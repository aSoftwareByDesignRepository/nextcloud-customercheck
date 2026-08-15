/**
 * Unassigned assign — open row, use suggestion or Assign (same chrome as Inbox Reply).
 */
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

async function openUnassigned(page) {
  await page.goto('/apps/customercheck/sales-inbox?tab=unmatched&kind=mail');
  await expect(page.locator('#crm-sales-inbox-root[data-crm-inbox-unified="1"]')).toBeVisible({ timeout: 30000 });
  await expect(page.locator('#crm-sales-tab-unmatched')).toHaveAttribute('aria-selected', 'true');
}

test.describe('Inbox Unassigned assign', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('disclosure is present on Unassigned tab', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await openUnassigned(page);
    await expect(page.locator('#crm-sales-inbox-disclosure')).toBeVisible({ timeout: 20000 });
  });

  test('suggested row can be assigned via one-tap when company is prefilled', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await openUnassigned(page);

    const rows = page.locator('#crm-sales-inbox-list .crm-mail-thread-row');
    const count = await rows.count();
    test.skip(count === 0, 'No open Unassigned mail seeded for assign');

    await rows.first().click();
    await expect(page.locator('#crm-sales-inbox-open-assign')).toBeVisible({ timeout: 20000 });
    await page.locator('#crm-sales-inbox-open-assign').click();
    await expect(page.locator('#crm-sales-inbox-decide')).toBeVisible({ timeout: 20000 });

    const quick = page.locator('#crm-sales-inbox-quick-assign');
    const quickId = await quick.getAttribute('data-company-id').catch(() => null);
    test.skip(!quickId || quickId === '0' || !(await quick.isVisible()), 'Top row has no suggested company');

    const assignReq = page.waitForRequest(
      (req) => req.method() === 'POST' && /\/review\/items\/\d+\/assign/.test(req.url()),
      { timeout: 15000 },
    ).catch(() => null);
    await quick.click();
    const req = await assignReq;
    expect(req, 'expected assign POST').toBeTruthy();
  });
});
