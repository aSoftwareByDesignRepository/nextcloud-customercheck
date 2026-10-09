/**
 * AC-CC-6/7/8/11/17/20/24 — Command Center binary surfaces.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { fillIsoDate } from './helpers/dates.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

const createdCompanies = [];

async function createCompany(page, name) {
  await page.goto('/apps/customercheck/companies/new');
  await page.waitForSelector('#crm-company-form', { timeout: 30000 });
  await page.locator('#crm-company-name').fill(name);
  await page.locator('#crm-company-form').evaluate((form) => {
    if (form instanceof HTMLFormElement) form.requestSubmit();
  });
  await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
  createdCompanies.push(page.url());
}

/** Company id from /companies/{id} URL after createCompany / navigation. */
function companyIdFromUrl(page) {
  const m = page.url().match(/\/companies\/(\d+)/);
  if (!m) {
    throw new Error('Expected company detail URL, got ' + page.url());
  }
  return parseInt(m[1], 10);
}

/**
 * Schedule a callback with an explicit due date via the Command API
 * (UI “Call tomorrow” is one-tap for tomorrow only — no date form).
 */
async function scheduleCallbackApi(page, companyId, { dueDate, reason }) {
  const result = await page.evaluate(async ({ companyId, dueDate, reason }) => {
    const token =
      (window.OC && window.OC.requestToken)
      || (document.querySelector('head') || {}).getAttribute?.('data-requesttoken')
      || (document.querySelector('meta[name="requesttoken"]') || {}).content
      || '';
    const stack = document.querySelector('.crm-page-stack[data-workspace-id]');
    const ws = stack ? parseInt(stack.getAttribute('data-workspace-id') || '0', 10) : 0;
    let url = (window.OC && typeof window.OC.generateUrl === 'function')
      ? window.OC.generateUrl('/apps/customercheck/api/v1/companies/' + companyId + '/callbacks')
      : '/index.php/apps/customercheck/api/v1/companies/' + companyId + '/callbacks';
    if (ws > 0) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'workspaceId=' + encodeURIComponent(String(ws));
    }
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        requesttoken: token,
      },
      body: JSON.stringify({ dueDate, reason }),
    });
    const text = await res.text();
    return { ok: res.ok, status: res.status, text };
  }, { companyId, dueDate, reason });
  expect(result.ok, result.text).toBeTruthy();
}

