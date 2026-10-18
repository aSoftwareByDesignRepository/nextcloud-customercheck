/**
 * Playwright globalSetup — clear “Update needed”, then persist an admin session.
 *
 * Host Chromium + overwritehost=10.0.2.2 (emulator alias) breaks CSP form-action
 * when the page is on localhost/127.0.0.1. Prefer a pre-seeded storageState.
 * Fresh login temporarily aligns overwrite.* to the Playwright base, then restores.
 */
import { chromium } from '@playwright/test';
import { mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { ensureUpgraded } from './helpers/ensure-upgraded.js';

import { acquireSharedInstanceLock } from './shared-instance-lock.mjs'

const ROOT = dirname(fileURLToPath(import.meta.url));
// Concurrent farm lanes isolate their auth cache via E2E_STORAGE_STATE —
// a shared .auth/admin.json gets clobbered mid-run by a parallel setup.
export const STORAGE_STATE_PATH = process.env.E2E_STORAGE_STATE
	|| join(ROOT, '.auth', 'admin.json');
const NC_COMPOSE_ROOT = join(ROOT, '..', '..', '..', '..');

function occGet(key) {
	const r = spawnSync(
		'docker',
		['compose', 'exec', '-u', 'www-data', '-T', 'nextcloud', 'php', 'occ', 'config:system:get', key],
		{ cwd: NC_COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
	);
	return (r.stdout || '').trim();
}

function occSet(key, value) {
	spawnSync(
		'docker',
		[
			'compose', 'exec', '-u', 'www-data', '-T', 'nextcloud', 'php', 'occ',
			'config:system:set', key, '--value=' + value,
		],
		{ cwd: NC_COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
	);
}

function occUserSet(uid, key, value) {
	return spawnSync(
		'docker',
		[
			'compose', 'exec', '-u', 'www-data', '-T', 'nextcloud', 'php', 'occ',
			'user:setting', uid, 'core', key, value,
		],
		{ cwd: NC_COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
	);
}

// Shared-lab self-heal: sibling lanes' locale sweeps can leave the shared
// e2e user's `core/lang` on an arbitrary locale (observed: da/sv leftovers
// poisoning an entire run's EN|DE text assertions). The pin alone is not
// proof — the served page lang is asserted here so a polluted run dies at
// setup instead of after an hour of false failures.
function assertServedLangEn(servedLang) {
	if (!/^en/i.test(servedLang || '')) {
		throw new Error(`[globalSetup] served html lang="${servedLang}" after core/lang=en pin — refusing to run a locale-polluted suite`);
	}
}

function hostPortFromBase(baseURL) {
	try {
		return new URL(baseURL).host;
	} catch {
		return '127.0.0.1:8081';
	}
}

async function storageStillValid(baseURL) {
	if (!existsSync(STORAGE_STATE_PATH)) {
		return false;
	}
	const browser = await chromium.launch({ headless: true });
	let ok = false;
	let servedLang = '';
	try {
		const context = await browser.newContext({
			baseURL,
			storageState: STORAGE_STATE_PATH,
		});
		const page = await context.newPage();
		await page.goto(baseURL.replace(/\/$/, '') + '/apps/customercheck/', {
			waitUntil: 'domcontentloaded',
			timeout: 45_000,
		});
		ok = !page.url().includes('/login');
		if (ok) {
			servedLang = await page.evaluate(() => document.documentElement.lang);
		}
		await context.close();
	} catch {
		return false;
	} finally {
		await browser.close().catch(() => {});
	}
	if (ok) {
		assertServedLangEn(servedLang);
	}
	return ok;
}

async function loginOnce(page, { username, password, baseURL }) {
	await page.goto(baseURL + '/login', { waitUntil: 'domcontentloaded' });
	const updateHeading = page.getByRole('heading', {
		name: /update needed|aktualisierung erforderlich/i,
	});
	if (await updateHeading.isVisible({ timeout: 800 }).catch(() => false)) {
		ensureUpgraded();
		await page.goto(baseURL + '/login', { waitUntil: 'domcontentloaded' });
	}
	await page.evaluate(() => {
		const form = document.querySelector('form');
		if (form) {
			form.setAttribute('action', '/login');
		}
	});
	const userInput = page
		.locator('#user')
		.or(page.locator('input[name="user"]'))
		.or(page.getByRole('textbox', { name: /account name|email|benutzername|e-mail|login|nazwa/i }))
		.first();
	await userInput.waitFor({ state: 'visible', timeout: 45000 });
	await userInput.fill(username);
	const passInput = page
		.locator('#password')
		.or(page.locator('input[name="password"]'))
		.or(page.getByRole('textbox', { name: /^password$|^passwort$|^hasło$/i }))
		.first();
	await passInput.fill(password);
	await page.evaluate(() => {
		const form = document.querySelector('form');
		if (form) {
			form.setAttribute('action', '/login');
		}
	});
	const submit = page
		.locator('button[data-login-form-submit]')
		.or(page.locator('form[name="login"] button[type="submit"]'))
		.or(page.getByRole('button', { name: /log in|anmelden|zaloguj|se connecter|accedi|inloggen/i }))
		.first();
	await submit.click();
	await page.waitForURL((url) => !url.pathname.includes('/login'), {
		timeout: 60000,
		waitUntil: 'domcontentloaded',
	});
}

export default async function globalSetup(config) {
	await acquireSharedInstanceLock('customercheck')
	ensureUpgraded();

	const baseURL = (
		config?.projects?.[0]?.use?.baseURL
		|| config?.use?.baseURL
		|| process.env.NC_BASE_URL
		|| 'http://127.0.0.1:8081'
	).replace(/\/$/, '');
	const username = process.env.NC_ADMIN_USER;
	const password = process.env.NC_ADMIN_PASS;
	if (!username || !password) {
		return;
	}

	const langPin = occUserSet(username, 'lang', 'en');
	if (langPin.status !== 0) {
		console.warn('[globalSetup] core/lang=en pin failed:', (langPin.stderr || langPin.stdout || '').trim());
	}

	mkdirSync(dirname(STORAGE_STATE_PATH), { recursive: true });
	if (await storageStillValid(baseURL)) {
		console.log('[globalSetup] reusing valid storage state');
		return;
	}

	const host = hostPortFromBase(baseURL);
	const oldUrl = occGet('overwrite.cli.url');
	const oldHost = occGet('overwritehost');
	let restored = false;
	const restore = () => {
		if (restored) {
			return;
		}
		if (oldUrl) {
			occSet('overwrite.cli.url', oldUrl);
		}
		if (oldHost) {
			occSet('overwritehost', oldHost);
		}
		restored = true;
	};

	try {
		occSet('overwrite.cli.url', baseURL);
		occSet('overwritehost', host);

		const browser = await chromium.launch({
			args: ['--disable-web-security', '--disable-features=IsolateOrigins,site-per-process'],
		});
		const context = await browser.newContext({ baseURL });
		const page = await context.newPage();
		try {
			await loginOnce(page, { username, password, baseURL });
			assertServedLangEn(await page.evaluate(() => document.documentElement.lang));
			await context.storageState({ path: STORAGE_STATE_PATH });
			console.log('[globalSetup] storage state written');
		} catch (err) {
			if (existsSync(STORAGE_STATE_PATH)) {
				console.warn('[globalSetup] loginOnce failed; reusing existing storage state:', err?.message || err);
			} else {
				throw err;
			}
		} finally {
			await browser.close();
		}
	} finally {
		restore();
	}
}
