// @ts-check
/**
 * Atlas dialog-legs — executed open → cancel/dismiss → confirm proofs for
 * web dialogs that previously carried api-only cites (POLICY 3.5.15 must_fix
 * cuc-critic-dialog-proofs-api-only). Locale-safe structural selectors only.
 */
import { test, expect } from '@playwright/test';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';
import { api, deleteCompany } from './helpers/cleanup.js';

const API = '/index.php/apps/customercheck/api/v1';

async function seedCompany(page, suffix) {
  const r = await api(page, 'POST', `${API}/companies`, { name: `DlgCo ${suffix}` });
  expect(r.status, JSON.stringify(r.body)).toBe(201);
  return r.body.data.id;
}

async function seedDeal(page, companyId, suffix) {
  const r = await api(page, 'POST', `${API}/deals`, { companyId, title: `DlgDeal ${suffix}` });
  expect(r.status, JSON.stringify(r.body)).toBe(201);
  return r.body.data.id;
}

async function seedAgreement(page, companyId, suffix, extra = {}) {
  const r = await api(page, 'POST', `${API}/agreements`, {
    companyId,
    kind: 'license',
    label: `DlgAgr ${suffix}`,
    productCode: 'cuc',
    amountCents: 5000,
    startsAt: '2026-10-01',
    validUntil: '2027-10-01',
    ...extra,
  });
  expect(r.status, JSON.stringify(r.body)).toBe(201);
  return r.body.data.id;
}

/** <details> stays open across dialog open/close — only click when closed. */
async function openMoreMenu(page) {
  const d = page.locator('details.crm-more-menu').first();
  if (!(await d.evaluate((el) => el.open))) {
    await d.locator('summary').click();
  }
}

/** Pipeline renders table + board stage selects in DOM; only one is visible per view. */
async function stageSelect(page, dealId) {
  const table = page.locator(`#deal-stage-${dealId}`);
  const board = page.locator(`#board-stage-${dealId}`);
  if (await table.isVisible().catch(() => false)) return table;
  await expect(board).toBeVisible({ timeout: 15000 });
  return board;
}

