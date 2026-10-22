/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';

function loadNewDealForm() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/new-deal-form.js');
  const code = readFileSync(path, 'utf8');
  const sandbox = { window: {}, Date, String, parseInt, setTimeout, clearTimeout, document: { getElementById: () => null } };
  vm.runInNewContext(code, sandbox);
  return sandbox.window.CustomerCheckNewDealForm;
}

describe('CustomerCheckNewDealForm', () => {
  it('exports open helper and open stage allow-list', () => {
    const mod = loadNewDealForm();
    expect(mod).toBeTruthy();
    expect(typeof mod.open).toBe('function');
    expect(typeof mod.offerNextStep).toBe('function');
    expect(mod.OPEN_STAGES).toEqual(['lead', 'contacted', 'quoted', 'negotiation']);
    expect(mod.OPEN_STAGES).not.toContain('won');
    expect(mod.OPEN_STAGES).not.toContain('lost');
    expect(mod.WIN_CHANCE.map((o) => o.percent)).toEqual([25, 50, 75, 100]);
    expect(mod.WIN_CHANCE.map((o) => o.value)).toEqual(['pipeline', 'upside', 'commit', 'certain']);
    expect(typeof mod.winChanceOptionsHtml).toBe('function');
    const html = mod.winChanceOptionsHtml((s) => s, (s) => s, '');
    expect(html).toContain('value="pipeline"');
    expect(html).toContain('25%');
    expect(html).toContain('100%');
    expect(html).toContain('value="certain"');
    expect((html.match(/value=""/g) || []).length).toBe(1);
    expect(html).not.toContain('This month likely');
    expect(html).not.toContain('won');
  });

  it('fills %s in the next-step title when base t never sprintfs (window.t semantics)', async () => {
    const mod = loadNewDealForm();
    let captured = '';
    const C = {
      openDialog: (o) => { captured = String(o && o.content || ''); },
      closeModal: () => {},
    };
    const rawT = (m) => m;
    try {
      await mod.offerNextStep({
        components: C,
        api: {},
        t: rawT,
        escapeHtml: (s) => String(s),
        deal: { id: 5, title: 'Acme rollout' },
        companyId: 7,
      });
    } catch (e) { /* stub document has no real form — content already captured */ }
    expect(captured).toContain('Follow up: Acme rollout');
    expect(captured).not.toContain('%s');
  });
});
