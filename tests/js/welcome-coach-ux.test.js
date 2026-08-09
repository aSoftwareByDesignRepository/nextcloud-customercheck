/**
 * @vitest-environment node
 *
 * Welcome coach layout contract — BudgetCheck-style step cards on CRM tokens.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const html = readFileSync(join(root, 'templates/company-detail.php'), 'utf8');
const css = readFileSync(join(root, 'css/app.css'), 'utf8');

describe('welcome coach UX', () => {
  it('renders three step cards with trigger-safe CTAs', () => {
    const marker = 'crm-welcome-coach" id="crm-welcome-coach"';
    const start = Math.max(0, html.indexOf(marker) - 80);
    const end = html.indexOf('id="crm-communication"', start);
    expect(html.indexOf(marker)).toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(start);
    const chunk = html.slice(start, end);
    expect(chunk).toContain('crm-welcome-coach__steps');
    expect(chunk).toContain('crm-welcome-coach__step');
    expect(chunk).toContain('Three short steps to make this customer useful.');
    expect(chunk).toContain('data-crm-trigger="crm-add-person"');
    expect(chunk).toContain('data-crm-trigger="crm-add-deal"');
    expect(chunk).toContain('data-crm-trigger="crm-log-email"');
    expect(chunk).toContain('data-crm-trigger="crm-schedule-callback"');
    expect(chunk).toContain("IconCatalog::renderCalloutWell('info', 'info')");
    expect((chunk.match(/crm-welcome-coach__step-num/g) || []).length).toBe(3);
  });

  it('ships a responsive three-column step grid with 44px CTAs', () => {
    expect(css).toContain('.crm-callout.crm-welcome-coach');
    expect(css).toContain('grid-template-columns: repeat(3, minmax(0, 1fr))');
    expect(css).toMatch(
      /#app-content\.crm-app \.crm-welcome-coach__step-actions \.crm-btn \{\s*min-height: var\(--crm-touch, 44px\);/,
    );
    expect(css).toContain('@media (forced-colors: active)');
  });
});
