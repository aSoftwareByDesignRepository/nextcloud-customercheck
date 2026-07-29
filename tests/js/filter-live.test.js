import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const src = readFileSync(join(root, 'js/common/filter-live.js'), 'utf8');

describe('filter-live.js', () => {
  it('wires select change to requestSubmit', () => {
    expect(src).toContain('requestSubmit');
    expect(src).toContain("addEventListener('change'");
    expect(src).toContain('crm-filter-panel__form');
  });

  it('debounces search input', () => {
    expect(src).toContain('DEBOUNCE_MS');
    expect(src).toMatch(/350/);
    expect(src).toContain("addEventListener('input'");
  });

  it('exports CustomerCheckFilterLive for re-init', () => {
    expect(src).toContain('window.CustomerCheckFilterLive');
    expect(src).toContain('wireLiveFilter');
  });
});
