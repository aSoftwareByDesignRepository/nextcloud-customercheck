// @ts-check
/**
 * Static design-token compliance gauntlet.
 *
 * Design-system rule (planning/design-system/DESIGN-SYSTEM.md §0.1):
 * "Never hard-code hex colours in feature CSS. Read Nextcloud --color-*
 *  (…) and derive tints with color-mix()." Raw colour literals are only
 * allowed as *fallbacks* inside var(--token, <fallback>), so the value
 * always follows the active Nextcloud theme when the variable exists.
 *
 * This test parses every shipped stylesheet, strips comments and var()
 * fallback arguments, and fails on any remaining raw colour literal.
 * It is the regression guard behind the "no hardcoded colors" contract.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const appRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const cssRoot = join(appRoot, 'css');

/** Recursively collect all .css files under css/. */
function collectCssFiles(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      out.push(...collectCssFiles(full));
    } else if (entry.endsWith('.css')) {
      out.push(full);
    }
  }
  return out.sort();
}

/** Remove CSS comments. */
function stripComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, ' ');
}

/**
 * Remove the fallback argument of every var() call (nesting-safe).
 * var(--x, #fff)          -> var(--x)
 * var(--x, var(--y, #f00)) -> var(--x)
 */
function stripVarFallbacks(css) {
  let out = '';
  let i = 0;
  while (i < css.length) {
    const idx = css.indexOf('var(', i);
    if (idx === -1) {
      out += css.slice(i);
      break;
    }
    out += css.slice(i, idx);
    let depth = 0;
    let j = idx + 4;
    let commaAt = -1;
    for (; j < css.length; j++) {
      const c = css[j];
      if (c === '(') {
        depth++;
      } else if (c === ')') {
        if (depth === 0) break;
        depth--;
      } else if (c === ',' && depth === 0 && commaAt === -1) {
        commaAt = j;
      }
    }
    const name = commaAt === -1 ? css.slice(idx + 4, j) : css.slice(idx + 4, commaAt);
    out += `var(${name})`;
    i = j + 1;
  }
  return out;
}

/** Extract declaration values only (skips selectors, so #ids never false-positive). */
function declarationValues(css) {
  const values = [];
  const re = /:\s*([^;{}]+)(?=[;}])/g;
  let m;
  while ((m = re.exec(css)) !== null) {
    values.push({ value: m[1], offset: m.index });
  }
  return values;
}

const RAW_COLOR = /#[0-9a-fA-F]{3,8}\b|(?:rgb|rgba|hsl|hsla)\(\s*[\d.]/;

