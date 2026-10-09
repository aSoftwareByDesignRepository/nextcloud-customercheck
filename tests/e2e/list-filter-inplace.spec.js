// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Activities / money / companies chips must fetch in place — never reload the shell.
 */
test.describe('List filters stay in place', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  async function markStay(page) {
    await page.evaluate(() => {
      window.__crmSearchStay = 1;
    });
  }

  async function expectStayed(page) {
    expect(await page.evaluate(() => window.__crmSearchStay)).toBe(1);
  }

  test('companies quick filter does not reload; URL and active chip update', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies?workspaceId=1', { ready: '#crm-companies-results' });
    await markStay(page);

    const leads = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="status=lead"]');
    await expect(leads).toBeVisible();
    const box = await leads.boundingBox();
    expect(box && box.height).toBeGreaterThanOrEqual(44);
    await leads.click();

    await expect(page).toHaveURL(/status=lead/, { timeout: 15000 });
    await expectStayed(page);
    await expect(page.locator('#crm-companies-results')).not.toHaveAttribute('aria-busy', 'true');
    await expect(page.locator('nav.crm-quick-filters a.crm-quick-filter.is-active[href*="status=lead"], nav.crm-quick-filters a.crm-quick-filter[aria-current="page"][href*="status=lead"]')).toBeVisible();
    await expect(page.locator('#crm-filter-status')).toHaveValue('lead');
  });

  test('money unpaid chip does not reload', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/money', { ready: '#crm-money-results' });
    await markStay(page);

    const unpaid = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="state=sent"]');
    await expect(unpaid).toBeVisible();
    await unpaid.click();

    await expect(page).toHaveURL(/state=sent/, { timeout: 15000 });
    await expectStayed(page);
    await expect(page.locator('#crm-money-results')).toBeVisible();
    await expect(page.locator('#crm-filter-state')).toHaveValue('sent');

    await page.setViewportSize({ width: 375, height: 812 });
    await expect(unpaid).toBeVisible();
  });

  test('activities All chip does not reload; Done still works after swap', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/activities', { ready: '#crm-activities-results' });
    await markStay(page);

    const all = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="scope=all"]');
    await expect(all).toBeVisible();
    await all.click();

    await expect(page).toHaveURL(/scope=all/, { timeout: 15000 });
    await expectStayed(page);
    await expect(page.locator('#crm-activities-results')).toBeVisible();
    await expect(page.locator('#crm-filter-scope')).toHaveValue('all');

    const done = page.locator('[data-crm-complete-activity]').first();
    if (await done.count()) {
      await expect(done).toBeVisible();
    }
  });

  test('companies chip fetch uses the fragment header', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies?workspaceId=1', { ready: '#crm-companies-results' });
    await markStay(page);

    const fragmentReq = page.waitForRequest((req) => {
      if (req.method() !== 'GET') {
        return false;
      }
      if (!req.url().includes('/apps/customercheck/companies')) {
        return false;
      }
      const headers = req.headers();
      return headers['x-customercheck-fragment'] === '1';
    }, { timeout: 15000 });

    const leads = page.locator('nav.crm-quick-filters a.crm-quick-filter[href*="status=lead"]');
    await expect(leads).toBeVisible();
    await leads.click();
    const req = await fragmentReq;
    expect(req.headers()['x-customercheck-fragment']).toBe('1');
    expect(req.headers()['prefer']).toBe('return=minimal');
    expect(req.headers()['x-requested-with']).toBe('XMLHttpRequest');
    await expect(page).toHaveURL(/status=lead/, { timeout: 15000 });
    await expectStayed(page);
  });

  test('marking an activity done does not reload the shell', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/activities', { ready: '#crm-activities-results' });

    let done = page.locator('#crm-activities-results [data-crm-complete-activity]').first();
    if ((await done.count()) === 0) {
      const suffix = Date.now().toString(36);
      await page.goto('/apps/customercheck/companies/new');
      await page.waitForSelector('#crm-company-form', { timeout: 30000 });
      await page.locator('#crm-company-name').fill(`FragDone ${suffix}`);
      await page.locator('#crm-company-form button[type="submit"]').click();
      await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
      createdCompanies.push(page.url());
      const companyMatch = page.url().match(/\/companies\/(\d+)/);
      expect(companyMatch).toBeTruthy();
      const companyId = parseInt(companyMatch[1], 10);
      const created = await page.evaluate(async ({ companyId, title }) => {
        const token =
          (window.OC && window.OC.requestToken)
          || (document.querySelector('head') || {}).getAttribute?.('data-requesttoken')
          || (document.querySelector('meta[name="requesttoken"]') || {}).content
          || '';
        const stack = document.querySelector('.crm-page-stack[data-workspace-id]');
        const ws = stack ? parseInt(stack.getAttribute('data-workspace-id') || '0', 10) : 0;
        let url = (window.OC && typeof window.OC.generateUrl === 'function')
          ? window.OC.generateUrl('/apps/customercheck/api/v1/activities')
          : '/index.php/apps/customercheck/api/v1/activities';
        if (ws > 0) {
          url += (url.indexOf('?') >= 0 ? '&' : '?') + 'workspaceId=' + encodeURIComponent(String(ws));
        }
        const res = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', requesttoken: token },
          body: JSON.stringify({ companyId, title, dueDate: '1999-01-01', type: 'todo' }),
        });
        return { ok: res.ok, status: res.status, text: await res.text() };
      }, { companyId, title: `Done in place ${suffix}` });
      expect(created.ok, created.text).toBeTruthy();
      await gotoCrm(page, '/apps/customercheck/activities', { ready: '#crm-activities-results' });
      done = page.locator('#crm-activities-results [data-crm-complete-activity]').first();
    }

    await expect(done).toBeVisible({ timeout: 15000 });
    const activityId = await done.getAttribute('data-crm-complete-activity');
    expect(activityId || '').toMatch(/^\d+$/);
    await markStay(page);

    const fragmentReq = page.waitForRequest((req) => {
      if (req.method() !== 'GET') {
        return false;
      }
      if (!req.url().includes('/apps/customercheck/activities')) {
        return false;
      }
      return req.headers()['x-customercheck-fragment'] === '1';
    }, { timeout: 15000 });

    await done.click();
    await fragmentReq;
    await expectStayed(page);
    await expect(page.locator(`[data-crm-complete-activity="${activityId}"]`)).toHaveCount(0, { timeout: 15000 });
    await expect(page.locator('#crm-activities-results')).not.toHaveAttribute('aria-busy', 'true');
  });

  test('company 360 Done patches the to-do without reload', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    const suffix = Date.now().toString(36);
    await page.goto('/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    await page.locator('#crm-company-name').fill(`TodoPatch ${suffix}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());

    const created = await page.evaluate(async ({ title }) => {
      const token =
        (window.OC && window.OC.requestToken)
        || (document.querySelector('head') || {}).getAttribute?.('data-requesttoken')
        || (document.querySelector('meta[name="requesttoken"]') || {}).content
        || '';
      const stack = document.querySelector('.crm-page-stack[data-workspace-id]');
      const ws = stack ? parseInt(stack.getAttribute('data-workspace-id') || '0', 10) : 0;
      const m = window.location.pathname.match(/\/companies\/(\d+)/);
      const companyId = m ? parseInt(m[1], 10) : 0;
      let url = (window.OC && typeof window.OC.generateUrl === 'function')
        ? window.OC.generateUrl('/apps/customercheck/api/v1/activities')
        : '/index.php/apps/customercheck/api/v1/activities';
      if (ws > 0) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'workspaceId=' + encodeURIComponent(String(ws));
      }
      const today = new Date();
      const ymd = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', requesttoken: token },
        body: JSON.stringify({ companyId, title, dueDate: ymd, type: 'todo' }),
      });
      return { ok: res.ok, status: res.status, text: await res.text() };
    }, { title: `Patch me ${suffix}` });
    expect(created.ok, created.text).toBeTruthy();

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#crm-activities', { timeout: 30000 });
    await page.locator('[data-crm-focus-tab="today"]').click().catch(() => {});
    const item = page.locator('#crm-activities .crm-todo-list__item', { hasText: suffix }).first();
    await expect(item).toBeVisible({ timeout: 15000 });
    await page.evaluate(() => {
      window.__crmSearchStay = 1;
    });
    const done = item.locator('[data-crm-complete-activity]');
    await expect(done).toBeVisible();
    const box = await done.boundingBox();
    expect(box && box.height).toBeGreaterThanOrEqual(44);
    const commandGet = page.waitForResponse(
      (r) => r.url().includes('/companies/') && r.url().includes('/command') && r.request().method() === 'GET',
      { timeout: 15000 },
    );
    await done.click();
    const cmd = await commandGet;
    expect(cmd.ok()).toBeTruthy();
    await expect(item).toHaveClass(/crm-todo-list__item--closed/, { timeout: 15000 });
    await expect(item.locator('.crm-badge.crm-badge--success')).toBeVisible();
    await expect(item.locator('[data-crm-complete-activity]')).toHaveCount(0);
    await expect(page.locator('#crm-glance-summary .crm-stat-tile').first()).toBeVisible();
    expect(await page.locator('#crm-glance-summary .crm-stat-tile').count()).toBeGreaterThanOrEqual(4);
    expect(await page.evaluate(() => window.__crmSearchStay)).toBe(1);
  });
});
