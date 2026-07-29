// One-off capture of website screenshots for the CustomerCheck product page.
// Usage (from apps/customercheck, with tests/e2e/.env sourced):
//   node tests/e2e/helpers/capture-website-screenshots.mjs <output-dir>
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const outDir = process.argv[2];
if (!outDir) {
  console.error('usage: node capture-website-screenshots.mjs <output-dir>');
  process.exit(1);
}
mkdirSync(outDir, { recursive: true });

const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081';
const user = process.env.NC_ADMIN_USER;
const pass = process.env.NC_ADMIN_PASS;
if (!user || !pass) {
  console.error('NC_ADMIN_USER / NC_ADMIN_PASS required');
  process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 1 });

// Login
await page.goto(`${baseURL}/login`);
await page.locator('#user, input[name="user"]').first().fill(user);
await page.locator('#password, input[name="password"]').first().fill(pass);
await page.locator('button[type="submit"]').first().click();
await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 });

async function shoot(path, file) {
  await page.goto(`${baseURL}${path}`);
  await page.waitForSelector('#crm-main-content', { timeout: 30000 });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${outDir}/${file}` });
  console.log('captured', file, path);
}

await shoot('/apps/customercheck/', '01.png'); // Today
// Company 360: first company from list, if any
await page.goto(`${baseURL}/apps/customercheck/companies`);
await page.waitForSelector('#crm-main-content', { timeout: 30000 });
const firstCompany = page.locator('a[href*="/apps/customercheck/companies/"]:not([href$="/new"])').first();
if (await firstCompany.count()) {
  const href = await firstCompany.getAttribute('href');
  const path = href.replace(/^https?:\/\/[^/]+/, '');
  await shoot(path, '02.png');
} else {
  await shoot('/apps/customercheck/companies', '02.png');
}
await shoot('/apps/customercheck/pipeline', '03.png');
await shoot('/apps/customercheck/agreements', '04.png');

await browser.close();
