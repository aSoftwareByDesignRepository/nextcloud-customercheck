/**
 * @vitest-environment node
 *
 * Guards the Account Plan / Promises hydrate boot order.
 * Calling loaders while `let *Generation` is still in the TDZ throws outside
 * try/catch and leaves the UI stuck on “Loading account plan…”.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const pageJs = readFileSync(join(root, 'js/pages/company-detail.js'), 'utf8');

describe('company 360 account-plan hydrate boot', () => {
  it('initializes generation counters before the hydrator boot trio', () => {
    const cmdGen = pageJs.indexOf('let commandCenterGeneration = 0');
    const planGen = pageJs.indexOf('let accountPlanGeneration = 0');
    const promGen = pageJs.indexOf('let promisesGeneration = 0');
    const bootMarker = 'loadCommandCenter();\n  loadAccountPlan();\n  loadPromises();';
    const boot = pageJs.indexOf(bootMarker);

    expect(cmdGen).toBeGreaterThan(-1);
    expect(planGen).toBeGreaterThan(-1);
    expect(promGen).toBeGreaterThan(-1);
    expect(boot).toBeGreaterThan(-1);
    expect(pageJs.split(bootMarker).length - 1).toBe(1);
    expect(planGen).toBeLessThan(boot);
    expect(promGen).toBeLessThan(boot);
    expect(cmdGen).toBeLessThan(planGen);
    expect(planGen).toBeLessThan(promGen);
  });

  it('bumps generations inside try and renders a recoverable error path', () => {
    expect(pageJs).toContain('generation = ++accountPlanGeneration;');
    expect(pageJs).toContain('generation = ++promisesGeneration;');
    expect(pageJs).toContain("t('Could not load account plan.')");
    expect(pageJs).toContain("t('Loading account plan…')");
    expect(pageJs).toContain('id="crm-account-plan-form" class="crm-form crm-form--stack"');
  });

  it('documents why calling before let init hangs the UI', () => {
    expect(() => {
      // Mimics the pre-fix boot order: invoke while `let gen` is still in TDZ.
      // eslint-disable-next-line no-new-func
      Function(`
        load();
        let gen = 0;
        function load() { gen = ++gen; }
      `)();
    }).toThrow(ReferenceError);
  });
});
