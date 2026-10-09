// @ts-check
import { defineConfig } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/** Load tests/e2e/.env into process.env when present (does not override existing). */
function loadE2eDotEnv() {
  const envPath = join(dirname(fileURLToPath(import.meta.url)), 'tests', 'e2e', '.env');
  if (!existsSync(envPath)) {
    return;
  }
  const raw = readFileSync(envPath, 'utf8');
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) {
      continue;
    }
    const eq = trimmed.indexOf('=');
    if (eq <= 0) {
      continue;
    }
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"'))
      || (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    if (process.env[key] === undefined) {
      process.env[key] = value;
    }
  }
}

loadE2eDotEnv();

const authState = process.env.E2E_STORAGE_STATE
  || join(dirname(fileURLToPath(import.meta.url)), 'tests', 'e2e', '.auth', 'admin.json');

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.js',
  timeout: 60000,
  retries: 1,
  workers: 1,
  fullyParallel: false,
  use: {
    // Prefer 127.0.0.1 over localhost — Chromium CSP form-action 'self' rejects
	// absolute http://localhost when overwritehost is the emulator alias 10.0.2.2.
	baseURL: process.env.NC_BASE_URL || 'http://127.0.0.1:8081',
    trace: 'on-first-retry',
    // Path is created by globalSetup before any test context starts.
    ...(process.env.NC_ADMIN_USER ? { storageState: authState } : {}),
  },
});
