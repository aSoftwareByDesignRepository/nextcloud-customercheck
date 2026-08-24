/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

describe('team capacity UX', () => {
  it('uses accordion panels with one open by default and collapsed bulk options', () => {
    const js = readFileSync(join(root, 'js/pages/persona.js'), 'utf8');
    expect(js).toContain("'<details class=\"crm-team-capacity__panel\"'");
    expect(js).toContain('teamCapacityPanel');
    expect(js).toContain('One step at a time');
    expect(js).toContain('crm-team-capacity__options');
    expect(js).toContain('crm-team-capacity__confirm');
    expect(js).toContain('crm-team-capacity__pickers');
    expect(js).toContain('capacityUi.bindExclusiveAccordion(capacityHost, 0);');
    expect(js).toContain('validateOooCoverInput');
    expect(js).toContain('isoDate(oooForm');
    expect(js).toMatch(/teamCapacityPanel\(\s*bulkStep[\s\S]*?false,\s*\)/);
    expect(js).toMatch(/teamCapacityPanel\(\s*1[\s\S]*?true\)/);
    expect(js).toMatch(/teamCapacityPanel\(\s*escStep[\s\S]*?false\)/);
    expect(js).not.toContain('crm-team-capacity__card');
    expect(js).not.toContain('Three separate steps');
    expect(js).not.toContain('Search colleagues by name — never type a user id.');
  });

  it('styles panels as full-width themed cards with touch-friendly actions', () => {
    const css = readFileSync(join(root, 'css/app.css'), 'utf8');
    expect(css).toMatch(/\.crm-team-capacity__panel\s*\{[\s\S]*?border-radius:/);
    expect(css).toMatch(/\.crm-team-capacity__stack[\s\S]*?max-width:\s*none/);
    expect(css).toContain('.crm-team-capacity__panel-body');
    expect(css).toMatch(/\.crm-team-capacity__actions[\s\S]*?justify-content:\s*flex-end/);
    expect(css).toMatch(
      /\.crm-team-capacity__dates \.crm-datepicker-host--flex \{[^}]*align-items:\s*stretch;[^}]*width:\s*100%;/,
    );
    expect(css).toContain('.crm-team-capacity__panel > summary');
    expect(css).not.toContain('.crm-team-capacity__card');
  });

  it('team template includes collapsible disclosure affordance', () => {
    const html = readFileSync(join(root, 'templates/team.php'), 'utf8');
    expect(html).toContain('crm-collapsible-disclosure.php');
  });
});
