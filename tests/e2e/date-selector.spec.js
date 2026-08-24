// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';

/**
 * Date/time fields: calendar or <select> is the writer. ISO stays on the wire.
 */
test.describe('Date and time selectors', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Agreement dates upgrade to a calendar host and write ISO', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/agreements/new');

    const until = page.locator('#crm-ag-until');
    await expect(until).toBeVisible({ timeout: 30000 });
    const host = page.locator('.crm-datepicker-host', { has: until });
    await expect(host).toBeVisible();
    // HTML boolean attributes serialize as empty string, not "readonly".
    await expect(until).toHaveJSProperty('readOnly', true);
    await expect(until).toHaveAttribute('aria-haspopup', 'dialog');
    await expect(until).toHaveAttribute('inputmode', 'none');

    const hidden = host.locator('input[data-crm-date-iso]');
    await expect(hidden).toHaveCount(1);

    const toggle = host.locator('.crm-datepicker-toggle');
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-label', /calendar|Kalender/i);

    const inputBox = await until.boundingBox();
    const toggleBox = await toggle.boundingBox();
    expect(inputBox).toBeTruthy();
    expect(toggleBox).toBeTruthy();
    if (inputBox && toggleBox) {
      const inputCenterY = inputBox.y + inputBox.height / 2;
      const toggleCenterY = toggleBox.y + toggleBox.height / 2;
      expect(Math.abs(inputCenterY - toggleCenterY)).toBeLessThan(4);
      expect(toggleBox.x).toBeGreaterThanOrEqual(inputBox.x + inputBox.width - 2);
    }

    await toggle.click();

    const cal = page.locator('.crm-datepicker[role="dialog"]');
    await expect(cal).toBeVisible();
    await expect(cal.locator('.crm-datepicker__nav').first()).toHaveAttribute('aria-label', /previous|vorheriger/i);
    await expect(cal.locator('.crm-datepicker__grid thead th')).toHaveCount(7);
    await expect(cal.locator('.crm-datepicker__grid tbody tr').first().locator('td')).toHaveCount(7);
    const calBox = await cal.boundingBox();
    expect(calBox).toBeTruthy();
    expect(calBox && calBox.width).toBeGreaterThan(240);
    expect(calBox && calBox.width).toBeLessThanOrEqual(420);

    const axeOpen = await new AxeBuilder({ page })
      .include('.crm-datepicker')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    const seriousOpen = axeOpen.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(seriousOpen, JSON.stringify(seriousOpen, null, 2)).toEqual([]);

    const enabledDay = cal.locator('button.crm-datepicker__day:not([disabled])').nth(10);
    await expect(enabledDay).toBeVisible();
    const iso = await enabledDay.getAttribute('data-iso');
    expect(iso).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    await enabledDay.click();
    await expect(cal).toHaveCount(0);
    await expect(hidden).toHaveValue(iso || '');
    const display = await until.inputValue();
    expect(display).toMatch(/^\d{2}\.\d{2}\.\d{4}$/);

    await page.keyboard.press('Escape');
  });

  test('Pipeline range filters use the calendar, jobs hours use a clock select', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/pipeline');
    await expect(page.locator('#crm-pipeline-toolbar')).toBeVisible({ timeout: 15000 });

    const filterBtn = page.locator('#crm-pipeline-filter-toggle');
    await expect(filterBtn.first()).toBeAttached({ timeout: 15000 });
    await filterBtn.first().click({ force: true });
    await expect(page.locator('#crm-pipeline-filter')).toBeVisible({ timeout: 10000 });

    const from = page.locator('#crm-filter-from');
    await expect(from).toBeVisible({ timeout: 30000 });
    await expect(page.locator('.crm-datepicker-host', { has: from })).toBeVisible();
    await expect(page.locator('.crm-datepicker-host', { has: page.locator('#crm-filter-to') })).toBeVisible();

    const fromToggle = page.locator('.crm-datepicker-host', { has: from }).locator('.crm-datepicker-toggle');
    await fromToggle.click();
    const cal = page.locator('.crm-datepicker[role="dialog"]');
    await expect(cal).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(cal).toHaveCount(0);

    await gotoCrm(page, '/apps/customercheck/app-settings/jobs');
    const reminder = page.locator('#crm-reminder-hour');
    await expect(reminder).toBeVisible({ timeout: 30000 });
    await expect(reminder).toHaveJSProperty('tagName', 'SELECT');
    const reminderOpts = reminder.locator('option');
    await expect(reminderOpts).toHaveCount(24);
    await expect(page.locator('#crm-digest-hour')).toHaveJSProperty('tagName', 'SELECT');
    const fiveAm = await reminder.locator('option[value="5"]').textContent();
    expect(fiveAm).toMatch(/05:00/);
  });

  test('Phone layout keeps 44px calendar targets', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/agreements/new');
    const toggle = page.locator('#crm-ag-until').locator('xpath=ancestor::*[contains(@class,"crm-datepicker-host")]').locator('.crm-datepicker-toggle');
    await expect(toggle).toBeVisible({ timeout: 30000 });
    const box = await toggle.boundingBox();
    expect(box).toBeTruthy();
    expect(box && box.height).toBeGreaterThanOrEqual(44);
    expect(box && box.width).toBeGreaterThanOrEqual(44);
    await toggle.click();
    const day = page.locator('.crm-datepicker__day:not([disabled])').first();
    await expect(day).toBeVisible();
    const dayBox = await day.boundingBox();
    expect(dayBox && dayBox.height).toBeGreaterThanOrEqual(44);
  });

  test('Calendar opens above a native dialog (top layer), not behind it', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/pipeline');
    await expect(page.locator('#crm-pipeline-toolbar')).toBeVisible({ timeout: 15000 });

    const newDeal = page.locator('#crm-pipeline-new-deal');
    await expect(newDeal).toBeVisible({ timeout: 15000 });
    await newDeal.click();

    const dialog = page.locator('dialog.crm-native-dialog[open]').first();
    await expect(dialog).toBeVisible({ timeout: 15000 });

    // Expected close lives under Bachus “More options” (collapsed by default).
    const more = dialog.locator('details.crm-advanced, details.crm-new-deal__more').first();
    await expect(more).toBeAttached({ timeout: 10000 });
    await more.locator('summary').click();
    await expect(more).toHaveAttribute('open', '');

    const host = dialog.locator('.crm-datepicker-host').first();
    await expect(host).toBeVisible({ timeout: 15000 });
    const toggle = host.locator('.crm-datepicker-toggle');
    await expect(toggle).toBeVisible({ timeout: 10000 });
    await toggle.click();

    const cal = page.locator('.crm-datepicker[role="dialog"]');
    await expect(cal).toBeVisible({ timeout: 10000 });

    const layering = await page.evaluate(() => {
      const calendar = document.querySelector('.crm-datepicker[role="dialog"]');
      const modal = document.querySelector('dialog.crm-native-dialog[open]');
      if (!(calendar instanceof HTMLElement) || !(modal instanceof HTMLElement)) {
        return { ok: false, reason: 'missing-nodes' };
      }
      const calRect = calendar.getBoundingClientRect();
      if (calRect.width < 200 || calRect.height < 160) {
        return { ok: false, reason: 'calendar-too-small', w: calRect.width, h: calRect.height };
      }
      // Hit-test the calendar's own center — do NOT clamp into the modal box
      // (the popup often sits below/beside the dialog panel).
      const cx = calRect.left + calRect.width / 2;
      const cy = calRect.top + calRect.height / 2;
      const topEl = document.elementFromPoint(cx, cy);
      const calendarOnTop = !!(topEl && (calendar === topEl || calendar.contains(topEl)));
      const coveredByModal = !!(topEl && modal.contains(topEl) && !calendar.contains(topEl));
      const popoverOpen = calendar.matches(':popover-open');
      const insideDialog = modal.contains(calendar);
      const inTopLayer = popoverOpen || insideDialog;
      return {
        ok: calendarOnTop && inTopLayer && !coveredByModal,
        calendarOnTop,
        coveredByModal,
        inTopLayer,
        popoverOpen,
        insideDialog,
        reason: calendarOnTop ? '' : ('top=' + (topEl && (topEl.className || topEl.tagName))),
        calTop: Math.round(calRect.top),
        calLeft: Math.round(calRect.left),
      };
    });

    expect(layering, JSON.stringify(layering)).toMatchObject({
      ok: true,
      coveredByModal: false,
      insideDialog: true,
    });
    expect(layering.inTopLayer).toBe(true);

    const enabledDay = cal.locator('button.crm-datepicker__day:not([disabled])').nth(8);
    await expect(enabledDay).toBeVisible();
    await enabledDay.click();
    await expect(cal).toHaveCount(0);
    await expect(dialog).toBeVisible();

    await page.keyboard.press('Escape');
  });
});
