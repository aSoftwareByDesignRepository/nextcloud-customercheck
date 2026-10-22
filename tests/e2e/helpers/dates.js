// @ts-check
/**
 * Date-field helpers for the enhanced crm datepicker.
 *
 * `input[type="date"]` is progressively enhanced into a dd.mm.yyyy display
 * input + hidden ISO carrier (`input[name][data-crm-date-iso]`). Tests must
 * write the ISO value on the hidden input (fill() targets the hidden element
 * and times out), then dispatch change so listeners fire.
 */

/**
 * Set an ISO date (YYYY-MM-DD) on a datepicker-enhanced field inside a scope.
 * Falls back to a plain native input when the field was not enhanced.
 * @param {import('@playwright/test').Locator} scope  form/dialog locator
 * @param {string} name   input name, e.g. "dueDate"
 * @param {string} iso    YYYY-MM-DD
 */
export async function fillIsoDate(scope, name, iso) {
  const hidden = scope.locator(`input[name="${name}"][data-crm-date-iso]`).first();
  if (await hidden.count() > 0) {
    await hidden.evaluate((el, value) => {
      if (el instanceof HTMLInputElement) {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, iso);
    return;
  }
  await scope.locator(`input[name="${name}"]`).fill(iso);
}
