// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Desktop shell: sidebar + main content side-by-side inside the viewport.
 * Regression for “pages show only the sidebar” (#customercheck-app display:block
 * stacked #app-content below the fold under #content { overflow:hidden }).
 */
test.describe('CustomerCheck desktop shell layout', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC_*_USER / NC_*_PASS');
    const creds = process.env.NC_ADMIN_USER
      ? credsFromEnv('ADMIN')
      : credsFromEnv('EMPLOYEE');
    await page.setViewportSize({ width: 1400, height: 900 });
    await login(page, creds);
  });

  for (const path of ['/apps/customercheck/companies', '/apps/customercheck/', '/apps/customercheck/money']) {
    test(`side-by-side panes visible: ${path}`, async ({ page }) => {
      await page.goto(path);
      await page.waitForSelector('#customercheck-app', { timeout: 30000 });
      await page.waitForSelector('#crm-main-content', { timeout: 30000 });

      const metrics = await page.evaluate(() => {
        const app = document.getElementById('customercheck-app');
        const nav = document.getElementById('app-navigation');
        const content = document.getElementById('app-content');
        const main = document.getElementById('crm-main-content');
        const box = (el) => {
          if (!el) return null;
          const r = el.getBoundingClientRect();
          return { x: r.x, y: r.y, w: r.width, h: r.height };
        };
        return {
          appDisplay: app ? getComputedStyle(app).display : null,
          appFlexDir: app ? getComputedStyle(app).flexDirection : null,
          app: box(app),
          nav: box(nav),
          content: box(content),
          main: box(main),
          viewportH: window.innerHeight,
          viewportW: window.innerWidth,
        };
      });

      expect(metrics.appDisplay).toBe('flex');
      expect(metrics.appFlexDir).toBe('row');
      expect(metrics.nav).not.toBeNull();
      expect(metrics.content).not.toBeNull();
      expect(metrics.main).not.toBeNull();

      // Nav and content share the first viewport row (not stacked below the fold).
      expect(metrics.nav.y).toBeLessThan(120);
      expect(metrics.content.y).toBeLessThan(120);
      expect(metrics.main.y).toBeLessThan(metrics.viewportH);

      // Side-by-side: content starts to the right of nav.
      expect(metrics.content.x).toBeGreaterThan(metrics.nav.x + 200);
      expect(metrics.content.w).toBeGreaterThan(400);

      // Main content must be visible (not clipped to zero height in the viewport).
      const mainVisible = await page.locator('#crm-main-content').isVisible();
      expect(mainVisible).toBe(true);
      await expect(page.locator('.crm-page-header').first()).toBeVisible();
    });
  }
});
