// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Header / app menu must use CustomerCheck building+check icon — not AZC clock.
 */
test.describe('CustomerCheck app icon', () => {
  test('served app icons are CustomerCheck building+check, not AZC clock', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC credentials');
    const creds = process.env.NC_ADMIN_USER ? credsFromEnv('ADMIN') : credsFromEnv('EMPLOYEE');
    await login(page, creds);
    await page.goto('/apps/customercheck/companies');
    await page.waitForSelector('#customercheck-app', { timeout: 30000 });

    const icons = await page.evaluate(async () => {
      /** @type {(app: string, file: string) => string} */
      const imagePath = (typeof OC !== 'undefined' && OC.imagePath)
        ? OC.imagePath.bind(OC)
        : (app, file) => `/custom_apps/${app}/img/${file}`;

      const load = async (path) => {
        const res = await fetch(path, { cache: 'no-store' });
        return { path, ok: res.ok, status: res.status, text: res.ok ? await res.text() : '' };
      };

      return {
        white: await load(imagePath('customercheck', 'app.svg')),
        dark: await load(imagePath('customercheck', 'app-dark.svg')),
        dashboard: await load(imagePath('customercheck', 'app-dashboard.svg')),
      };
    });

    for (const key of ['white', 'dark', 'dashboard']) {
      const icon = icons[key];
      expect(icon.ok, `${key} ${icon.path} status ${icon.status}`).toBe(true);
      expect(icon.text, key).not.toContain('a10 10 0 1 0 10 10');
      expect(icon.text, key).toMatch(/M8 2a2 2/);
      expect(icon.text, key).toMatch(/M9\.5 14\.8/);
      expect(icon.text, key).toMatch(/CustomerCheck/i);
      // Strict XML: comments must not contain "--"
      const comments = [...icon.text.matchAll(/<!--([\s\S]*?)-->/g)].map((m) => m[1]);
      for (const body of comments) {
        expect(body, `${key} comment`).not.toContain('--');
      }
    }

    expect(icons.white.text).toContain('fill="#ffffff"');
    expect(icons.dark.text).not.toContain('fill="#ffffff"');
    expect(icons.dashboard.text).not.toContain('fill="#ffffff"');

    // Header app glyph: core renders the current-app icon as a CSS-masked
    // .app-menu__current-app-glyph (--app-icon-url), not an <img> — assert the
    // resolved URL points at our building+check icon, not the AZC clock.
    const glyph = page.locator('.app-menu__current-app-glyph').first();
    await expect(glyph).toBeAttached({ timeout: 15000 });
    const iconUrl = await glyph.evaluate((el) => getComputedStyle(el).getPropertyValue('--app-icon-url'));
    expect(iconUrl || '').toContain('customercheck/img/app.svg');
    expect(iconUrl || '').not.toContain('a10 10');
  });
});
