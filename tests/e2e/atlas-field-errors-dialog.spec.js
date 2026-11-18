// @ts-check
import { test, expect } from '@playwright/test';
import { login, credsFromEnv, gotoCrm } from './helpers/auth.js';

/**
 * ATLAS render leg — field-errors.css `:is(#app-content.crm-app, .crm-dialog)`
 * scope widening (2aebb1c). Proves:
 *   1. A named-field error inside a real .crm-dialog renders the error
 *      treatment (aria-invalid border + .crm-field-error) — the selector
 *      intent is realized, not merely widened.
 *   2. The .crm-dialog arm is what styles it (drop the class → treatment
 *      falls back to dialog defaults).
 *   3. Page-surface field errors keep the identical treatment (the widening
 *      is a strict superset — page behavior unchanged).
 *
 * Note: .form-input carries `transition: border-color .15s` — a mark applied
 * after the element's first style resolution transitions 125→error. We mark
 * in one evaluate, let the transition finish, then read computed styles.
 */
test.describe('field errors render inside .crm-dialog', () => {
  test('named-field error styled inside dialog and on page surface', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC credentials');
    await login(page, credsFromEnv('ADMIN'));
    await gotoCrm(page, '/apps/customercheck/companies');

    const setup = await page.evaluate(() => {
      const app = document.querySelector('#app-content.crm-app');
      if (!app) return { fatal: 'no #app-content.crm-app' };
      if (!window.CheckFieldErrors) return { fatal: 'no CheckFieldErrors' };

      const probeInput = (name) => {
        const el = document.createElement('input');
        el.type = 'text';
        el.name = name;
        el.className = 'crm-input form-input';
        return el;
      };

      const pageInp = probeInput('atlas_probe_page');
      app.appendChild(pageInp);

      // Real .crm-dialog (same class bundle components.js assigns).
      const dlg = document.createElement('dialog');
      dlg.className = 'crm-native-dialog modal modal--sm crm-dialog';
      const dlgInp = probeInput('atlas_probe_dialog');
      dlg.appendChild(dlgInp);
      document.body.appendChild(dlg);
      dlg.showModal();

      window.CheckFieldErrors.install({ prefix: 'crm' });
      window.CheckFieldErrors.markValidationFields({
        atlas_probe_dialog: 'Atlas dialog error.',
        atlas_probe_page: 'Atlas page error.',
      });
      return { ok: true };
    });
    expect(setup.fatal).toBeUndefined();

    // Let the border-color transition (0.15s) finish before reading.
    await page.waitForTimeout(300);

    const out = await page.evaluate(() => {
      const app = document.querySelector('#app-content.crm-app');
      const dlg = document.querySelector('dialog.crm-dialog');
      const dlgInp = dlg.querySelector('input[name="atlas_probe_dialog"]');
      const pageInp = app.querySelector('input[name="atlas_probe_page"]');
      const cs = (el) => getComputedStyle(el);
      const dlgErr = dlg.querySelector('.crm-field-error');
      const pageErr = app.querySelector('.crm-field-error');
      const marked = {
        dialogInvalid: dlgInp.getAttribute('aria-invalid'),
        pageInvalid: pageInp.getAttribute('aria-invalid'),
        dlgErrText: dlgErr ? dlgErr.textContent : null,
        pageErrText: pageErr ? pageErr.textContent : null,
        dlgErrColor: dlgErr ? cs(dlgErr).color : null,
        dlgErrWeight: dlgErr ? cs(dlgErr).fontWeight : null,
        pageErrColor: pageErr ? cs(pageErr).color : null,
        dlgBorder: cs(dlgInp).borderColor,
        dlgShadow: cs(dlgInp).boxShadow,
        pageBorder: cs(pageInp).borderColor,
        pageShadow: cs(pageInp).boxShadow,
      };

      // Oracle: what the error border color SHOULD resolve to.
      const oracle = document.createElement('input');
      oracle.style.borderColor = 'var(--color-error-text, var(--crm-danger-ink, var(--color-error)))';
      app.appendChild(oracle);
      marked.expectBorder = cs(oracle).borderColor;
      oracle.remove();

      // Mutation leg: stripping .crm-dialog must drop the dialog-arm styling.
      dlg.classList.remove('crm-dialog');
      window.__atlasStrip = { dlg, dlgInp, dlgErr, pageInp, pageErr };

      return marked;
    });

    await page.waitForTimeout(300);
    const out2 = await page.evaluate(() => {
      const { dlg, dlgInp, dlgErr, pageInp, pageErr } = window.__atlasStrip;
      const cs = (el) => getComputedStyle(el);
      const post = {
        dlgBorderAfterStrip: cs(dlgInp).borderColor,
        dlgShadowAfterStrip: cs(dlgInp).boxShadow,
        dlgErrColorAfterStrip: dlgErr ? cs(dlgErr).color : null,
      };
      // Cleanup.
      window.CheckFieldErrors.clearFieldMarks(document);
      dlg.close();
      dlg.remove();
      pageInp.remove();
      pageErr && pageErr.remove();
      delete window.__atlasStrip;
      return post;
    });
    Object.assign(out, out2);

    // 1. Real marker ran in both scopes.
    expect(out.dialogInvalid).toBe('true');
    expect(out.pageInvalid).toBe('true');
    expect(out.dlgErrText).toBe('Atlas dialog error.');
    expect(out.pageErrText).toBe('Atlas page error.');
    // 2. Dialog-arm styling realized: border+shadow match the error oracle.
    expect(out.dlgBorder).toBe(out.expectBorder);
    expect(out.dlgShadow).toContain(out.expectBorder);
    expect(out.dlgErrColor).toBe(out.expectBorder);
    expect(Number(out.dlgErrWeight)).toBeGreaterThanOrEqual(600);
    // 3. Mutation leg: without .crm-dialog the treatment is gone.
    expect(out.dlgBorderAfterStrip).not.toBe(out.expectBorder);
    expect(out.dlgShadowAfterStrip).not.toContain(out.expectBorder);
    expect(out.dlgErrColorAfterStrip).not.toBe(out.expectBorder);
    // 4. Page surface unchanged: identical treatment.
    expect(out.pageBorder).toBe(out.expectBorder);
    expect(out.pageShadow).toContain(out.expectBorder);
    expect(out.pageErrColor).toBe(out.expectBorder);
  });
});