test.describe('Atlas dialog legs (open → cancel → confirm)', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('deal-lost dialog: cancel keeps stage, confirm persists lost + code', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}a`;
    let companyId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      companyId = await seedCompany(page, suffix);
      const dealId = await seedDeal(page, companyId, suffix);

      await gotoCrm(page, '/apps/customercheck/pipeline', { ready: '#crm-pipeline-toolbar' });
      const sel0 = await stageSelect(page, dealId);
      await expect(sel0).toHaveValue('lead');

      // OPEN → CANCEL: dialog opens, cancel resolves null, stage untouched.
      await sel0.selectOption('lost');
      const dlg = page.locator('dialog.crm-native-dialog[open]');
      await expect(dlg.locator('select[name="lostReasonCode"]')).toBeVisible({ timeout: 10000 });
      await dlg.locator('[data-cancel]').click();
      await page.waitForLoadState('load');
      const sel1 = await stageSelect(page, dealId);
      await expect(sel1).toHaveValue('lead', { timeout: 15000 });

      // REOPEN → CONFIRM: required code + submit → POST stage → reload → lost.
      await sel1.selectOption('lost');
      const dlg2 = page.locator('dialog.crm-native-dialog[open]');
      await expect(dlg2.locator('select[name="lostReasonCode"]')).toBeVisible({ timeout: 10000 });
      const stagePost = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes(`/deals/${dealId}/stage`),
        { timeout: 15000 },
      );
      await dlg2.locator('select[name="lostReasonCode"]').selectOption('price');
      await dlg2.locator('button[type="submit"]').click();
      expect((await stagePost).ok()).toBeTruthy();
      // moveDeal reloads — wait for the fresh document before evaluating.
      await expect(page.locator('#crm-pipeline-toolbar')).toBeVisible({ timeout: 15000 });

      // Durable postcondition: server state is lost with the chosen code.
      const deal = await api(page, 'GET', `${API}/deals?limit=100`);
      const row = (deal.body.data || []).find((d) => d.id === dealId);
      expect(row && row.stage).toBe('lost');
      expect(row && row.lostReasonCode).toBe('price');
    } finally {
      if (companyId) await deleteCompany(page, companyId);
    }
  });

  test('agreement-cancel dialog: keep stays active, reason submit cancels', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}b`;
    let companyId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      companyId = await seedCompany(page, suffix);
      const agreementId = await seedAgreement(page, companyId, suffix);

      await gotoCrm(page, `/apps/customercheck/agreements/${agreementId}/edit`, { ready: '#crm-agreement-form' });
      // Cancel lives under the collapsed "End this agreement" disclosure.
      await page.locator('#crm-agreement-end > summary').click();
      await expect(page.locator('#crm-agreement-cancel')).toBeVisible({ timeout: 10000 });

      // OPEN → CANCEL ("Keep agreement"): dialog closes, agreement untouched.
      await page.locator('#crm-agreement-cancel').click();
      const dlg = page.locator('dialog.crm-native-dialog[open]');
      await expect(dlg.locator('textarea[name="reason"]')).toBeVisible({ timeout: 10000 });
      await dlg.locator('[data-cancel]').click();
      await expect(page.locator('dialog.crm-native-dialog[open]')).toHaveCount(0);
      await expect(page.locator('#crm-agreement-cancel')).toBeVisible();

      // REOPEN → CONFIRM: required reason → POST cancel → leaves edit page.
      await page.locator('#crm-agreement-cancel').click();
      const dlg2 = page.locator('dialog.crm-native-dialog[open]');
      await expect(dlg2.locator('textarea[name="reason"]')).toBeVisible({ timeout: 10000 });
      const cancelPost = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes(`/agreements/${agreementId}/cancel`),
        { timeout: 15000 },
      );
      await dlg2.locator('textarea[name="reason"]').fill(`Audit cancel ${suffix}`);
      await dlg2.locator('button[type="submit"]').click();
      expect((await cancelPost).ok()).toBeTruthy();
      await expect(page).not.toHaveURL(/\/agreements\/\d+\/edit/, { timeout: 15000 });

      // Durable postcondition: server status is cancelled.
      const list = await api(page, 'GET', `${API}/agreements?limit=100`);
      const row = (list.body.data || []).find((a) => a.id === agreementId);
      expect(row && String(row.status)).toContain('cancel');
    } finally {
      if (companyId) await deleteCompany(page, companyId);
    }
  });

  test('delivery-pack dialog: opens with generated pack, close dismisses', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}c`;
    let companyId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      companyId = await seedCompany(page, suffix);
      // Delivery-pack chrome only renders for issued licenses (keyFingerprint set);
      // recipient resolution needs a person with an email on the company.
      await seedAgreement(page, companyId, suffix, { keyFingerprint: `fp-${suffix}` });
      const personSeed = await api(page, 'POST', `${API}/companies/${companyId}/persons`, {
        name: `Atlas Pack Recipient ${suffix}`,
        email: `atlas-pack-${suffix}@example.invalid`,
        isPrimary: true,
      });
      expect(personSeed.status, `person seed: ${JSON.stringify(personSeed.body)}`).toBe(201);

      await gotoCrm(page, `/apps/customercheck/companies/${companyId}`, { ready: '#crm-main-content' });
      // Licenses & renewals card sits on the Deals focus panel; the button is
      // inside the row's collapsed "More" disclosure.
      await page.locator('[data-crm-focus-tab="deals"]').click();
      await expect(page.locator('#crm-360-panel-deals')).toBeVisible({ timeout: 10000 });
      const row = page.locator('#crm-360-panel-deals tr', { has: page.locator('[data-crm-delivery-pack]') }).first();
      await row.locator('details.crm-more-menu > summary').click();
      const packBtn = row.locator('[data-crm-delivery-pack]');
      await packBtn.scrollIntoViewIfNeeded();
      await expect(packBtn).toBeVisible({ timeout: 15000 });
      const packPost = page.waitForResponse(
        (r) => r.request().method() === 'POST' && /\/agreements\/\d+\/delivery-pack/.test(r.url()),
        { timeout: 15000 },
      );
      await packBtn.click();
      const packResp = await packPost;
      expect(packResp.ok(), `delivery-pack POST ${packResp.status()}: ${(await packResp.text()).slice(0, 300)}`).toBeTruthy();

      const dlg = page.locator('dialog.crm-native-dialog[open]');
      await expect(dlg.first()).toBeVisible({ timeout: 10000 });
      await expect(dlg.locator('.modal-close').first()).toBeVisible();
      await dlg.locator('.modal-close').first().click();
      await expect(page.locator('dialog.crm-native-dialog[open]')).toHaveCount(0);
    } finally {
      if (companyId) await deleteCompany(page, companyId);
    }
  });

  test('person dialog: open → cancel dismisses without a write', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}d`;
    let companyId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      companyId = await seedCompany(page, suffix);

      await gotoCrm(page, `/apps/customercheck/companies/${companyId}`, { ready: '#crm-main-content' });
      // People roster lives on the People focus panel.
      await page.locator('[data-crm-focus-tab="people"]').click();
      await expect(page.locator('#crm-360-panel-people')).toBeVisible({ timeout: 10000 });
      await page.locator('#crm-add-person').click();
      const dlg = page.locator('#crm-person-dialog[open]');
      await expect(dlg).toBeVisible({ timeout: 10000 });
      await expect(dlg).toHaveAttribute('aria-modal', 'true');
      await dlg.locator('#crm-person-cancel').click();
      await expect(page.locator('#crm-person-dialog[open]')).toHaveCount(0);

      // Durable postcondition: no person row was written.
      const persons = await api(page, 'GET', `${API}/companies/${companyId}/persons`);
      expect((persons.body.data || []).length).toBe(0);

      // REOPEN → CONFIRM: submit persists a person row.
      await page.locator('#crm-add-person').click();
      const dlg2 = page.locator('#crm-person-dialog[open]');
      await expect(dlg2).toBeVisible({ timeout: 10000 });
      await dlg2.locator('#crm-person-name').fill(`Atlas Person ${suffix}`);
      const personPost = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes(`/companies/${companyId}/persons`),
        { timeout: 15000 },
      );
      await dlg2.locator('button[type="submit"]').click();
      expect((await personPost).ok()).toBeTruthy();
      await expect(page.locator('#crm-person-dialog[open]')).toHaveCount(0);
      const after = await api(page, 'GET', `${API}/companies/${companyId}/persons`);
      expect((after.body.data || []).some((p) => p.name === `Atlas Person ${suffix}`)).toBeTruthy();
    } finally {
      if (companyId) await deleteCompany(page, companyId);
    }
  });

  test('confirm-dialog: archive cancel keeps company, confirm archives, restore undoes', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}e`;
    let companyId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      companyId = await seedCompany(page, suffix);

      await gotoCrm(page, `/apps/customercheck/companies/${companyId}`, { ready: '#crm-main-content' });
      const more = page.locator('details.crm-more-menu').first();
      await more.locator('summary').click();

      // OPEN → CANCEL: archive confirm dismisses, company stays active.
      await page.locator('#crm-company-archive').click();
      const dlg = page.locator('dialog.confirm-dialog[open]');
      await expect(dlg.locator('.confirm-dialog__confirm')).toBeVisible({ timeout: 10000 });
      await dlg.locator('.confirm-dialog__cancel').click();
      await expect(page.locator('dialog.confirm-dialog[open]')).toHaveCount(0);
      await expect(page.locator('#crm-company-archive')).toBeVisible();

      // REOPEN → CONFIRM: POST archive → reload → Restore chrome.
      await page.locator('#crm-company-archive').click();
      const dlg2 = page.locator('dialog.confirm-dialog[open]');
      await expect(dlg2).toBeVisible({ timeout: 10000 });
      const archivePost = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes(`/companies/${companyId}/archive`),
        { timeout: 15000 },
      );
      await dlg2.locator('.confirm-dialog__confirm').click();
      expect((await archivePost).ok()).toBeTruthy();
      await page.locator('details.crm-more-menu > summary').first().click();
      await expect(page.locator('#crm-company-restore')).toBeVisible({ timeout: 15000 });

      // RESTORE confirm → company back to active.
      const restorePost = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes(`/companies/${companyId}/restore`),
        { timeout: 15000 },
      );
      await page.locator('#crm-company-restore').click();
      const dlg3 = page.locator('dialog.confirm-dialog[open]');
      await expect(dlg3).toBeVisible({ timeout: 10000 });
      await dlg3.locator('.confirm-dialog__confirm').click();
      expect((await restorePost).ok()).toBeTruthy();
      await page.locator('details.crm-more-menu > summary').first().click();
      await expect(page.locator('#crm-company-archive')).toBeVisible({ timeout: 15000 });
    } finally {
      if (companyId) await deleteCompany(page, companyId);
    }
  });

  test('merge dialogs: prompt cancel, confirm cancel, confirm merges + archives source', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}f`;
    let srcId = 0;
    let keeperId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      srcId = await seedCompany(page, `${suffix}-src`);
      keeperId = await seedCompany(page, `${suffix}-keeper`);

      await gotoCrm(page, `/apps/customercheck/companies/${srcId}`, { ready: '#crm-main-content' });
      await openMoreMenu(page);
      await expect(page.locator('#crm-company-merge')).toBeVisible({ timeout: 10000 });

      // OPEN promptFields → CANCEL: dialog closes, no merge.
      await page.locator('#crm-company-merge').click();
      const prompt = page.locator('dialog.crm-native-dialog[open]').last();
      await expect(prompt.locator('li[role="option"]').first()).toBeVisible({ timeout: 15000 });
      await prompt.locator('[data-cancel]').click();
      await expect(page.locator('dialog.crm-native-dialog[open]')).toHaveCount(0);

      // REOPEN → pick keeper → Save → CONFIRM dialog → CANCEL (dismiss leg).
      await openMoreMenu(page);
      await page.locator('#crm-company-merge').click();
      const prompt2 = page.locator('dialog.crm-native-dialog[open]').last();
      await prompt2.locator(`li[role="option"][data-company-id="${keeperId}"] .crm-company-list__pick`).click();
      await prompt2.locator('button[type="submit"]').click();
      const confirm = page.locator('dialog.confirm-dialog[open]');
      await expect(confirm.locator('.confirm-dialog__confirm')).toBeVisible({ timeout: 10000 });
      await confirm.locator('.confirm-dialog__cancel').click();
      await expect(page.locator('dialog.confirm-dialog[open]')).toHaveCount(0);
      await expect(page).toHaveURL(new RegExp(`/companies/${srcId}`));

      // REOPEN → pick keeper → Save → CONFIRM → merge → redirect to keeper.
      await openMoreMenu(page);
      await page.locator('#crm-company-merge').click();
      const prompt3 = page.locator('dialog.crm-native-dialog[open]').last();
      await prompt3.locator(`li[role="option"][data-company-id="${keeperId}"] .crm-company-list__pick`).click();
      await prompt3.locator('button[type="submit"]').click();
      const mergePost = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes(`/companies/${srcId}/merge`),
        { timeout: 15000 },
      );
      const confirm2 = page.locator('dialog.confirm-dialog[open]');
      await expect(confirm2).toBeVisible({ timeout: 10000 });
      await confirm2.locator('.confirm-dialog__confirm').click();
      expect((await mergePost).ok()).toBeTruthy();
      await expect(page).toHaveURL(new RegExp(`/companies/${keeperId}`), { timeout: 15000 });

      // Durable postcondition: source archived, keeper active.
      const src = await api(page, 'GET', `${API}/companies/${srcId}`);
      expect(src.body.data && src.body.data.archivedAt, JSON.stringify(src.body)).toBeTruthy();
    } finally {
      if (srcId) await deleteCompany(page, srcId);
      if (keeperId) await deleteCompany(page, keeperId);
    }
  });

  test('folder picker: intro confirm opens native picker, cancel links nothing', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}g`;
    let companyId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      companyId = await seedCompany(page, suffix);

      await gotoCrm(page, `/apps/customercheck/companies/${companyId}`, { ready: '#crm-main-content' });
      // Documents card sits on the Money focus panel.
      await page.locator('[data-crm-focus-tab="money"]').click();
      await expect(page.locator('#crm-360-panel-money')).toBeVisible({ timeout: 10000 });
      await page.locator('#crm-link-folder').click();

      // First-run intro is app chrome; confirm it to reach the native picker.
      const intro = page.locator('dialog.confirm-dialog[open]');
      if (await intro.isVisible().catch(() => false)) {
        await intro.locator('.confirm-dialog__confirm').click();
      }

      // Native picker (modern NcModal or legacy oc-dialog) → close/dismiss.
      const picker = page.locator('.modal-container, .oc-dialog').first();
      await expect(picker).toBeVisible({ timeout: 15000 });
      const closeBtn = picker.locator('.modal-container__close, .oc-dialog-close').first();
      if (await closeBtn.isVisible().catch(() => false)) {
        await closeBtn.click();
      } else {
        await picker.locator('.oc-dialog-buttonrow button').last().click();
      }
      await expect(picker).toBeHidden({ timeout: 15000 });

      // Durable postcondition: no folder was linked.
      const detail = await api(page, 'GET', `${API}/companies/${companyId}`);
      const row = (detail.body.data || {});
      expect(row.filesFolderId ?? null).toBeNull();
      expect(row.filesFolderPath ?? null).toBeNull();
    } finally {
      if (companyId) await deleteCompany(page, companyId);
    }
  });

  test('conflict resolve: seeded CAS conflict resolves via conflicts card', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}h`;
    let companyId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      companyId = await seedCompany(page, suffix);
      const personSeed = await api(page, 'POST', `${API}/companies/${companyId}/persons`, {
        name: `Atlas Conflict ${suffix}`,
      });
      expect(personSeed.status).toBe(201);
      const personId = personSeed.body.data.id;
      const rev0 = personSeed.body.data.updatedAt;
      expect(rev0).toBeTruthy();

      // Second-granularity CAS tokens need distinct-second revisions; and a
      // conflict only records when a base snapshot exists → write twice, then
      // collide a third stale write against the first write's revision.
      await page.waitForTimeout(1100);
      const w1 = await api(page, 'PUT', `${API}/persons/${personId}`, {
        name: `Atlas Conflict A ${suffix}`,
        updatedAt: rev0,
      });
      expect(w1.status).toBe(200);
      const rev1 = w1.body.data.updatedAt;
      await page.waitForTimeout(1100);
      const w2 = await api(page, 'PUT', `${API}/persons/${personId}`, {
        name: `Atlas Conflict A2 ${suffix}`,
        updatedAt: rev1,
      });
      expect(w2.status).toBe(200);
      const w3 = await api(page, 'PUT', `${API}/persons/${personId}`, {
        name: `Atlas Conflict B ${suffix}`,
        updatedAt: rev1,
      });
      expect(w3.status, JSON.stringify(w3.body)).toBe(409);
      const conflictId = (w3.body.data && w3.body.data.conflictId)
        || w3.body.conflictId
        || (w3.body.fields && w3.body.fields.conflictId);
      expect(conflictId, JSON.stringify(w3.body)).toBeTruthy();

      // OPEN: conflicts page lists the card with per-field choices.
      await gotoCrm(page, '/apps/customercheck/conflicts', { ready: '[data-crm-persona-mount="conflicts"]' });
      const card = page.locator(`[data-crm-conflict-id="${conflictId}"]`);
      await expect(card).toBeVisible({ timeout: 20000 });
      await expect(card.locator('input[type="radio"]')).not.toHaveCount(0);

      // DISMISS-equivalent: navigate away without resolving → still open.
      await gotoCrm(page, '/apps/customercheck/companies', { ready: '#crm-main-content' });
      const wsCo = await api(page, 'GET', `${API}/companies/${companyId}`);
      const wsId = wsCo.body.data && wsCo.body.data.workspaceId;
      expect(wsId).toBeTruthy();
      const still = await api(page, 'GET', `${API}/workspaces/${wsId}/conflicts`);
      const stillList = Array.isArray(still.body.data) ? still.body.data : ((still.body.data && still.body.data.items) || []);
      expect(stillList.some((c) => c.id === conflictId)).toBeTruthy();

      // CONFIRM: take_incoming + reason → Resolve → card gone, name = B.
      await gotoCrm(page, '/apps/customercheck/conflicts', { ready: '[data-crm-persona-mount="conflicts"]' });
      const card2 = page.locator(`[data-crm-conflict-id="${conflictId}"]`);
      await expect(card2).toBeVisible({ timeout: 20000 });
      await card2.locator('input[type="radio"][value="take_incoming"]').first().check();
      await card2.locator('textarea').fill(`Audit resolve ${suffix}`);
      const resolvePost = page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes(`/conflicts/${conflictId}/resolve`),
        { timeout: 15000 },
      );
      await card2.locator('button.crm-btn--primary').click();
      expect((await resolvePost).ok()).toBeTruthy();
      await expect(page.locator(`[data-crm-conflict-id="${conflictId}"]`)).toHaveCount(0, { timeout: 15000 });

      const plist = await api(page, 'GET', `${API}/companies/${companyId}/persons`);
      const prow = (plist.body.data || []).find((p) => p.id === personId) || {};
      expect(prow.name).toBe(`Atlas Conflict B ${suffix}`);
    } finally {
      if (companyId) await deleteCompany(page, companyId);
    }
  });

  test('prepare-meeting route: 301 lands on company 360 (no modal by design)', async ({ page }) => {
    const suffix = `${Date.now().toString(36)}i`;
    let companyId = 0;
    try {
      await login(page, credsFromEnv('ADMIN'));
      companyId = await seedCompany(page, suffix);
      await page.goto(`/apps/customercheck/companies/${companyId}/prepare-meeting`);
      await expect(page).toHaveURL(new RegExp(`/apps/customercheck/companies/${companyId}(?!/prepare)`), { timeout: 15000 });
      await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 15000 });
    } finally {
      if (companyId) await deleteCompany(page, companyId);
    }
  });
});
