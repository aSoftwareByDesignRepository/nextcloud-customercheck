// @ts-check
/**
 * ds_chrome capture: every web surface × Nextcloud theme (light / dark /
 * light-highcontrast / dark-highcontrast) + honest states (empty, error,
 * denied, loading) + dialogs (danger vs primary confirm, content dialog,
 * focus trap / Escape / focus restore) + live computed-style assertions
 * (touch targets ≥44px, danger fill, cascade survival on body-mounted dialogs).
 *
 * Outputs: PNG craft to $CUC_CRAFT_DIR, JSON proofs to $CUC_PROOF_DIR.
 * Restores the default theme at the end.
 */
import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { login, credsFromEnv } from './helpers/auth.js';

const CRAFT = process.env.CUC_CRAFT_DIR || 'test-results/ds-craft';
const PROOF = process.env.CUC_PROOF_DIR || 'test-results/ds-proofs';
mkdirSync(CRAFT, { recursive: true });
mkdirSync(PROOF, { recursive: true });

const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast'];

const SURFACES = [
  ['today', '/apps/customercheck/'],
  ['pulse', '/apps/customercheck/pulse'],
  ['brief', '/apps/customercheck/brief'],
  ['my-accounts', '/apps/customercheck/my-accounts'],
  ['team', '/apps/customercheck/team'],
  ['strategy', '/apps/customercheck/strategy'],
  ['sales-inbox', '/apps/customercheck/sales-inbox'],
  ['conflicts', '/apps/customercheck/conflicts'],
  ['companies', '/apps/customercheck/companies'],
  ['companies-new', '/apps/customercheck/companies/new'],
  ['company-360', '/apps/customercheck/companies/240'],
  ['company-edit', '/apps/customercheck/companies/240/edit'],
  ['pipeline', '/apps/customercheck/pipeline'],
  ['agreements', '/apps/customercheck/agreements'],
  ['agreements-new', '/apps/customercheck/agreements/new'],
  ['money', '/apps/customercheck/money'],
  ['activities', '/apps/customercheck/activities'],
  ['import', '/apps/customercheck/import'],
  ['support-us', '/apps/customercheck/support-us'],
  ['get-the-app', '/apps/customercheck/get-the-app'],
  ['settings-mailbox', '/apps/customercheck/settings/mailbox'],
  ['settings-my-email', '/apps/customercheck/settings/my-email'],
  ['settings-preferences', '/apps/customercheck/settings/preferences'],
  ['settings-checklists', '/apps/customercheck/settings/checklists'],
  ['settings-review-sources', '/apps/customercheck/settings/review-sources'],
  ['settings-promises', '/apps/customercheck/settings/promises'],
  ['settings-integrations', '/apps/customercheck/settings/integrations'],
  ['appsettings-access', '/apps/customercheck/app-settings/access'],
  ['appsettings-jobs', '/apps/customercheck/app-settings/jobs'],
  ['appsettings-tokens', '/apps/customercheck/app-settings/tokens'],
  ['appsettings-license', '/apps/customercheck/app-settings/license'],
  ['appsettings-product-codes', '/apps/customercheck/app-settings/product-codes'],
  ['appsettings-personal-mail', '/apps/customercheck/app-settings/personal-mail'],
];

const proofs = {
  capturedAt: new Date().toISOString(),
  surfaces: [],
  computed: {},
  dialogs: {},
  states: {},
};

function flushProofs() {
  writeFileSync(join(PROOF, 'ds-chrome-probes.json'), JSON.stringify(proofs, null, 2));
}

