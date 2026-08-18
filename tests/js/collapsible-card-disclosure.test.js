// @ts-check
/**
 * Collapsible card disclosure — CSS + template contract (Vitest).
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const appRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const css = readFileSync(join(appRoot, 'css', 'app.css'), 'utf8');
const partial = readFileSync(
  join(appRoot, 'templates', 'common', 'crm-collapsible-disclosure.php'),
  'utf8',
);
const detail = readFileSync(join(appRoot, 'templates', 'company-detail.php'), 'utf8');

describe('collapsible card disclosure', () => {
  it('partial exposes Open and Close labels with icon', () => {
    expect(partial).toContain('crm-card__disclosure');
    expect(partial).toContain('crm-card__disclosure-label--closed');
    expect(partial).toContain('crm-card__disclosure-label--open');
    expect(partial).toContain("t('Open')");
    expect(partial).toContain("t('Close')");
  });

  it('account plan is an always-open section without outer disclosure', () => {
    const start = detail.indexOf('<section class="crm-card crm-card--account-plan"');
    expect(start).toBeGreaterThan(-1);
    const chunk = detail.slice(start, start + 900);
    expect(chunk).toContain('crm-card--account-plan');
    expect(chunk).not.toContain('crm-collapsible-disclosure.php');
    expect(chunk).not.toContain('<summary');
  });

  it('css keeps 44px touch targets and toggles labels by open state', () => {
    expect(css).toMatch(/\.crm-card__disclosure[^{]*\{[^}]*min-height:\s*44px/s);
    expect(css).toMatch(/\.crm-card__disclosure[^{]*\{[^}]*min-width:\s*44px/s);
    expect(css).toContain('details:not([open]) > summary .crm-card__disclosure-label--open');
    expect(css).toContain('details[open] > summary .crm-card__disclosure-label--closed');
    expect(css).not.toMatch(/crm-card--collapsible[^\{]*::after[\s\S]{0,120}content:\s*'▸'/);
  });

  it('summary rows use flex layout (not block float chevron)', () => {
    expect(css).toMatch(
      /summary\.crm-card__header--summary[^{]*\{[^}]*display:\s*flex/s,
    );
  });
});
