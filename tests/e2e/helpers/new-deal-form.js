// @ts-check

/**
 * Shared new-deal dialog helpers (pipeline + company 360).
 */

/** @param {import('@playwright/test').Page} page */
export function openNewDealForm(page) {
  return page.locator('dialog.crm-native-dialog[open] form.crm-form--new-deal').first();
}

/** @param {import('@playwright/test').Page} page */
export async function waitForNewDealForm(page) {
  const dialog = page.locator('dialog.crm-native-dialog[open]').first();
  await dialog.waitFor({ state: 'visible', timeout: 15000 });
  const form = dialog.locator('form.crm-form--new-deal');
  await form.waitFor({ state: 'visible', timeout: 15000 });
  return form;
}

/**
 * Post-create nudge — skip optional Today step so specs can assert reload.
 * @param {import('@playwright/test').Page} page
 */
export async function dismissNextStepNudge(page) {
  const nextForm = page.locator('form.crm-next-step-form');
  const appeared = await nextForm.waitFor({ state: 'visible', timeout: 15000 }).then(() => true).catch(() => false);
  if (!appeared) {
    await page.waitForLoadState('networkidle');
    return;
  }
  const skip = nextForm.locator('[data-skip]').first();
  await skip.waitFor({ state: 'visible', timeout: 5000 });
  await skip.click();
  await page.waitForFunction(() => !document.querySelector('form.crm-next-step-form'), { timeout: 20000 });
  await page.waitForLoadState('networkidle');
}

/**
 * Fill due date on next-step form (datepicker may hide the native input).
 * @param {import('@playwright/test').Locator} nextForm
 * @param {string} iso YYYY-MM-DD
 */
export async function fillNextStepDueDate(nextForm, iso) {
  const hidden = nextForm.locator('input[name="dueDate"]').first();
  await hidden.evaluate((el, value) => {
    if (el instanceof HTMLInputElement) {
      el.value = value;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, iso);
}

/**
 * Submit new-deal form and dismiss post-create nudge.
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} form
 */
export async function submitNewDealAndDismiss(page, form) {
  const createDeal = page.waitForResponse(
    (r) => r.request().method() === 'POST' && /\/deals(\?|$)/.test(r.url()) && r.status() < 400,
    { timeout: 30000 },
  );
  await form.locator('button[type="submit"]').click();
  await createDeal.catch(() => null);
  await dismissNextStepNudge(page);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ title: string, notes?: string, leadSource?: string }} payload
 */
export async function submitNewDealForm(page, payload) {
  const form = await waitForNewDealForm(page);
  await form.locator('[name="title"]').fill(payload.title);
  if (payload.notes) {
    await form.locator('[name="notes"]').fill(payload.notes);
  }
  if (payload.leadSource) {
    await form.locator('[name="leadSource"]').selectOption(payload.leadSource);
  }
  await submitNewDealAndDismiss(page, form);
}

/**
 * Filter panel toggle — wait until ListToolbar has bound (avoids flaky first click).
 * @param {import('@playwright/test').Page} page
 * @param {string} toggleId e.g. crm-agreements-filter-toggle
 * @param {string} panelId e.g. crm-agreements-filter
 * @param {string} _openClass unused; kept for call-site clarity
 */
export async function openFilterPanel(page, toggleId, panelId, _openClass) {
  await page.waitForFunction(() => !!(window.CustomerCheckListToolbar && typeof window.CustomerCheckListToolbar.bind === 'function'), { timeout: 15000 });
  const toggle = page.locator(`#${toggleId}`);
  await toggle.waitFor({ state: 'visible', timeout: 10000 });
  await toggle.click();
  await page.waitForFunction(
    (id) => {
      const panel = document.getElementById(id);
      return panel instanceof HTMLElement && !panel.hasAttribute('hidden');
    },
    panelId,
    { timeout: 15000 },
  );
  await page.locator(`#${panelId}`).waitFor({ state: 'visible', timeout: 5000 });
}
