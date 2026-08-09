// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

test.describe('Mobile app license settings (CUC2)', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');

  test('license section is visible for app admins and explains the free web app', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/app-settings/license');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });

    const section = page.locator('#section-license');
    await expect(section).toBeVisible();
    await expect(section.getByRole('heading', { level: 2 })).toBeVisible();
    await expect(page.locator('#crm-license-form')).toBeVisible();
    await expect(page.locator('#crm-license-key')).toBeVisible();
    // The key input must be described for screen readers.
    await expect(page.locator('#crm-license-key')).toHaveAttribute(
      'aria-describedby',
      /crm-license-key-help/,
    );
  });

  test('rejects a malformed license key with an accessible inline error', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/app-settings/license');
    await page.waitForSelector('#crm-license-form', { timeout: 30000 });

    await page.locator('#crm-license-key').fill('CUC2.this-is.not-a-valid-key');
    await page.locator('#crm-license-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });

    const error = page.locator('#crm-license-error');
    await expect(error).toBeVisible({ timeout: 15000 });
    await expect(error).toHaveAttribute('role', 'alert');
    await expect(error).not.toHaveText('');
    // Still on settings — a bad key never navigates away or breaks the page.
    await expect(page.locator('#section-license')).toBeVisible();
  });

  test('empty key submit shows a hint without a server round-trip crash', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/app-settings/license');
    await page.waitForSelector('#crm-license-form', { timeout: 30000 });

    await page.locator('#crm-license-key').fill('   ');
    await page.locator('#crm-license-form').evaluate((form) => {
      if (form instanceof HTMLFormElement) form.requestSubmit();
    });
    await expect(page.locator('#crm-license-error')).toBeVisible({ timeout: 15000 });
  });

  test('seat search finds directory users and hides already-seated', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/app-settings/license?workspaceId=1');
    await page.waitForSelector('#section-license', { timeout: 30000 });

    // Unhide seats block for the picker journey even without a live key.
    await page.evaluate(() => {
      const block = document.getElementById('crm-license-seats-block');
      if (block) block.hidden = false;
      const list = document.getElementById('crm-license-seat-list');
      if (list && !list.querySelector('[data-seat-uid="bob"]')) {
        const li = document.createElement('li');
        li.setAttribute('data-seat-uid', 'bob');
        li.textContent = 'Bob Seated';
        list.appendChild(li);
      }
    });

    await page.route('**/apps/customercheck/api/v1/settings/users**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            users: [
              { id: 'alice', displayName: 'Alice Example' },
              { id: 'bob', displayName: 'Bob Seated' },
            ],
          },
        }),
      });
    });

    const search = page.locator('#crm-license-seat-search');
    await expect(search).toBeVisible();
    await search.fill('al');
    const suggest = page.locator('#crm-license-seat-suggest');
    await expect(suggest).toBeVisible({ timeout: 10000 });
    await expect(suggest.getByRole('option', { name: /Alice Example/i })).toBeVisible();
    await expect(suggest.getByText(/Bob Seated/i)).toHaveCount(0);
  });

  test('seat assign stays on the page without a full reload', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/app-settings/license');
    await page.waitForSelector('#section-license', { timeout: 30000 });

    await page.locator('#section-license').scrollIntoViewIfNeeded();
    const scrollBefore = await page.evaluate(() => window.scrollY);

    // Empty assign must show inline error and keep scroll / URL stable.
    const seatsBlock = page.locator('#crm-license-seats-block');
    if (await seatsBlock.isVisible()) {
      await page.locator('#crm-license-seat-add').click();
      await expect(page.locator('#crm-license-seat-error')).toBeVisible({ timeout: 10000 });
      await expect(page).toHaveURL(/app-settings/);
      const scrollAfter = await page.evaluate(() => window.scrollY);
      expect(Math.abs(scrollAfter - scrollBefore)).toBeLessThan(120);
    } else {
      // No key applied yet — license form itself must still not navigate on empty save.
      await page.locator('#crm-license-key').fill('   ');
      await page.locator('#crm-license-form').evaluate((form) => {
        if (form instanceof HTMLFormElement) form.requestSubmit();
      });
      await expect(page.locator('#crm-license-error')).toBeVisible({ timeout: 15000 });
      await expect(page).toHaveURL(/app-settings/);
    }
  });

  test('settings page (including license section) passes axe WCAG 2.1 AA', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/app-settings/license');
    await page.waitForSelector('#crm-main-content', { timeout: 30000 });
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });

  test('license section is hidden from non-admin CRM users', async ({ page }) => {
    test.skip(!process.env.NC_EMPLOYEE_USER, 'Requires NC employee credentials');
    await login(page, credsFromEnv('EMPLOYEE'));
    await page.goto('/apps/customercheck/app-settings/license');
    // Fail-closed allowlist may deny the employee entirely — that also means no license UI.
    const denied = page.locator('.crm-access-denied, [data-crm-access-denied="1"]');
    const main = page.locator('#crm-main-content');
    await expect(denied.or(main).first()).toBeVisible({ timeout: 30000 });
    if (await denied.count()) {
      await expect(page.locator('#section-license')).toHaveCount(0);
      return;
    }
    await expect(page.locator('#section-license')).toHaveCount(0);
    await expect(page.locator('#crm-license-form')).toHaveCount(0);
  });
});

