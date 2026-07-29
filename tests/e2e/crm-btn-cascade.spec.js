// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv } from './helpers/auth.js';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

test.describe('CustomerCheck button cascade', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('primary/secondary buttons meet AZC min-height contract', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/companies');
    await page.waitForSelector('#app-content.crm-app', { timeout: 30000 });

    // Source contract (same as CrmBtnCascadeContractTest) — CSS must declare solid fills
    // + a true 44px minimum (WCAG 2.5.5 target size is defined in CSS px; rem shrinks
    // under Nextcloud's 15px root font).
    const cssPath = path.resolve(__dirname, '../../css/app.css');
    const css = fs.readFileSync(cssPath, 'utf8');
    expect(css).toContain('#app-content.crm-app .crm-btn--primary');
    expect(css).toContain('min-height: 44px');
    expect(css).toMatch(/background-color:\s*var\(--color-primary-element[^)]*\)\s*!important/);

    // Runtime: probe inside the scoped shell and assert computed size in px or rem.
    const metrics = await page.evaluate(() => {
      const host = document.querySelector('#app-content.crm-app');
      if (!host) return { ok: false };
      const probe = document.createElement('button');
      probe.type = 'button';
      probe.className = 'crm-btn crm-btn--primary';
      probe.textContent = 'Probe';
      host.appendChild(probe);
      const cs = getComputedStyle(probe);
      const minH = cs.minHeight;
      const h = probe.getBoundingClientRect().height;
      const bg = cs.backgroundColor;
      probe.remove();
      return { ok: true, minH, h, bg };
    });

    expect(metrics.ok).toBe(true);
    const minPx = parseFloat(String(metrics.minH));
    const heightOk = (Number.isFinite(minPx) && minPx >= 44)
      || (typeof metrics.h === 'number' && metrics.h >= 44);
    expect(heightOk, JSON.stringify(metrics)).toBe(true);
    expect(metrics.bg).not.toBe('rgba(0, 0, 0, 0)');
  });
});
