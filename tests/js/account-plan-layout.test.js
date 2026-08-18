// @ts-check
/**
 * Account plan layout — design-system coach steps, page grid, numbered panels.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const pageJs = readFileSync(join(root, 'js/pages/company-detail.js'), 'utf8');
const css = readFileSync(join(root, 'css/app.css'), 'utf8');
const detail = readFileSync(join(root, 'templates/company-detail.php'), 'utf8');

describe('account plan layout', () => {
  it('uses welcome-coach step cards with progress and More options disclosure', () => {
    expect(pageJs).toContain('function accountPlanStepsHtml(plan, mapSteps)');
    expect(pageJs).toContain('crm-welcome-coach__steps crm-account-plan__guide');
    expect(pageJs).toContain('crm-welcome-coach__step--done');
    expect(pageJs).toContain('crm-account-plan__stack');
    expect(pageJs).not.toContain('crm-page-grid crm-account-plan__grid');
    expect(pageJs).toContain('crm-advanced crm-account-plan__more');
    expect(pageJs).toContain("t('More options')");
    expect(pageJs).not.toContain("t('Priority, owner & review')");
  });

  it('structures goal and moves in numbered panels inside the grid', () => {
    expect(pageJs).toContain('crm-account-plan__col--plan');
    expect(pageJs).toContain('crm-account-plan__col--moves');
    expect(pageJs).toContain('crm-account-plan__panel crm-account-plan__panel--goal');
    expect(pageJs).toContain('crm-account-plan__panel crm-account-plan__panel--moves');
    expect(pageJs).toContain('crm-account-plan__panel-num');
    expect(pageJs).toContain("t('Write the goal')");
    expect(detail).toContain('<section class="crm-card crm-card--account-plan"');
    expect(detail).not.toContain('<details class="crm-card crm-card--collapsible" id="crm-account-plan"');
  });

  it('css defines full-width vertical account plan with panel chrome', () => {
    expect(css).toMatch(/\.crm-account-plan\s*\{[^}]*max-width:\s*none/s);
    expect(css).toContain('.crm-account-plan__guide');
    expect(css).toContain('.crm-account-plan__stack {');
    expect(css).toMatch(/\.crm-account-plan__stack\s*\{[^}]*flex-direction:\s*column/s);
    expect(css).toMatch(/\.crm-account-plan__guide\.crm-welcome-coach__steps\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s);
    expect(css).toMatch(/@media\s*\(min-width:\s*720px\)\s*\{[^}]*\.crm-account-plan__guide\.crm-welcome-coach__steps\s*\{[^}]*repeat\(3,\s*minmax\(0,\s*1fr\)\)/s);
    expect(css).not.toMatch(/\.crm-account-plan__guide[^{]*\{[^}]*flex-direction:\s*column/s);
    expect(css).toContain('.crm-account-plan__panel {');
    expect(css).toMatch(/\.crm-account-plan__panel-title\s*\{[^}]*align-items:\s*center/s);
    expect(css).not.toMatch(/\.crm-account-plan__panel-title\s*\{[^}]*align-items:\s*flex-start/s);
    expect(css).toContain('.crm-account-plan__panel-num {');
    expect(css).not.toContain('.crm-account-plan__layout {');
  });

  it('guards save with aria-busy and resolves owner display names', () => {
    expect(pageJs).toContain('function accountPlanOwnerSearchLabel(plan)');
    expect(pageJs).toContain("form.getAttribute('aria-busy') === 'true'");
    expect(pageJs).toContain("t('Saving…')");
    expect(pageJs).toContain('plan.ownerDisplayName');
    expect(pageJs).toContain('novalidate data-plan-updated-at=');
    expect(pageJs).toContain('form.isConnected');
    expect(pageJs).toContain('function announceAccountPlanSaved()');
    expect(pageJs).toContain('crm-account-plan__saved');
    expect(pageJs).toContain('loadAccountPlan(savedPlan)');
    expect(pageJs).toContain("C.showToast({ type: 'success'");
    expect(pageJs).toContain('horizon: plan.horizon ||');
  });
});
