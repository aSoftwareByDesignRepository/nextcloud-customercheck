// @ts-check
/**
 * Responsive gauntlet: every core page at every breakpoint class —
 * narrow phone (320), phone (375), tablet portrait (768), small desktop
 * (1024), desktop (1440) and 4K (3840).
 *
 * Per viewport × page this suite asserts:
 *  1. shell renders (page header + page stack),
 *  2. zero horizontal overflow on the document and on #crm-main-content
 *     (WCAG 1.4.10 Reflow),
 *  3. every visible button-like control inside main content offers a
 *     ≥ 44×44 px hit target (WCAG 2.5.5 / design-system rule 4),
 *  4. below 1024 px the navigation drawer collapses instead of
 *     overlapping/squeezing the content (native NC drawer pattern).
 *
 * Full-page screenshots are stored under test-results/responsive/ as
 * visual-regression evidence for each breakpoint.
 */
import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { login, credsFromEnv } from './helpers/auth.js';

const VIEWPORTS = [
  { name: 'phone-320', width: 320, height: 568 },
  { name: 'phone-375', width: 375, height: 812 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'desktop-1024', width: 1024, height: 768 },
  { name: 'desktop-1440', width: 1440, height: 900 },
  { name: 'uhd-3840', width: 3840, height: 2160 },
];

const PAGES = [
  { path: '/apps/customercheck/', name: 'today' },
  { path: '/apps/customercheck/companies', name: 'companies' },
  { path: '/apps/customercheck/pipeline', name: 'pipeline' },
  { path: '/apps/customercheck/agreements', name: 'agreements' },
  { path: '/apps/customercheck/money', name: 'money' },
  { path: '/apps/customercheck/activities', name: 'activities' },
  { path: '/apps/customercheck/import', name: 'import' },
  { path: '/apps/customercheck/settings', name: 'settings' },
  { path: '/apps/customercheck/app-settings', name: 'app-settings' },
  // support-us intentionally ships its own hero layout instead of the page stack
  { path: '/apps/customercheck/support-us', name: 'support-us', shell: '.crm-support-us-page' },
];

const shotDir = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'test-results', 'responsive');

for (const viewport of VIEWPORTS) {
  test(`responsive ${viewport.name}: all pages reflow, no overflow, 44px targets`, async ({ page }) => {
    test.setTimeout(420000);
    test.skip(!process.env.NC_ADMIN_USER && !process.env.NC_EMPLOYEE_USER, 'Requires NC credentials');
    const creds = process.env.NC_ADMIN_USER ? credsFromEnv('ADMIN') : credsFromEnv('EMPLOYEE');
    mkdirSync(shotDir, { recursive: true });

    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await login(page, creds);

    for (const { path, name, shell } of PAGES) {
      await page.goto(path, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#crm-main-content', { timeout: 30000 });

      // 1. Shell chrome present.
      await expect(
        page.locator('.crm-page-header').first(),
        `[${viewport.name}] ${name}: page header missing`,
      ).toBeVisible();
      await expect(
        page.locator(shell || '.crm-page-stack').first(),
        `[${viewport.name}] ${name}: page layout root missing`,
      ).toBeVisible();

      // 2. No horizontal overflow (document + main region).
      const overflow = await page.evaluate(() => {
        const doc = document.scrollingElement || document.documentElement;
        const main = document.getElementById('crm-main-content');
        return {
          doc: doc.scrollWidth - doc.clientWidth,
          main: main ? main.scrollWidth - main.clientWidth : 0,
        };
      });
      expect(overflow.doc, `[${viewport.name}] ${name}: document scrolls horizontally by ${overflow.doc}px`).toBeLessThanOrEqual(2);
      expect(overflow.main, `[${viewport.name}] ${name}: main content overflows by ${overflow.main}px`).toBeLessThanOrEqual(2);

      // 3. Touch targets ≥ 44×44 for visible button-like controls in main.
      const smallTargets = await page.evaluate(() => {
        const MIN = 43.5; // 44px minus sub-pixel rounding tolerance
        const offenders = [];
        const controls = document.querySelectorAll(
          '#crm-main-content button, #crm-main-content a.crm-btn, #crm-main-content [role="button"], #crm-main-content input[type="submit"]',
        );
        for (const el of controls) {
          const rect = el.getBoundingClientRect();
          const cs = getComputedStyle(el);
          if (rect.width === 0 || rect.height === 0 || cs.visibility === 'hidden' || cs.display === 'none') {
            continue; // hidden (collapsed panels, closed dialogs)
          }
          if (rect.width < MIN || rect.height < MIN) {
            offenders.push(
              `${el.tagName}.${String(el.className).split(' ').slice(0, 3).join('.')} `
              + `${Math.round(rect.width)}×${Math.round(rect.height)}px "${(el.textContent || '').trim().slice(0, 40)}"`,
            );
          }
        }
        return offenders;
      });
      expect(
        smallTargets,
        `[${viewport.name}] ${name}: touch targets below 44×44px:\n${smallTargets.join('\n')}`,
      ).toEqual([]);

      // 4. Below the desktop breakpoint the collapsed nav drawer must not sit
      // on top of the content. The native snap.js pattern keeps #app-navigation
      // off-canvas *behind* the content plane, so rectangle intersection lies —
      // hit-test the visible strip instead.
      if (viewport.width < 1024) {
        const navOk = await page.evaluate(() => {
          const nav = document.getElementById('app-navigation');
          if (!nav) return true;
          const cs = getComputedStyle(nav);
          if (cs.display === 'none' || cs.visibility === 'hidden') return true;
          const rect = nav.getBoundingClientRect();
          if (rect.width === 0 || rect.right <= 0) return true;
          // Sample the vertical middle of whatever part is inside the viewport.
          const x = Math.max(1, Math.min(rect.right - 4, window.innerWidth - 1));
          const y = rect.top + rect.height / 2;
          const topEl = document.elementFromPoint(x, y);
          return !topEl || !topEl.closest('#app-navigation');
        });
        expect(navOk, `[${viewport.name}] ${name}: collapsed nav drawer covers main content`).toBe(true);
      }

      // Visual-regression evidence per breakpoint.
      await page.screenshot({
        path: join(shotDir, `${viewport.name}--${name}.png`),
        fullPage: viewport.width < 1024,
      });
    }
  });
}
