// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';
import { deleteCompanyAt } from './helpers/cleanup.js';

/**
 * Lens-1 proof: optimistic locking is enforced on the wire, not just in the
 * service layer. Two PUTs carrying the SAME base revision must not both
 * commit — exactly one wins, the loser gets the uniform 409 conflict, and
 * the persisted name is one of the two contenders (never a merge of both).
 */
test.describe('Concurrency: optimistic locking on the wire', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  const createdCompanies = [];
  test.afterEach(async ({ page }) => {
    for (const url of createdCompanies.splice(0)) await deleteCompanyAt(page, url);
  });

  test('two PUTs with one base revision: exactly one commits', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    await page.locator('#crm-company-name').fill(`CAS Co ${Date.now().toString(36)}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());
    const id = page.url().match(/companies\/(\d+)/)[1];

    const stamp = await page.locator('.crm-page-stack').getAttribute('data-updated-at');
    expect(stamp).toBeTruthy();

    const statuses = await page.evaluate(async ({ companyId, base }) => {
      const rt = document.head?.dataset?.requesttoken
        // @ts-ignore Nextcloud global
        || (window.OC && window.OC.requestToken)
        || '';
      const put = (name) => fetch(`/apps/customercheck/api/v1/companies/${companyId}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          requesttoken: rt,
          'OCS-APIRequest': 'true',
        },
        body: JSON.stringify({ name, updatedAt: base }),
      }).then((r) => r.status);
      return Promise.all([put('CAS Winner A'), put('CAS Winner B')]);
    }, { companyId: id, base: stamp });

    expect(statuses.slice().sort()).toEqual([200, 409]);

    // Ground truth is the stored entity, not either HTTP response.
    const company = await page.evaluate(async ({ companyId }) => {
      const rt = document.head?.dataset?.requesttoken
        // @ts-ignore Nextcloud global
        || (window.OC && window.OC.requestToken)
        || '';
      const r = await fetch(`/apps/customercheck/api/v1/companies/${companyId}`, {
        headers: { requesttoken: rt, 'OCS-APIRequest': 'true' },
      });
      return r.json();
    }, { companyId: id });
    expect(['CAS Winner A', 'CAS Winner B']).toContain(company?.data?.name);
  });

  test('a stale base revision fails closed (409, no silent merge)', async ({ page }) => {
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies/new');
    await page.waitForSelector('#crm-company-form', { timeout: 30000 });
    await page.locator('#crm-company-name').fill(`CAS Stale ${Date.now().toString(36)}`);
    await page.locator('#crm-company-form button[type="submit"]').click();
    await page.waitForURL(/\/apps\/customercheck\/companies\/\d+/, { timeout: 30000 });
    createdCompanies.push(page.url());
    const id = page.url().match(/companies\/(\d+)/)[1];
    const stamp = await page.locator('.crm-page-stack').getAttribute('data-updated-at');

    // First update moves the base; a retry with the old base must not apply.
    const [first, stale] = await page.evaluate(async ({ companyId, base }) => {
      const rt = document.head?.dataset?.requesttoken
        // @ts-ignore Nextcloud global
        || (window.OC && window.OC.requestToken)
        || '';
      const put = (body) => fetch(`/apps/customercheck/api/v1/companies/${companyId}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          requesttoken: rt,
          'OCS-APIRequest': 'true',
        },
        body: JSON.stringify(body),
      }).then((r) => r.status);
      const a = await put({ name: 'Moved Forward', updatedAt: base });
      const b = await put({ name: 'Stale Loser', updatedAt: base });
      return [a, b];
    }, { companyId: id, base: stamp });

    expect(first).toBe(200);
    expect(stale).toBe(409);

    const company = await page.evaluate(async ({ companyId }) => {
      const rt = document.head?.dataset?.requesttoken
        // @ts-ignore Nextcloud global
        || (window.OC && window.OC.requestToken)
        || '';
      const r = await fetch(`/apps/customercheck/api/v1/companies/${companyId}`, {
        headers: { requesttoken: rt, 'OCS-APIRequest': 'true' },
      });
      return r.json();
    }, { companyId: id });
    expect(company?.data?.name).toBe('Moved Forward');
  });
});
