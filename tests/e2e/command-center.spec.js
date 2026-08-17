/**
 * Command Center E2E — AC-CC-1/2/8/10/15 (COMMAND-CENTER-SPEC §14.3).
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

async function openFirstCompany(page) {
  await page.goto('/apps/customercheck/companies');
  await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
  // Prefer a real company id URL — never /companies/new or filter chips.
  const detailHref = await page.locator('#crm-main-content a[href*="/apps/customercheck/companies/"]').evaluateAll((anchors) => {
    for (const a of anchors) {
      const href = a.getAttribute('href') || '';
      if (/\/apps\/customercheck\/companies\/\d+(\?|$)/.test(href)) {
        return href;
      }
    }
    return null;
  });
  if (!detailHref) {
    await page.goto('/apps/customercheck/companies/new');
    await page.locator('#crm-company-name, input[name="name"]').first().fill('Command Center Acme');
    await page.getByRole('button', { name: /save|create|anlegen|speichern/i }).first().click();
    await page.waitForURL(/\/companies\/\d+/, { timeout: 30000 });
    return;
  }
  await page.goto(detailHref);
  await page.waitForURL(/\/companies\/\d+/, { timeout: 30000 });
}

test.describe('Command Center', () => {
  test('company 360 section order and glance (AC-CC-1, AC-CC-2)', async ({ page }) => {
    await openFirstCompany(page);
    const main = page.locator('#crm-main-content');
    await expect(main).toBeVisible({ timeout: 30000 });

    // DOM source order (viewport y is unreliable with sticky glance + collapsed details).
    const order = [
      'crm-glance',
      'crm-identity',
      'crm-communication',
      'crm-next-steps',
      'crm-open-tasks',
      'crm-persons',
      'crm-deals',
      'crm-contracts',
      'crm-billing',
      'crm-documents',
      'crm-projects',
      'crm-tickets',
      'crm-timeline',
    ];
    const positions = await page.evaluate((ids) => {
      const html = document.documentElement.innerHTML;
      return ids.map((id) => {
        const el = document.getElementById(id);
        return { id, attached: !!el, index: html.indexOf(`id="${id}"`) };
      });
    }, order);
    let prev = -1;
    for (const row of positions) {
      expect(row.attached, row.id).toBe(true);
      expect(row.index, row.id).toBeGreaterThan(prev);
      prev = row.index;
    }
    await expect(page.locator('#crm-glance')).toBeVisible();
    await expect(page.locator('#crm-platform-links')).toBeAttached();
    await expect(page.locator('#crm-documents')).toBeAttached();
    await expect(page.locator('#crm-open-tasks')).toBeVisible();
    await page.locator('[data-crm-focus-tab="people"]').click();
    await expect(page.locator('#crm-persons')).toBeVisible();
  });

  test('companies list health columns (AC-CC-15)', async ({ page }) => {
    await page.goto('/apps/customercheck/companies');
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    const head = page.locator('table thead');
    await expect(head.getByText(/last touch|letzter kontakt/i)).toBeVisible();
    await expect(head.getByText(/next expiry|nächstes ende/i)).toBeVisible();
    await expect(head.getByText(/money|geld/i)).toBeVisible();
    await expect(head.getByText(/callback|rückruf/i)).toBeVisible();
  });

  test('today command board renders (AC-CC-8 surface)', async ({ page }) => {
    await page.goto('/apps/customercheck/today');
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    await expect(page.getByRole('heading', { name: /command board|kommandozentrale|needs attention|heute/i }).first()).toBeVisible();
  });

  test('documents section uses browse folders control', async ({ page }) => {
    await openFirstCompany(page);
    await page.locator('[data-crm-focus-tab="money"]').click();
    await expect(page.locator('#crm-documents')).toBeVisible({ timeout: 30000 });
    await expect(page.locator('#crm-link-folder')).toBeVisible();
    await expect(page.locator('#crm-link-folder')).toHaveText(/browse folders|ordner durchsuchen|ordner wählen/i);
    const hasPicker = await page.evaluate(() => !!(window.CustomerCheckFolderPicker && typeof window.CustomerCheckFolderPicker.pickFolder === 'function'));
    expect(hasPicker).toBe(true);
  });

  test('timeline shows human labels not machine keys', async ({ page }) => {
    await openFirstCompany(page);
    await page.locator('[data-crm-focus-tab="more"]').click();
    const timeline = page.locator('#crm-timeline');
    await expect(timeline).toBeVisible({ timeout: 30000 });
    // Collapsed by default (Bachus) — open to read labels.
    if (!(await timeline.evaluate((el) => el instanceof HTMLDetailsElement && el.open))) {
      await timeline.locator('summary').click();
    }
    const body = timeline.locator('.crm-card__body');
    await expect(body).toBeVisible();
    const text = await body.innerText();
    expect(text).not.toMatch(/\bemail\.logged\b/);
    expect(text).not.toMatch(/\bdeal\.stage\b/);
    expect(text).not.toMatch(/\bcompany\.created\b/);
    expect(text).not.toMatch(/\bagreement\.created\b/);
    // Either empty state or labeled cards
    const empty = /no events yet|noch keine ereignisse/i.test(text);
    const hasCards = await timeline.locator('.crm-timeline__what').count();
    expect(empty || hasCards > 0).toBeTruthy();
    if (hasCards > 0) {
      await expect(timeline.locator('.crm-timeline__when').first()).toBeVisible();
      const firstWhat = await timeline.locator('.crm-timeline__what').first().innerText();
      expect(firstWhat).not.toMatch(/\./);
    }
  });

  test('company 360 axe WCAG 2.1 AA on main (AC-CC-10)', async ({ page }) => {
    await openFirstCompany(page);
    await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
    const results = await new AxeBuilder({ page })
      .include('#crm-main-content')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
  });
});