async function enableTheme(page, themeId) {
  const status = await page.evaluate(async (id) => {
    const token = document.head?.dataset?.requesttoken || (window.OC && window.OC.requestToken);
    const res = await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${id}/enable`, {
      method: 'PUT',
      headers: { requesttoken: token || '', 'OCS-APIRequest': 'true' },
    });
    return res.status;
  }, themeId);
  if (status !== 200) throw new Error(`theme enable ${themeId} → HTTP ${status}`);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect.poll(async () => page.evaluate((id) => document.body.hasAttribute(`data-theme-${id}`), themeId), { timeout: 15000 }).toBe(true);
}

async function disableTheme(page, themeId) {
  await page.evaluate(async (id) => {
    const token = document.head?.dataset?.requesttoken || (window.OC && window.OC.requestToken);
    await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
      method: 'DELETE',
      headers: { requesttoken: token || '', 'OCS-APIRequest': 'true' },
    }).catch(() => {});
  }, themeId);
}

async function shot(page, name) {
  await page.screenshot({ path: join(CRAFT, `${name}.png`) });
  proofs.surfaces.push(`${name}.png`);
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(600000);

test('ds-chrome: surfaces × themes + states + dialogs + computed styles', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page, credsFromEnv('ADMIN'));
  await page.goto('/apps/customercheck/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });

  // ── Computed-style proofs (light) ────────────────────────────────────
  const computed = await page.evaluate(() => {
    const pick = (sel) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      return {
        sel,
        height: Math.round(r.height * 100) / 100,
        minHeight: cs.minHeight,
        bg: cs.backgroundColor,
        color: cs.color,
        cursor: cs.cursor,
        borderColor: cs.borderColor,
      };
    };
    return {
      primary: pick('#crm-page-actions .crm-btn--primary, #crm-main-content .crm-btn--primary, .crm-btn--primary'),
      secondary: pick('.crm-btn--secondary'),
      ghost: pick('.crm-btn--ghost'),
      quickFilter: pick('.crm-quick-filter'),
      navLink: pick('#app-navigation a'),
    };
  });
  proofs.computed.shell = computed;
  if (computed.primary) {
    expect(parseFloat(computed.primary.height)).toBeGreaterThanOrEqual(44);
    expect(computed.primary.cursor).toBe('pointer');
  }

  // ── Body-mounted dialogs keep DS chrome (cascade survival) ──────────
  // Marker focused BEFORE open — proves focus restoration on close.
  const markerReady = await page.evaluate(() => {
    const btn = document.createElement('button');
    btn.id = 'cuc-focus-marker';
    btn.textContent = 'focus marker';
    document.getElementById('crm-main-content').prepend(btn);
    btn.focus();
    window.__confirmResult = 'unset';
    window.CustomerCheckComponents.confirmDialog({
      title: 'Delete customer?',
      message: 'This cannot be undone.',
      confirmLabel: 'Delete',
      cancelLabel: 'Cancel',
      variant: 'danger',
    }).then((r) => { window.__confirmResult = r; });
    return btn.id;
  });
  expect(markerReady).toBe('cuc-focus-marker');
  const dangerDialog = page.locator('dialog.crm-native-dialog.confirm-dialog[open]');
  await expect(dangerDialog).toBeVisible({ timeout: 10000 });
  const dlgComputed = await page.evaluate(() => {
    const pick = (sel) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      return {
        bg: cs.backgroundColor, color: cs.color, cursor: cs.cursor,
        minHeight: cs.minHeight, height: Math.round(r.height * 100) / 100,
        borderColor: cs.borderColor, outline: cs.outlineStyle,
      };
    };
    return {
      dangerConfirm: pick('dialog.confirm-dialog .crm-btn--danger'),
      cancelSecondary: pick('dialog.confirm-dialog .crm-btn--secondary'),
      close: pick('dialog.confirm-dialog .modal-close'),
      dialogBg: (() => { const d = document.querySelector('dialog.confirm-dialog'); const cs = d ? getComputedStyle(d) : null; return cs ? { bg: cs.backgroundColor, color: cs.color, padding: cs.padding, border: cs.border, borderRadius: cs.borderRadius } : null; })(),
      inBody: !!document.querySelector('body > dialog.confirm-dialog'),
      focusTag: document.activeElement ? document.activeElement.className : '',
    };
  });
  proofs.computed.dangerDialog = dlgComputed;
  expect(dlgComputed.inBody, 'confirm dialog mounts on document.body').toBe(true);
  // Danger must be a solid error fill, not the pale --color-error tint.
  expect(dlgComputed.dangerConfirm.bg).not.toBe('rgba(0, 0, 0, 0)');
  expect(dlgComputed.dangerConfirm.cursor).toBe('pointer');
  expect(parseFloat(dlgComputed.dangerConfirm.height)).toBeGreaterThanOrEqual(44);
  expect(dlgComputed.dangerConfirm.bg).not.toBe(dlgComputed.cancelSecondary.bg);
  expect(dlgComputed.dangerConfirm.color).not.toBe(dlgComputed.cancelSecondary.color);
  await shot(page, 'cuc-dialog-confirm-danger-light');

  // Focus trap: Tab from last element wraps to first; Escape blocked on destructive.
  const focusProbe = await page.evaluate(async () => {
    const d = document.querySelector('dialog.confirm-dialog');
    const focusables = Array.from(d.querySelectorAll('button')).filter((b) => !b.disabled);
    const out = { count: focusables.length, labels: focusables.map((b) => b.textContent.trim() || b.className) };
    const last = d.querySelector('.confirm-dialog__confirm') || focusables[focusables.length - 1];
    last.focus();
    out.before = document.activeElement ? document.activeElement.className : '';
    d.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
    out.afterTab = document.activeElement ? document.activeElement.className : '';
    const first = focusables[0];
    first.focus();
    d.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true }));
    out.afterShiftTab = document.activeElement ? document.activeElement.className : '';
    return out;
  });
  proofs.dialogs.focusTrap = focusProbe;
  // Confirm dialogs carry only Cancel + Confirm (no X): Tab from last wraps to first, Shift+Tab wraps back.
  expect(focusProbe.afterTab).toContain('confirm-dialog__cancel');
  expect(focusProbe.afterShiftTab).toContain('confirm-dialog__confirm');

  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  proofs.dialogs.destructiveEscapeBlocked = await dangerDialog.count() === 1;
  expect(proofs.dialogs.destructiveEscapeBlocked, 'destructive confirm keeps Esc guarded').toBe(true);

  await page.locator('dialog.confirm-dialog .crm-btn--secondary').click();
  await expect(dangerDialog).toHaveCount(0);
  proofs.dialogs.confirmCancelResolves = await page.evaluate(() => window.__confirmResult);
  expect(proofs.dialogs.confirmCancelResolves).toBe(false);
  proofs.dialogs.confirmFocusRestored = await page.evaluate(() => document.activeElement && document.activeElement.id);
  expect(proofs.dialogs.confirmFocusRestored).toBe('cuc-focus-marker');

  // Content dialog via openDialog — Esc closes + restores focus.
  await page.evaluate(() => {
    document.getElementById('cuc-focus-marker').focus();
    window.CustomerCheckComponents.openDialog({ title: 'Customer note', content: '<p>Body copy</p><button class="crm-btn crm-btn--primary" id="cuc-dlg-primary">Save</button>' });
  });
  const contentDlg = page.locator('dialog.crm-native-dialog.crm-dialog--content[open]');
  await expect(contentDlg).toBeVisible({ timeout: 10000 });
  const contentComputed = await page.evaluate(() => {
    const el = document.querySelector('#cuc-dlg-primary');
    const cs = el ? getComputedStyle(el) : null;
    return cs ? { bg: cs.backgroundColor, color: cs.color, minHeight: cs.minHeight, cursor: cs.cursor } : null;
  });
  proofs.computed.contentDialogPrimary = contentComputed;
  expect(contentComputed.cursor).toBe('pointer');
  expect(contentComputed.bg).not.toBe('rgba(0, 0, 0, 0)');
  await shot(page, 'cuc-dialog-content-light');
  await page.keyboard.press('Escape');
  await expect(contentDlg).toHaveCount(0);
  proofs.dialogs.contentEscapeRestores = await page.evaluate(() => document.activeElement && document.activeElement.id);
  expect(proofs.dialogs.contentEscapeRestores).toBe('cuc-focus-marker');
  await page.evaluate(() => { const m = document.getElementById('cuc-focus-marker'); if (m) m.remove(); });

  // ── States: error well (API aborted) ─────────────────────────────────
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await page.route('**/apps/customercheck/api/**', (route) => route.abort());
  await page.goto('/apps/customercheck/pulse', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const errorProbe = await page.evaluate(() => {
    const wells = Array.from(document.querySelectorAll('.crm-callout--danger, .crm-empty-state, [role="alert"], .crm-error'));
    return {
      count: wells.length,
      texts: wells.slice(0, 5).map((w) => (w.textContent || '').trim().slice(0, 140)),
      hasRetry: !!Array.from(document.querySelectorAll('button, a')).find((b) => /try again|retry|reload|erneut/i.test(b.textContent || '')),
      spinners: document.querySelectorAll('.crm-skeleton[aria-busy="true"], .icon-loading').length,
    };
  });
  proofs.states.error = errorProbe;
  await shot(page, 'cuc-state-error-pulse-light');
  await page.unrouteAll({ behavior: 'ignoreErrors' });

  // ── States: empty surfaces (money / activities / conflicts) ──────────
  for (const [name, path] of [['money', '/apps/customercheck/money'], ['activities', '/apps/customercheck/activities'], ['conflicts', '/apps/customercheck/conflicts']]) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await shot(page, `cuc-state-surface-${name}-light`);
  }

  // ── 200% zoom proxy on companies ──────────────────────────────────────
  await page.goto('/apps/customercheck/companies', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#crm-main-content')).toBeVisible({ timeout: 30000 });
  await page.evaluate(() => { document.body.style.zoom = '200%'; });
  await page.waitForTimeout(400);
  const zoomProbe = await page.evaluate(() => ({
    overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 4,
    contentW: document.documentElement.scrollWidth,
    clientW: document.documentElement.clientWidth,
  }));
  proofs.states.zoom200 = zoomProbe;
  expect(zoomProbe.overflowX, 'no horizontal overflow at 200% zoom').toBe(false);
  await shot(page, 'cuc-zoom200-companies-light');
  await page.evaluate(() => { document.body.style.zoom = ''; });

  // ── Mobile 390px drawer ──────────────────────────────────────────────
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/apps/customercheck/companies', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  const menuBtn = page.locator('#crm-mobile-menu-toggle, .crm-mobile-nav__toggle, button[aria-controls="app-navigation"]').first();
  if (await menuBtn.count()) {
    await menuBtn.click();
    await page.waitForTimeout(400);
    const navComputed = await page.evaluate(() => {
      const links = Array.from(document.querySelectorAll('#app-navigation a, .crm-mobile-nav a')).filter((a) => a.offsetParent !== null);
      return links.slice(0, 6).map((a) => {
        const r = a.getBoundingClientRect();
        return { label: (a.textContent || '').trim().slice(0, 24), h: Math.round(r.height) };
      });
    });
    proofs.computed.mobileNavLinks = navComputed;
    for (const l of navComputed) {
      expect(l.h, `nav link "${l.label}" ≥44px`).toBeGreaterThanOrEqual(44);
    }
    await shot(page, 'cuc-mobile-nav-open-light');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  flushProofs();

  // ── Theme sweep: every surface × 4 themes ────────────────────────────
  for (const theme of THEMES) {
    await enableTheme(page, theme);
    for (const [name, path] of SURFACES) {
      await page.goto(path, { waitUntil: 'domcontentloaded', timeout: 45000 });
      await page.waitForTimeout(1400);
      await shot(page, `cuc-${name}-${theme}`);
    }
    flushProofs();
  }
  for (const theme of THEMES) await disableTheme(page, theme);
  await page.reload({ waitUntil: 'domcontentloaded' });
  flushProofs();
});

test('ds-chrome: denied surface as non-admin probe user', async ({ browser }) => {
  // browser.newContext() inherits the project's storageState (admin session)
  // under the config's `use` block — an explicit empty state is required to
  // land on /login unauthenticated as the probe user.
  const probeCtx = await browser.newContext({ viewport: { width: 1440, height: 900 }, storageState: { cookies: [], origins: [] } });
  const probePage = await probeCtx.newPage();
  await probePage.goto('/login', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await probePage.locator('#user').fill(process.env.CUC_DSPROBE_USER || 'cuc_dsprobe');
  await probePage.locator('#password').fill(process.env.CUC_DSPROBE_PASS || 'CucDsPr0be!x');
  await probePage.locator('form[name="login"] button[type="submit"], button[data-login-form-submit]').first().click();
  await probePage.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 45000 });
  const deniedResp = await probePage.goto('/apps/customercheck/app-settings/access', { waitUntil: 'domcontentloaded' });
  expect(deniedResp && deniedResp.status()).toBe(403);
  await expect(probePage.locator('.crm-access-denied')).toBeVisible({ timeout: 15000 });
  await probePage.screenshot({ path: join(CRAFT, 'cuc-state-denied-appsettings-light.png') });
  await probePage.goto('/apps/customercheck/', { waitUntil: 'domcontentloaded' });
  await probePage.waitForTimeout(1500);
  await probePage.screenshot({ path: join(CRAFT, 'cuc-role-user-today-light.png') });
  await probeCtx.close();
});
