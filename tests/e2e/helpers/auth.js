/**
 * Shared Nextcloud login for CustomerCheck Playwright specs.
 *
 * Red-team notes:
 * - Prefer storageState from globalSetup (already authenticated).
 * - “Update needed” from sibling info.xml bumps: heal via occ upgrade, never
 *   click “Upgrade via web” on large instances.
 * - Do NOT call occ upgrade on the happy path — maintenance mode mid-submit
 *   leaves Playwright stuck on /login until timeout.
 * - Never treat Nextcloud `#app-content` alone as CRM-ready (Dashboard has it too).
 * - Never look for `#user` when storageState already redirected `/login` → Dashboard.
 */

import { ensureUpgraded } from './ensure-upgraded.js';

export async function dismissOrFailOnUpdateNeeded(page) {
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

/**
 * Navigate to a CustomerCheck path and heal “Update needed” from sibling app bumps.
 * Prefer this over bare page.goto for CRM pages after login.
 */
export async function gotoCrm(page, path, { ready = '#crm-main-content', timeout = 30000 } = {}) {
  await page.goto(path, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await dismissOrFailOnUpdateNeeded(page);
  if (page.url().includes('/login')) {
    throw new Error(`CRM navigation to ${path} bounced to login`);
  }
  if (ready) {
    await page.waitForSelector(ready, { timeout });
  }
}

/** @returns {Promise<boolean>} true when CustomerCheck shell is visible */
async function ensureCrmShell(page) {
  await page.goto('/apps/customercheck/today', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await dismissOrFailOnUpdateNeeded(page);
  if (page.url().includes('/login')) {
    return false;
  }
  await page.locator('#crm-main-content').waitFor({ state: 'visible', timeout: 30000 });
  return true;
}

export async function login(page, { username, password }) {
  if (await ensureCrmShell(page).catch(() => false)) {
    return;
  }

  await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await dismissOrFailOnUpdateNeeded(page);

  // storageState / existing session: /login bounces to Dashboard — do not hunt #user.
  if (!page.url().includes('/login')) {
    if (!(await ensureCrmShell(page))) {
      throw new Error('Session is authenticated but CustomerCheck shell did not load.');
    }
    return;
  }

  const startUpdate = page.getByRole('button', { name: /start update|update starten/i });
  if (await startUpdate.isVisible({ timeout: 1500 }).catch(() => false)) {
    await startUpdate.click();
    await page.waitForTimeout(2000);
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 45000 });
    await dismissOrFailOnUpdateNeeded(page);
    if (!page.url().includes('/login')) {
      if (!(await ensureCrmShell(page))) {
        throw new Error('Session is authenticated but CustomerCheck shell did not load.');
      }
      return;
    }
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
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 45000 });
    await dismissOrFailOnUpdateNeeded(page);
    if (!page.url().includes('/login')) {
      if (!(await ensureCrmShell(page))) {
        throw new Error('Session is authenticated but CustomerCheck shell did not load.');
      }
      return;
    }
    await fillAndSubmit();
  }

  if (!(await ensureCrmShell(page))) {
    throw new Error('Login succeeded but CustomerCheck shell did not load.');
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
