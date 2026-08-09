/**
 * AC-CC-6/7/8/11/17/20/24 — Command Center binary surfaces.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

async function createCompany(page, name) {
  await page.goto('/apps/customercheck/companies/new');
  await page.waitForSelector('#crm-company-form', { timeout: 30000 });
  await page.locator('#crm-company-name').fill(name);
  await page.locator('#crm-company-form').evaluate((form) => {
    if (form instanceof HTMLFormElement) form.requestSubmit();
  });
  await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
}

test.describe('Command Center binary ACs', () => {
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
    await expect(page.locator('#crm-glance-summary .crm-stat-tile')).toHaveCount(8, { timeout: 10000 });
    await expect(page.locator('#crm-billing-body .crm-billing-strip .crm-stat-tile')).toHaveCount(3, { timeout: 10000 });
    await expect(page.locator('#crm-glance-density-title')).toBeVisible();
    const kpis = page.locator('#crm-glance .crm-glance-kpi');
    await expect(kpis).toHaveCount(3);
    for (let i = 0; i < 3; i += 1) {
      const kpi = kpis.nth(i);
      await expect(kpi.locator('.crm-glance-kpi__value')).toBeVisible();
      await expect(kpi.locator('.crm-glance-kpi__cta')).toBeVisible();
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
    await page.locator('#crm-documents').scrollIntoViewIfNeeded();
    await expect(page.locator('#crm-unlink-folder')).toBeHidden();
    await page.locator('#crm-create-folder').click({ force: true });
    await expect(page.locator('#crm-unlink-folder')).toBeVisible({ timeout: 30000 });
    await page.locator('#crm-unlink-folder').click({ force: true });
    await page.locator('.confirm-dialog__confirm').click({ timeout: 10000 });
    await expect(page.locator('#crm-unlink-folder')).toBeHidden({ timeout: 30000 });
  });

  test('schedule callback appears on command board (AC-CC-8)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const suffix = Date.now().toString(36);
    await createCompany(page, `Callback Co ${suffix}`);
    await page.locator('#crm-schedule-callback').click({ force: true });
    const form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    // Due yesterday → overdue callbacks section
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    const ymd = yesterday.toISOString().slice(0, 10);
    await form.locator('[name="dueDate"]').fill(ymd);
    await form.locator('[name="reason"]').fill('Audit callback');
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const t0 = Date.now();
    await page.goto('/apps/customercheck/today');
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    await expect(page.getByText(/Callback Co|Audit callback|Callback:/i).first()).toBeVisible({ timeout: 60000 });
    expect(Date.now() - t0).toBeLessThan(60000);
  });

  test('open tasks panel + one-way copy (AC-CC-17/20)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await createCompany(page, `Tasks Co ${Date.now().toString(36)}`);
    await expect(page.locator('#crm-open-tasks')).toBeVisible();
    await expect(page.locator('#crm-open-tasks')).toContainText(/Done in CustomerCheck is what counts|in CustomerCheck zählt/i);
    await page.locator('#crm-new-task').click({ force: true });
    const form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    await form.locator('[name="title"]').fill('Follow up tomorrow');
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    await form.locator('[name="dueDate"]').fill(tomorrow.toISOString().slice(0, 10));
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#crm-open-tasks-body')).toContainText(/Follow up tomorrow/i, { timeout: 15000 });
  });

  test('command board Done sends updatedAt lock (AC-CC-8 lock)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const suffix = Date.now().toString(36);
    await createCompany(page, `Done Lock ${suffix}`);
    await page.locator('#crm-schedule-callback').click({ force: true });
    const form = page.locator('form[id^="crm-prompt-"]');
    await expect(form).toBeVisible({ timeout: 10000 });
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    await form.locator('[name="dueDate"]').fill(yesterday.toISOString().slice(0, 10));
    await form.locator('[name="reason"]').fill('Lock done');
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await page.goto('/apps/customercheck/today');
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    const done = page.locator('[data-crm-complete-activity]').first();
    await expect(done).toBeVisible({ timeout: 60000 });
    const updatedAt = await done.getAttribute('data-updated-at');
    expect(updatedAt || '').toMatch(/\d{4}-\d{2}-\d{2}/);

    const donePromise = page.waitForResponse(
      (r) => r.url().includes('/activities/') && r.url().includes('/done') && r.request().method() === 'POST',
      { timeout: 30000 },
    );
    await done.click({ force: true });
    const resp = await donePromise;
    expect(resp.ok()).toBeTruthy();
    const body = resp.request().postDataJSON();
    expect(body.updatedAt).toBeTruthy();
    expect(String(body.updatedAt)).toContain(updatedAt.slice(0, 10));
  });

  test('platform strip only enabled chips (AC-CC-24) + today axe', async ({ page }) => {
    await page.goto('/apps/customercheck/companies');
    const link = page.locator('#crm-main-content a[href*="/apps/customercheck/companies/"]').first();
    if ((await link.count()) === 0) {
      await createCompany(page, `Platform Co ${Date.now().toString(36)}`);
    } else {
      await link.click();
      await page.waitForURL(/\/companies\/\d+/, { timeout: 30000 });
    }
    await expect(page.locator('#crm-platform-links')).toBeAttached();
    const chips = page.locator('#crm-platform-links a.crm-platform-links__chip');
    const n = await chips.count();
    for (let i = 0; i < n; i++) {
      const href = await chips.nth(i).getAttribute('href');
      expect(href || '').toMatch(/\/apps\//);
    }

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
