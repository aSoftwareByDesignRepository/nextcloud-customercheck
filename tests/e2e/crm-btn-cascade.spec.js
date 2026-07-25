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

    // Source contract (same as CrmBtnCascadeContractTest) — CSS must declare solid fills + 2.75rem.
    const cssPath = path.resolve(__dirname, '../../css/app.css');
    const css = fs.readFileSync(cssPath, 'utf8');
    expect(css).toContain('#app-content.crm-app .crm-btn--primary');
    expect(css).toContain('min-height: 2.75rem');
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
    const heightOk = (Number.isFinite(minPx) && (minPx >= 40 || String(metrics.minH).includes('2.75')))
      || (typeof metrics.h === 'number' && metrics.h >= 40);
    expect(heightOk, JSON.stringify(metrics)).toBe(true);
    expect(metrics.bg).not.toBe('rgba(0, 0, 0, 0)');
  });
});
