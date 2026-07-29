/**
 * Shared Nextcloud login for CustomerCheck Playwright specs.
 *
 * Red-team notes:
 * - Prefer storageState from globalSetup (already authenticated).
 * - “Update needed” from sibling info.xml bumps: heal via occ upgrade, never
 *   click “Upgrade via web” on large instances.
 * - Do NOT call occ upgrade on the happy path — maintenance mode mid-submit
 *   leaves Playwright stuck on /login until timeout.
 */

import { ensureUpgraded } from './ensure-upgraded.js';

async function dismissOrFailOnUpdateNeeded(page) {
  const updateHeading = page.getByRole('heading', { name: /update needed|aktualisierung erforderlich/i });
  if (!(await updateHeading.isVisible({ timeout: 800 }).catch(() => false))) {
    return;
  }
  ensureUpgraded();
  await page.reload({ waitUntil: 'domcontentloaded' });
  if (await updateHeading.isVisible({ timeout: 2000 }).catch(() => false)) {
    throw new Error(
      'Nextcloud still shows “Update needed” after occ upgrade. '
      + 'Run `docker compose exec -u www-data nextcloud php occ upgrade` manually.',
    );
  }
}

export async function login(page, { username, password }) {
  // Fast path: storageState / existing session already inside the app.
  await page.goto('/apps/customercheck/today', { waitUntil: 'domcontentloaded' });
  await dismissOrFailOnUpdateNeeded(page);
  if (!page.url().includes('/login') && await page.locator('#crm-main-content, #app-content').first().isVisible().catch(() => false)) {
    return;
  }

  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await dismissOrFailOnUpdateNeeded(page);

  const startUpdate = page.getByRole('button', { name: /start update|update starten/i });
  if (await startUpdate.isVisible({ timeout: 1500 }).catch(() => false)) {
    await startUpdate.click();
    await page.waitForTimeout(2000);
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await dismissOrFailOnUpdateNeeded(page);
  }

  const fillAndSubmit = async () => {
    await dismissOrFailOnUpdateNeeded(page);
    const userInput = page
      .locator('#user')
      .or(page.locator('input[name="user"]'))
      .or(page.getByRole('textbox', { name: /account name|email|benutzername|e-mail|login/i }))
      .first();
    await userInput.waitFor({ state: 'visible', timeout: 30000 });
    await userInput.fill(username);
    const passInput = page
      .locator('#password')
      .or(page.locator('input[name="password"]'))
      .or(page.getByRole('textbox', { name: /^password$|^passwort$/i }))
      .first();
    await passInput.fill(password);
    const wrong = page.getByText(/wrong password|wrong username|ungültig|throttle|zu viele/i);
    const submit = page
      .locator('form[name="login"] button[type="submit"]')
      .or(page.locator('button[data-login-form-submit]'))
      .or(page.getByRole('button', { name: /^log in$|^anmelden$/i }))
      .first();
    await submit.click();
    try {
      await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 45000 });
    } catch (err) {
      const msg = ((await wrong.textContent().catch(() => '')) || '').trim();
      if (msg) {
        throw new Error('Login rejected: ' + msg);
      }
      throw err;
    }
    await dismissOrFailOnUpdateNeeded(page);
  };

  try {
    await fillAndSubmit();
  } catch (err) {
    if (String(err?.message || err).includes('Update needed') || String(err?.message || err).includes('Login rejected')) {
      throw err;
    }
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await dismissOrFailOnUpdateNeeded(page);
    await fillAndSubmit();
  }
}

export function credsFromEnv(role) {
  const u = process.env[`NC_${role}_USER`];
  const p = process.env[`NC_${role}_PASS`];
  if (!u || !p) {
    throw new Error(`Missing env vars NC_${role}_USER / NC_${role}_PASS`);
  }
  return { username: u, password: p };
}
