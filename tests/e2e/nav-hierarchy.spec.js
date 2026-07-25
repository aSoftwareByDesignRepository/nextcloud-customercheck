// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Tools accordion hierarchy — PSD S12.
 */
test.describe('CustomerCheck sidebar nav hierarchy', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Tools submenu nests under parent with chevron + rail', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/settings');
    await page.waitForSelector('#customercheck-app', { timeout: 30000 });
    await page.waitForSelector('#tools-subnav', { timeout: 30000 });

    const toolsToggle = page.locator('#app-navigation .nav-item-has-children').filter({
      has: page.locator('#tools-subnav'),
    }).locator('.nav-parent-toggle');
    await expect(toolsToggle).toBeVisible();
    await expect(toolsToggle.locator('.nav-parent-chevron')).toBeVisible();

    const submenu = page.locator('#tools-subnav');
    if (await submenu.getAttribute('hidden') !== null) {
      await toolsToggle.click();
    }
    await expect(submenu).not.toHaveAttribute('hidden', '');
    await expect(page.locator('#tools-subnav a').first()).toBeVisible();
    await expect(toolsToggle).toHaveAttribute('aria-expanded', 'true');

    const metrics = await page.evaluate(() => {
      const submenuEl = document.getElementById('tools-subnav');
      const childLink = document.querySelector('#tools-subnav > li > a');
      const toggle = document.querySelector(
        '#app-navigation .nav-item-has-children:has(#tools-subnav) > .nav-parent-toggle',
      );
      if (!submenuEl || !childLink || !toggle) {
        return { ok: false };
      }
      const nest = getComputedStyle(document.getElementById('app-navigation')).getPropertyValue('--crm-nav-child-nest').trim();
      const pad = parseFloat(getComputedStyle(childLink).paddingLeft || '0');
      const rail = getComputedStyle(submenuEl, '::before');
      return {
        ok: true,
        nest,
        pad,
        railDisplay: rail.display,
        ariaExpanded: toggle.getAttribute('aria-expanded'),
      };
    });

    expect(metrics.ok, JSON.stringify(metrics)).toBe(true);
    expect(metrics.ariaExpanded).toBe('true');
    expect(metrics.pad).toBeGreaterThan(20);
    expect(String(metrics.nest)).not.toBe('');
  });
});