test.describe('Command Center binary ACs', () => {
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('timed warm path companies → 360 glance ≤10s (AC-CC-11)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/apps/customercheck/companies');
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    let openRow = page.locator('#crm-main-content table tbody tr .crm-table-actions a.crm-btn--primary').first();
    if ((await openRow.count()) === 0) {
      await createCompany(page, `Warm Path ${Date.now().toString(36)}`);
      await page.goto('/apps/customercheck/companies');
      await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
      openRow = page.locator('#crm-main-content table tbody tr .crm-table-actions a.crm-btn--primary').first();
    }
    const t0 = Date.now();
    await openRow.click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    await expect(page.locator('#crm-glance')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#crm-glance-summary .crm-glance-dl.crm-stat-strip')).toBeVisible({ timeout: 10000 });
    // Four calm glance tiles (primary/nextAction/money/renewal) — pinned by
    // tests/js/glance-summary.test.js + GlanceSummaryContractTest.
    await expect(page.locator('#crm-glance-summary .crm-stat-tile')).toHaveCount(4, { timeout: 10000 });
    // Billing lives on the Money focus panel — activate it before asserting visibility.
    await page.locator('[data-crm-focus-tab="money"]').click();
    await expect(page.locator('#crm-360-panel-money')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#crm-billing-body .crm-billing-strip .crm-stat-tile').first()).toBeVisible({ timeout: 10000 });
    const tileCount = await page.locator('#crm-billing-body .crm-billing-strip .crm-stat-tile').count();
    expect(tileCount).toBeGreaterThanOrEqual(3);
    await expect(page.locator('#crm-billing-body .crm-billing-honesty')).toBeVisible();
    await expect(page.locator('#crm-billing-body .crm-billing-actions a[href="#crm-contracts"]')).toBeVisible();
    // Glance density sits above the focus tabs — always in the pin, not panel-gated.
    await page.locator('#crm-glance').scrollIntoViewIfNeeded();
    await expect(page.locator('#crm-glance-density-title')).toBeVisible();
    const calmDensity = page.locator('#crm-glance details.crm-glance-density--calm');
    const attentionDensity = page.locator('#crm-glance .crm-glance-density--attention');
    if (await calmDensity.count()) {
      await expect(calmDensity.locator('.crm-glance-density__chevron')).toBeVisible();
      await expect(calmDensity).not.toHaveAttribute('open', '');
      await calmDensity.locator('summary').click();
      await expect(calmDensity).toHaveAttribute('open', '');
      await expect(calmDensity.locator('.crm-glance-density__lead')).toContainText(/Projects, tickets|Projekte, Tickets/i);
    } else {
      await expect(attentionDensity).toBeVisible();
      await expect(attentionDensity.locator('.crm-glance-density__lead')).toContainText(/Open a number|Zahl öffnen|Öffne eine Zahl|jump|spring/i);
    }
    const kpis = page.locator('#crm-glance .crm-glance-kpi');
    await expect(kpis).toHaveCount(3);
    // Stat-strip style: on a typical desktop viewport the first two sit on one row.
    const box0 = await kpis.nth(0).boundingBox();
    const box1 = await kpis.nth(1).boundingBox();
    expect(box0 && box1, 'KPI tiles laid out').toBeTruthy();
    expect(Math.abs(box1.y - box0.y)).toBeLessThan(24);
    expect(box1.x).toBeGreaterThan(box0.x + 8);
    for (let i = 0; i < 3; i += 1) {
      const kpi = kpis.nth(i);
      // Value/CTA spans are aria-hidden (name lives on the link) — still must be laid out.
      await expect(kpi.locator('.crm-glance-kpi__value')).toBeAttached();
      await expect(kpi.locator('.crm-glance-kpi__label')).toBeAttached();
      await expect(kpi.locator('.crm-glance-kpi__cta')).toBeAttached();
      const box = await kpi.boundingBox();
      expect(box, 'KPI tile laid out').toBeTruthy();
      expect(box.height).toBeGreaterThanOrEqual(44);
    }
    const unpaid = page.locator('#crm-glance .crm-glance-kpi').filter({ hasText: /Unpaid|Unbezahlt/i }).first();
    await expect(unpaid).toBeVisible();
    const uCls = (await unpaid.getAttribute('class')) || '';
    const uVal = (await unpaid.locator('.crm-glance-kpi__value').innerText()).trim();
    if (uVal === '0') {
      expect(uCls).not.toContain('crm-glance-kpi--attention');
    }
    const axe = await new AxeBuilder({ page })
      .include('#crm-glance')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
    expect(Date.now() - t0).toBeLessThan(10000);
  });

  test('documents create folder then unlink (AC-CC-6)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await createCompany(page, `Docs Co ${Date.now().toString(36)}`);
    await page.locator('[data-crm-focus-tab="money"]').click();
    await expect(page.locator('#crm-360-panel-money')).toBeVisible({ timeout: 10000 });
    await page.locator('#crm-documents').scrollIntoViewIfNeeded();
    await expect(page.locator('#crm-unlink-folder')).toBeHidden();
    // Create lives under More — open the disclosure, then create.
    await page.locator('#crm-documents .crm-more-menu > summary').click();
    await expect(page.locator('#crm-create-folder')).toBeVisible();
    await page.locator('#crm-create-folder').click();
    await expect(page.locator('#crm-unlink-folder')).toBeVisible({ timeout: 30000 });
    await page.locator('#crm-unlink-folder').click();
    await page.locator('.confirm-dialog__confirm').click({ timeout: 10000 });
    await expect(page.locator('#crm-unlink-folder')).toBeHidden({ timeout: 30000 });
  });

  test('schedule callback appears on command board (AC-CC-8)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const suffix = Date.now().toString(36);
    const companyName = `Callback Co ${suffix}`;
    await createCompany(page, companyName);
    const companyId = companyIdFromUrl(page);
    await page.locator('[data-crm-focus-tab="today"]').click();
    await expect(page.locator('#crm-360-panel-today')).toBeVisible({ timeout: 10000 });
    // Product: Call tomorrow is one-tap (no date form).
    const schedulePromise = page.waitForResponse(
      (r) => r.url().includes('/callbacks') && r.request().method() === 'POST',
      { timeout: 15000 },
    );
    await page.locator('#crm-schedule-callback').click();
    const scheduleResp = await schedulePromise;
    expect(scheduleResp.ok()).toBeTruthy();

    // Today’s command board prioritises due/overdue — seed yesterday so it surfaces now.
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    await scheduleCallbackApi(page, companyId, {
      dueDate: yesterday.toISOString().slice(0, 10),
      reason: `Audit callback ${suffix}`,
    });

    const t0 = Date.now();
    // Prefer Today overdue queue; fall back to company To-do when the board truncates.
    await page.goto('/apps/customercheck/today');
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    const overdue = page.locator('#crm-cmd-overdue_callbacks');
    if (await overdue.count()) {
      await overdue.scrollIntoViewIfNeeded();
      await expect(overdue).toContainText(new RegExp(suffix, 'i'), { timeout: 15000 });
    } else {
      await page.goto('/apps/customercheck/companies/' + companyId);
      await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
      await page.locator('[data-crm-focus-tab="today"]').click();
      await expect(page.locator('#crm-360-panel-today')).toBeVisible({ timeout: 10000 });
      await expect(page.locator('#crm-activities')).toContainText(new RegExp(suffix, 'i'), { timeout: 15000 });
    }
    expect(Date.now() - t0).toBeLessThan(60000);
  });

  test('open tasks panel + one-way copy (AC-CC-17/20)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await createCompany(page, `Tasks Co ${Date.now().toString(36)}`);
    await page.locator('[data-crm-focus-tab="today"]').click();
    await expect(page.locator('#crm-360-panel-today')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#crm-open-tasks')).toBeVisible();
    await expect(page.locator('#crm-open-tasks')).toContainText(/Done in CustomerCheck is what counts|in CustomerCheck zählt/i);
    await page.locator('#crm-open-tasks > summary').click();
    await expect(page.locator('#crm-open-tasks')).toHaveAttribute('open', '');
    await page.locator('#crm-new-task').click();
    const form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await form.locator('[name="title"]').fill('Follow up tomorrow');
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    await fillIsoDate(form, 'dueDate', tomorrow.toISOString().slice(0, 10));
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-open-tasks-body')).toContainText(/Follow up tomorrow/i, { timeout: 15000 });
  });

  test('command board Done sends updatedAt lock (AC-CC-8 lock)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const suffix = Date.now().toString(36);
    await createCompany(page, `Done Lock ${suffix}`);
    const companyId = companyIdFromUrl(page);
    // Overdue via API — UI one-tap only schedules tomorrow.
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    await scheduleCallbackApi(page, companyId, {
      dueDate: yesterday.toISOString().slice(0, 10),
      reason: `Lock done ${suffix}`,
    });

    // Complete from company 360 — Today board can truncate past 500 open activities.
    await page.goto('/apps/customercheck/companies/' + companyId);
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    await page.locator('[data-crm-focus-tab="today"]').click();
    await expect(page.locator('#crm-360-panel-today')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#crm-activities')).toContainText(new RegExp(suffix, 'i'), { timeout: 15000 });
    // Title is sibling of the button — scope to the list item containing the suffix.
    const item = page.locator('#crm-activities .crm-todo-list__item', { hasText: suffix }).first();
    await expect(item).toBeVisible({ timeout: 15000 });
    const doneBtn = item.locator('[data-crm-complete-activity]');
    await expect(doneBtn).toBeVisible();
    const updatedAt = await doneBtn.getAttribute('data-updated-at');
    expect(updatedAt || '').toMatch(/\d{4}-\d{2}-\d{2}/);

    const donePromise = page.waitForResponse(
      (r) => r.url().includes('/activities/') && r.url().includes('/done') && r.request().method() === 'POST',
      { timeout: 30000 },
    );
    await doneBtn.click();
    const resp = await donePromise;
    expect(resp.ok()).toBeTruthy();
    const body = resp.request().postDataJSON();
    expect(body.updatedAt).toBeTruthy();
    expect(String(body.updatedAt)).toContain(updatedAt.slice(0, 10));
  });

  test('platform strip only enabled chips (AC-CC-24) + today axe', async ({ page }) => {
    // Always create — list-row links can be filters/other routes and flake waitForURL.
    await createCompany(page, `Platform Co ${Date.now().toString(36)}`);
    await expect(page.locator('#crm-platform-links')).toBeAttached();
    await expect(page.locator('#crm-platform-links')).toHaveAttribute('data-platform-painted', '1', { timeout: 20000 });
    const companyId = companyIdFromUrl(page);
    const api = await page.evaluate(async (id) => {
      const token =
        (window.OC && window.OC.requestToken)
        || (document.querySelector('head') || {}).getAttribute?.('data-requesttoken')
        || (document.querySelector('meta[name="requesttoken"]') || {}).content
        || '';
      const stack = document.querySelector('.crm-page-stack[data-workspace-id]');
      const ws = stack ? parseInt(stack.getAttribute('data-workspace-id') || '0', 10) : 0;
      let url = (window.OC && typeof window.OC.generateUrl === 'function')
        ? window.OC.generateUrl('/apps/customercheck/api/v1/companies/' + id + '/platform-links')
        : '/apps/customercheck/api/v1/companies/' + id + '/platform-links';
      if (ws > 0) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'workspaceId=' + encodeURIComponent(String(ws));
      }
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', requesttoken: token },
      });
      let json = null;
      try {
        json = await res.json();
      } catch (e) {
        json = { parseError: true };
      }
      return { ok: res.ok, status: res.status, json };
    }, companyId);
    expect(api.ok, JSON.stringify({ status: api.status, json: api.json })).toBeTruthy();
    const payload = api.json;
    const data = payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)
      ? payload.data
      : {};
    const keys = Object.keys(data);
    const chips = page.locator('#crm-platform-links a.crm-platform-links__chip');
    await expect(chips).toHaveCount(keys.length);
    const moreTab = page.locator('[data-crm-focus-tab="more"]');
    if ((await moreTab.count()) > 0) {
      await moreTab.click();
    }
    if (keys.length === 0) {
      await expect(page.locator('#crm-platform-links')).toBeHidden();
    } else {
      await expect(page.locator('#crm-platform-links')).toBeVisible();
      await expect(page.locator('#crm-platform-links-lead')).toBeVisible();
      for (const key of keys) {
        expect(data[key].available).toBe(true);
        const href = String(data[key].url || '');
        expect(href).toMatch(/\/apps\//);
        await expect(page.locator('#crm-platform-links a.crm-platform-links__chip[href="' + href + '"]')).toHaveCount(1);
        expect(href).not.toMatch(/javascript:/i);
      }
    }
    await expect(page.locator('#crm-platform-links a.crm-platform-links__chip[href*="/apps/tasks/"]')).toHaveCount(
      keys.includes('tasks') ? 1 : 0,
    );
    await expect(page.locator('#crm-platform-links a.crm-platform-links__chip[href*="/apps/calendar/"]')).toHaveCount(
      keys.includes('calendar') ? 1 : 0,
    );

    await page.goto('/apps/customercheck/today');
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});