describe('CSS design-token compliance (no hardcoded colors)', () => {
  const files = collectCssFiles(cssRoot);

  it('finds the stylesheet tree', () => {
    expect(files.length).toBeGreaterThan(10);
  });

  for (const file of files) {
    const rel = relative(appRoot, file);
    it(`${rel}: raw colours only appear as var() fallbacks`, () => {
      const css = stripComments(readFileSync(file, 'utf8'));
      const offenders = [];
      for (const { value } of declarationValues(css)) {
        const cleaned = stripVarFallbacks(value);
        const match = cleaned.match(RAW_COLOR);
        if (match) {
          offenders.push(`${value.trim().slice(0, 120)}  →  offending literal: ${match[0]}`);
        }
      }
      expect(offenders, `Hardcoded colour(s) outside var() fallback in ${rel}:\n${offenders.join('\n')}`).toEqual([]);
    });
  }

  it('feature CSS has zero hex (even as var fallbacks); only tokens.css may keep NC fallbacks', () => {
    const offenders = [];
    for (const file of files) {
      const rel = relative(appRoot, file);
      if (rel === 'css/common/tokens.css') continue;
      const css = stripComments(readFileSync(file, 'utf8'));
      for (const { value } of declarationValues(css)) {
        const match = value.match(RAW_COLOR);
        if (match) {
          offenders.push(`${rel}: ${value.trim().slice(0, 100)} → ${match[0]}`);
        }
      }
    }
    expect(offenders, `Hex outside tokens.css:\n${offenders.join('\n')}`).toEqual([]);
  });

  it('theme-bound tokens are defined on body (Nextcloud sets --color-* on body)', () => {
    const tokens = stripComments(readFileSync(join(cssRoot, 'common', 'tokens.css'), 'utf8'));
    const bodyBlocks = [...tokens.matchAll(/(^|\})\s*body\s*\{([\s\S]*?)\}/g)].map((m) => m[2]).join('\n');
    for (const token of [
      '--crm-bg-soft',
      '--crm-bg-card',
      '--crm-surface-muted',
      '--crm-text',
      '--crm-muted',
      '--crm-border',
      '--crm-tint-success',
      '--crm-tint-warning',
      '--crm-tint-danger',
      '--crm-tint-info',
      '--crm-shadow-sm',
      '--crm-shadow-md',
      '--crm-scrim',
      '--crm-danger-fill',
      '--crm-danger-on-fill',
      '--crm-danger-ink',
    ]) {
      expect(bodyBlocks, `${token} must be defined inside body {}`).toContain(`${token}:`);
    }
    for (const token of [
      '--crm-support-brand-plate',
      '--crm-support-brand-ink',
      '--crm-support-brand-muted',
    ]) {
      expect(bodyBlocks, `${token} must be defined inside body {}`).toContain(`${token}:`);
    }
  });

  it('touch targets and danger fill follow design-system §0.4 / §3.3', () => {
    const tokens = stripComments(readFileSync(join(cssRoot, 'common', 'tokens.css'), 'utf8'));
    expect(tokens).toContain('--crm-touch: 44px');
    expect(tokens).toContain('--crm-touch-lg: 48px');
    expect(tokens).toContain('--crm-danger-fill: var(--color-element-error');
    const app = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'));
    expect(app).toMatch(/\.crm-btn--danger[\s\S]*?background-color:\s*var\(--crm-danger-fill\)/);
    expect(app).not.toMatch(/\.crm-btn--danger[\s\S]*?background-color:\s*var\(--color-error/);
  });

  it('legacy --space-* aliases map to design-system --crm-space-* (px grid)', () => {
    const tokens = stripComments(readFileSync(join(cssRoot, 'common', 'tokens.css'), 'utf8'));
    expect(tokens).toContain('--space-1: var(--crm-space-1)');
    expect(tokens).toContain('--space-5: var(--crm-space-5)');
    expect(tokens).toContain('--space-8: var(--crm-space-8)');
    expect(tokens).toContain('--crm-nav-width: 280px');
    // Must not redefine space-5 as the old rem/20px drift.
    expect(tokens).not.toMatch(/--space-5:\s*1\.25rem/);
    expect(tokens).not.toMatch(/--crm-space-5:\s*1\.25rem/);
  });

  it('modals use main text colour (not muted) for AA body contrast', () => {
    const css = stripComments(readFileSync(join(cssRoot, 'common', 'components.css'), 'utf8'));
    expect(css).toMatch(/\.modal,\s*\n?\s*\.crm-dialog\s*\{[\s\S]*?color:\s*var\(--crm-text/);
  });

  it('theme-bound tokens derive from Nextcloud --color-* variables', () => {
    const tokens = stripComments(readFileSync(join(cssRoot, 'common', 'tokens.css'), 'utf8'));
    for (const ncVar of [
      '--color-main-background',
      '--color-main-text',
      '--color-text-maxcontrast',
      '--color-success',
      '--color-warning',
      '--color-error',
      '--color-primary-element',
      '--border-radius-large',
    ]) {
      expect(tokens, `tokens.css must consume ${ncVar}`).toContain(`var(${ncVar}`);
    }
  });

  it('interactive controls never use sub-44px rem/em min-size (NC 15px root collapse)', () => {
    // FARM-RECURRING BUG guard: rem-based min-heights collapse under Nextcloud's
    // 15px root font (2.75rem -> 41.25px). Interactive selectors must use px or
    // var(--crm-touch*) tokens. Threshold: 2.94rem = 44px at a 15px root.
    const INTERACTIVE = /\.crm-btn\b|\.crm-quick-filter\b|\.crm-review-sort\b|__toggle\b|__remove\b|modal-close\b|toast-close\b|\.crm-suggest__item\b|\.crm-chip__remove\b/;
    const offenders = [];
    for (const file of files) {
      const rel = relative(appRoot, file);
      const css = stripComments(readFileSync(file, 'utf8'));
      // Naive rule scan: selector { body } — sufficient for our flat authored CSS.
      const ruleRe = /([^{}@]+)\{([^{}]*)\}/g;
      let m;
      while ((m = ruleRe.exec(css)) !== null) {
        const selector = m[1].trim();
        const bodyCss = m[2];
        if (!INTERACTIVE.test(selector)) continue;
        for (const decl of bodyCss.split(';')) {
          const mm = decl.match(/min-(?:height|width)\s*:\s*([\d.]+)(rem|em)\b/);
          if (mm && parseFloat(mm[1]) < 2.94) {
            offenders.push(`${rel}: ${selector.replace(/\s+/g, ' ').slice(0, 90)} { ${decl.trim()} }`);
          }
        }
      }
    }
    expect(offenders, `Sub-44px rem/em min-size on interactive controls:\n${offenders.join('\n')}`).toEqual([]);
  });

  it('regression: crm-review-sort + sales-compose cc-toggle keep >=44px floors', () => {
    const app = stripComments(readFileSync(join(cssRoot, 'app.css'), 'utf8'));
    expect(app).toMatch(/\.crm-review-sort\s*\{[^}]*min-height:\s*var\(--crm-touch/);
    expect(app).toMatch(/\.crm-sales-compose__cc-toggle\s*\{[^}]*min-height:\s*var\(--crm-touch/);
  });

  it('legacy primary alias maps to --color-primary-element (accent + AA)', () => {
    const colors = stripComments(readFileSync(join(cssRoot, 'common', 'colors.css'), 'utf8'));
    expect(colors).toContain('--arbeitszeitcheck-color-primary: var(--color-primary-element)');
    expect(colors).not.toMatch(/--arbeitszeitcheck-color-primary:\s*var\(--color-primary\)\s*;/);
    expect(colors).toMatch(/--arbeitszeitcheck-color-success-light:[^;]*var\(--color-main-background\)/);
    expect(colors).not.toMatch(/--arbeitszeitcheck-color-success-light:[^;]*transparent/);
    expect(colors).not.toMatch(/--arbeitszeitcheck-color-success-light:[^;]*#[0-9a-fA-F]{3,8}/);
    expect(colors).toMatch(/--arbeitszeitcheck-color-button-danger:\s*var\(--crm-danger-fill/);
    const a11y = stripComments(readFileSync(join(cssRoot, 'common', 'accessibility.css'), 'utf8'));
    expect(a11y).toContain('@media (prefers-contrast: more)');
    expect(a11y).not.toContain('@media (prefers-contrast: high)');
    expect(a11y).toContain('body[data-theme-light-highcontrast]');
    const responsive = stripComments(readFileSync(join(cssRoot, 'common', 'responsive.css'), 'utf8'));
    expect(responsive).toContain('max-width: 100% !important');
    expect(responsive).not.toContain('max-width: 100vw !important');
  });
});
