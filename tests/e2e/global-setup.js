/**
 * Playwright globalSetup — clear “Update needed”, then persist an admin session.
 *
 * Why storageState: calling occ upgrade around every login races maintenance mode
 * (sibling apps bump info.xml in this monorepo). One authenticated storage file
 * keeps specs off the Vue login form for most of the suite.
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ensureUpgraded } from './helpers/ensure-upgraded.js';

const ROOT = dirname(fileURLToPath(import.meta.url));
export const STORAGE_STATE_PATH = join(ROOT, '.auth', 'admin.json');

async function loginOnce(page, { username, password, baseURL }) {
  await page.goto(baseURL + '/login', { waitUntil: 'domcontentloaded' });
  const updateHeading = page.getByRole('heading', { name: /update needed|aktualisierung erforderlich/i });
  if (await updateHeading.isVisible({ timeout: 800 }).catch(() => false)) {
    ensureUpgraded();
    await page.goto(baseURL + '/login', { waitUntil: 'domcontentloaded' });
  }
  // Local farm CSP form-action 'self' sometimes blocks absolute action URLs —
  // force a same-document relative action before submit.
  await page.evaluate(() => {
    const form = document.querySelector('form');
    if (form) {
      form.setAttribute('action', '/login');
    }
  });
  const userInput = page
    .locator('#user')
    .or(page.locator('input[name="user"]'))
    .or(page.getByRole('textbox', { name: /account name|email|benutzername|e-mail|login/i }))
    .first();
  await userInput.waitFor({ state: 'visible', timeout: 45000 });
  await userInput.fill(username);
  const passInput = page
    .locator('#password')
    .or(page.locator('input[name="password"]'))
    .or(page.getByRole('textbox', { name: /^password$|^passwort$/i }))
    .first();
  await passInput.fill(password);
  const submit = page
    .locator('form[name="login"] button[type="submit"]')
    .or(page.locator('button[data-login-form-submit]'))
    .or(page.getByRole('button', { name: /^log in$|^anmelden$/i }))
    .first();
  await submit.click();
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 60000, waitUntil: 'domcontentloaded' });
}

export default async function globalSetup(config) {
  ensureUpgraded();

  const baseURL = config?.projects?.[0]?.use?.baseURL
    || config?.use?.baseURL
    || process.env.NC_BASE_URL
    || 'http://localhost:8081';
  const username = process.env.NC_ADMIN_USER;
  const password = process.env.NC_ADMIN_PASS;
  if (!username || !password) {
    // Specs that need creds will skip; still clear Update needed.
    return;
  }

  mkdirSync(dirname(STORAGE_STATE_PATH), { recursive: true });
  // Local farm CSP sometimes blocks absolute form-action to the same host:port
  // ("form-action 'self'" false negative). Disable web security only for setup login.
  const browser = await chromium.launch({
    args: ['--disable-web-security', '--disable-features=IsolateOrigins,site-per-process'],
  });
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  try {
    await loginOnce(page, { username, password, baseURL });
    await context.storageState({ path: STORAGE_STATE_PATH });
  } catch (err) {
    // Keep a pre-seeded storage state if present (CSP / transient login races).
    const { existsSync } = await import('node:fs');
    if (existsSync(STORAGE_STATE_PATH)) {
      console.warn('[globalSetup] loginOnce failed; reusing existing storage state:', err?.message || err);
    } else {
      throw err;
    }
  } finally {
    await browser.close();
  }
}
