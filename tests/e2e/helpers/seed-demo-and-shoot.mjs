// Seeds presentable demo data via the app's own JSON API (from the browser
// context, so CSRF is handled), captures the four product-page screenshots,
// and removes the demo data again. Dev-instance janitor tool — not a test.
//
// Modes (so the caller can quiesce other test data via SQL between steps):
//   node seed-demo-and-shoot.mjs seed            -> prints DEMO_IDS=<csv>
//   node seed-demo-and-shoot.mjs shoot <outDir> <companyId>
//   node seed-demo-and-shoot.mjs cleanup <id:slug> [<id:slug> ...]
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const mode = process.argv[2];
const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081';
const user = process.env.NC_ADMIN_USER;
const pass = process.env.NC_ADMIN_PASS;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 900 } });

await page.goto(`${baseURL}/login`);
await page.locator('#user, input[name="user"]').first().fill(user);
await page.locator('#password, input[name="password"]').first().fill(pass);
await page.locator('button[type="submit"]').first().click();
await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 });
await page.goto(`${baseURL}/apps/customercheck/`);
await page.waitForSelector('#crm-main-content', { timeout: 30000 });

async function api(method, path, body) {
  return page.evaluate(async ({ method, path, body }) => {
    const resp = await fetch(OC.generateUrl('/apps/customercheck/api/v1') + path, {
      method,
      headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
      body: body ? JSON.stringify(body) : undefined,
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(`${method} ${path} -> ${resp.status}: ${JSON.stringify(json)}`);
    return json && typeof json === 'object' && 'data' in json ? json.data : json;
  }, { method, path, body });
}

const iso = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);

try {
  if (mode === 'seed') {
    const run = Date.now().toString(36);
    const created = [];
    const company = async (payload) => {
      const c = await api('POST', '/companies', payload);
      created.push(c);
      return c;
    };
    const nordwind = await company({
      name: 'Nordwind Media GmbH', slug: `nordwind-media-${run}`, status: 'active',
      addrCity: 'Hamburg', addrZip: '20095', country: 'DE', website: 'https://example.com',
    });
    const hafenblick = await company({ name: 'Hafenblick Consulting', slug: `hafenblick-consulting-${run}`, status: 'active', addrCity: 'Kiel', country: 'DE' });
    const brandt = await company({ name: 'Brandt & Söhne Maschinenbau', slug: `brandt-soehne-${run}`, status: 'lead', addrCity: 'Flensburg', country: 'DE' });

    await api('POST', `/companies/${nordwind.id}/persons`, { name: 'Anna Petersen', role: 'Managing director', email: 'a.petersen@example.com', isPrimary: true });
    await api('POST', `/companies/${nordwind.id}/persons`, { name: 'Jonas Krüger', role: 'IT operations', email: 'j.krueger@example.com' });
    await api('POST', `/companies/${hafenblick.id}/persons`, { name: 'Miriam Lohse', role: 'Office management', email: 'm.lohse@example.com', isPrimary: true });

    await api('POST', '/agreements', {
      companyId: nordwind.id, kind: 'license', productCode: 'azc',
      label: 'ArbeitszeitCheck — 25 mobile seats', startsAt: iso(-330), validUntil: iso(35),
      amountCents: 45000, status: 'active', limits: { mobileSeats: 25, terminalDevices: 1 },
    });
    await api('POST', '/agreements', {
      companyId: hafenblick.id, kind: 'license', productCode: 'pc',
      label: 'ProjectCheck — 12 mobile seats', startsAt: iso(-100), validUntil: iso(58),
      amountCents: 15000, status: 'active', limits: { mobileSeats: 12, terminalDevices: 0 },
    });

    await api('POST', '/deals', { companyId: nordwind.id, title: 'Mobile seats upgrade (10 users)', stage: 'negotiation', valueCents: 90000, expectedClose: iso(21) });
    await api('POST', '/deals', { companyId: hafenblick.id, title: 'CRM rollout workshop', stage: 'quoted', valueCents: 240000, expectedClose: iso(30) });
    await api('POST', '/deals', { companyId: brandt.id, title: 'Maintenance contract 2027', stage: 'lead', valueCents: 480000, expectedClose: iso(90) });

    await api('POST', '/activities', { companyId: nordwind.id, type: 'call', title: 'Follow up on renewal quote', dueDate: iso(0) });
    await api('POST', '/activities', { companyId: hafenblick.id, type: 'email', title: 'Send workshop agenda', dueDate: iso(1) });
    await api('POST', '/activities', { companyId: brandt.id, type: 'meeting', title: 'Intro call — maintenance needs', dueDate: iso(3) });

    console.log('DEMO_IDS=' + created.map((c) => `${c.id}:${c.slug}`).join(','));
    console.log('DETAIL_ID=' + nordwind.id);
  } else if (mode === 'shoot') {
    const outDir = process.argv[3];
    const detailId = process.argv[4];
    mkdirSync(outDir, { recursive: true });
    const shoot = async (path, file) => {
      await page.goto(`${baseURL}${path}`);
      await page.waitForSelector('#crm-main-content', { timeout: 30000 });
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(600);
      await page.screenshot({ path: `${outDir}/${file}` });
      console.log('captured', file);
    };
    await shoot('/apps/customercheck/', '01.png');
    await shoot(`/apps/customercheck/companies/${detailId}`, '02.png');
    await shoot('/apps/customercheck/pipeline', '03.png');
    await shoot('/apps/customercheck/agreements', '04.png');
  } else if (mode === 'cleanup') {
    for (const pair of process.argv.slice(3).join(',').split(',').filter(Boolean)) {
      const [id, slug] = pair.split(':');
      await api('DELETE', `/companies/${id}`, { confirmSlug: slug })
        .then(() => console.log('erased', id))
        .catch((e) => console.error('cleanup failed', id, String(e)));
    }
  } else {
    console.error('unknown mode', mode);
    process.exitCode = 1;
  }
} finally {
  await browser.close();
}
