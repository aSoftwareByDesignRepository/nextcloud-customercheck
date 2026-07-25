// @ts-check
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60000,
  retries: 1,
  workers: 1,
  fullyParallel: false,
  use: {
    baseURL: process.env.NC_BASE_URL || 'http://localhost:8081',
    trace: 'on-first-retry',
  },
});
