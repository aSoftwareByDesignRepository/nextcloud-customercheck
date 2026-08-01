// @ts-check
/**
 * Theme-matrix gauntlet: WCAG 2.1 AA (axe-core) + theme-variable adaptation
 * across every stock Nextcloud theme: Light, Dark, Light High-Contrast,
 * Dark High-Contrast.
 *
 * Per theme this suite:
 *  1. enables the theme through the theming API (real user theme switch,
 *     not an emulation shortcut — exercises body[data-theme-*] scoping),
 *  2. runs axe (wcag2a / wcag2aa / wcag21aa) on every core page,
 *  3. proves the accent/primary colour flows from --color-primary-element
 *     into rendered CRM chrome (custom-accent adaptation),
 *  4. proves keyboard focus rings stay visible (≥2px, non-transparent).
 *
 * The final test restores the default theme so the instance is left clean.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast'];

const PAGES = [
  '/apps/customercheck/',
  '/apps/customercheck/companies',
  '/apps/customercheck/pipeline',
  '/apps/customercheck/agreements',
  '/apps/customercheck/money',
  '/apps/customercheck/activities',
  '/apps/customercheck/companies/new',
  '/apps/customercheck/support-us',
];

/** Enable a user theme via the theming OCS API (CSRF-protected route). */
async function enableTheme(page, themeId) {
  const status = await page.evaluate(async (id) => {
    const token = document.head?.dataset?.requesttoken
      // @ts-ignore Nextcloud global
      || (window.OC && window.OC.requestToken);
    const res = await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${id}/enable`, {
      method: 'PUT',
      headers: { requesttoken: token || '', 'OCS-APIRequest': 'true' },
    });
    return res.status;
  }, themeId);
  expect(status, `theming API must accept enabling theme "${themeId}"`).toBe(200);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect
    .poll(async () => page.evaluate((id) => document.body.hasAttribute(`data-theme-${id}`), themeId), {
      message: `body must carry data-theme-${themeId} after enabling`,
      timeout: 15000,
    })
    .toBe(true);
}

/** Disable a user theme (restore default). Missing/foreign ids are tolerated. */
async function disableTheme(page, themeId) {
  await page.evaluate(async (id) => {
    const token = document.head?.dataset?.requesttoken
      // @ts-ignore Nextcloud global
      || (window.OC && window.OC.requestToken);
    await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
      method: 'DELETE',
      headers: { requesttoken: token || '', 'OCS-APIRequest': 'true' },
    }).catch(() => {});
  }, themeId);
}

for (const theme of THEMES) {
  test(`theme ${theme}: axe WCAG 2.1 AA green on all core pages`, async ({ page }) => {
    test.setTimeout(420000);
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
    await login(page, credsFromEnv('ADMIN'));
    try {
      await enableTheme(page, theme);

      for (const path of PAGES) {
        await page.goto(path, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#crm-main-content', { timeout: 30000 });

        const results = await new AxeBuilder({ page })
          .include('#crm-main-content')
          .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
          .analyze();
        const serious = results.violations.filter(
          (v) => v.impact === 'serious' || v.impact === 'critical',
        );
        expect(
          serious,
          `[${theme}] ${path} axe violations:\n${JSON.stringify(serious, null, 2)}`,
        ).toEqual([]);

        const overflowFree = await page.evaluate(() => {
          const main = document.getElementById('crm-main-content');
          return !!main && main.scrollWidth <= main.clientWidth + 2;
        });
        expect(overflowFree, `[${theme}] ${path} must not overflow horizontally`).toBe(true);
      }
    } finally {
      await disableTheme(page, theme).catch(() => {});
    }
  });

  test(`theme ${theme}: primary chrome follows --color-primary-element (accent adaptation)`, async ({ page }) => {
    test.setTimeout(180000);
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
    await login(page, credsFromEnv('ADMIN'));
    try {
      await enableTheme(page, theme);
      await page.goto('/apps/customercheck/companies', { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#crm-main-content', { timeout: 30000 });
      // Page CTAs relocate from .crm-page-actions-source into #crm-page-actions (header, not main).
      await page.waitForSelector(
        '#crm-page-actions .crm-btn--primary, #crm-main-content .crm-btn--primary',
        { timeout: 30000 },
      );

      const { expected, actual, probeFound } = await page.evaluate(() => {
        // Resolve what --color-primary-element computes to in this theme.
        const probe = document.createElement('span');
        probe.style.backgroundColor = 'var(--color-primary-element)';
        probe.style.position = 'fixed';
        document.body.appendChild(probe);
        const expectedColor = getComputedStyle(probe).backgroundColor;
        probe.remove();

        const btn = document.querySelector(
          '#crm-page-actions .crm-btn--primary, #crm-main-content .crm-btn--primary',
        );
        return {
          expected: expectedColor,
          actual: btn ? getComputedStyle(btn).backgroundColor : null,
          probeFound: !!btn,
        };
      });
      expect(probeFound, `[${theme}] companies page must render a primary action`).toBe(true);
      expect(actual, `[${theme}] .crm-btn--primary must inherit --color-primary-element`).toBe(expected);
    } finally {
      await disableTheme(page, theme).catch(() => {});
    }
  });

  test(`theme ${theme}: keyboard focus ring is visible (WCAG 2.4.7)`, async ({ page }) => {
    test.setTimeout(180000);
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
    await login(page, credsFromEnv('ADMIN'));
    try {
      await enableTheme(page, theme);
      await page.goto('/apps/customercheck/companies', { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#crm-main-content', { timeout: 30000 });

      // Real keyboard modality so :focus-visible applies: land on <main>, Tab once.
      await page.evaluate(() => {
        const main = document.getElementById('crm-main-content');
        if (main) main.focus();
      });
      await page.keyboard.press('Tab');
      const ring = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) return null;
        const cs = getComputedStyle(el);
        return {
          inMain: !!el.closest('#crm-main-content'),
          tag: el.tagName,
          style: cs.outlineStyle,
          width: parseFloat(cs.outlineWidth || '0'),
          color: cs.outlineColor,
        };
      });
      expect(ring, `[${theme}] Tab from <main> must reach a focusable control`).not.toBeNull();
      expect(ring?.inMain, `[${theme}] first Tab stop must be inside main content`).toBe(true);
      expect(ring?.style, `[${theme}] focused ${ring?.tag} must draw an outline`).not.toBe('none');
      expect(ring?.width, `[${theme}] focus outline must be ≥ 2px`).toBeGreaterThanOrEqual(2);
      expect(ring?.color, `[${theme}] focus outline must not be transparent`).not.toMatch(/rgba\(\s*\d+,\s*\d+,\s*\d+,\s*0\s*\)/);
    } finally {
      await disableTheme(page, theme).catch(() => {});
    }
  });
}

test('theme matrix cleanup: default theme restored', async ({ page }) => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC admin credentials');
  await login(page, credsFromEnv('ADMIN'));
  for (const theme of THEMES) {
    await disableTheme(page, theme);
  }
  await page.reload({ waitUntil: 'domcontentloaded' });
  const residual = await page.evaluate(
    (themes) => themes.filter((id) => document.body.hasAttribute(`data-theme-${id}`)),
    THEMES,
  );
  expect(residual, 'no user theme may stay enabled after the matrix').toEqual([]);
});
