/**
 * @vitest-environment jsdom
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it } from 'vitest';

function loadCore() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/list-toolbar-core.js');
  const code = readFileSync(path, 'utf8');
  // eslint-disable-next-line no-eval
  eval(code);
  return window.CustomerCheckListToolbar;
}

describe('CustomerCheckListToolbar', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    delete window.CustomerCheckListToolbar;
  });

  it('toggles panel visibility and open class on filter button click', () => {
    document.body.innerHTML = `
      <div id="crm-money-controls">
        <button type="button" id="crm-money-filter-toggle" aria-expanded="false" aria-controls="crm-money-filter">Filter</button>
        <section id="crm-money-filter" class="crm-filter-panel" hidden></section>
      </div>
    `;
    const ListToolbar = loadCore();
    ListToolbar.bind({
      controlsId: 'crm-money-controls',
      toggleId: 'crm-money-filter-toggle',
      panelId: 'crm-money-filter',
    });

    const wrap = document.getElementById('crm-money-controls');
    const toggle = document.getElementById('crm-money-filter-toggle');
    const panel = document.getElementById('crm-money-filter');

    expect(wrap.classList.contains('crm-list-controls--filter-open')).toBe(false);
    expect(toggle.getAttribute('aria-expanded')).toBe('false');

    toggle.click();
    expect(panel.hasAttribute('hidden')).toBe(false);
    expect(wrap.classList.contains('crm-list-controls--filter-open')).toBe(true);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');

    toggle.click();
    expect(panel.hasAttribute('hidden')).toBe(true);
    expect(wrap.classList.contains('crm-list-controls--filter-open')).toBe(false);
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
  });

  it('syncs initial open state when panel is visible on load', () => {
    document.body.innerHTML = `
      <div id="crm-agreements-controls">
        <button type="button" id="crm-agreements-filter-toggle" aria-expanded="false">Filter</button>
        <section id="crm-agreements-filter" class="crm-filter-panel"></section>
      </div>
    `;
    const ListToolbar = loadCore();
    ListToolbar.bind({
      controlsId: 'crm-agreements-controls',
      toggleId: 'crm-agreements-filter-toggle',
      panelId: 'crm-agreements-filter',
    });

    const wrap = document.getElementById('crm-agreements-controls');
    const toggle = document.getElementById('crm-agreements-filter-toggle');
    expect(wrap.classList.contains('crm-list-controls--filter-open')).toBe(true);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
  });

  it('returns null when panel is missing (fragment-safe)', () => {
    const ListToolbar = loadCore();
    const sync = ListToolbar.bind({
      controlsId: 'missing-controls',
      toggleId: 'missing-toggle',
      panelId: 'missing-panel',
    });
    expect(sync).toBeNull();
  });

  it('supports custom openClass for pipeline parity', () => {
    document.body.innerHTML = `
      <div id="crm-pipeline-controls">
        <button type="button" id="crm-pipeline-filter-toggle" aria-expanded="false">Filter</button>
        <section id="crm-pipeline-filter" hidden></section>
      </div>
    `;
    const ListToolbar = loadCore();
    ListToolbar.bind({
      controlsId: 'crm-pipeline-controls',
      toggleId: 'crm-pipeline-filter-toggle',
      panelId: 'crm-pipeline-filter',
      openClass: 'crm-pipeline-controls--filter-open',
    });

    document.getElementById('crm-pipeline-filter-toggle').click();
    expect(document.getElementById('crm-pipeline-controls').classList.contains('crm-pipeline-controls--filter-open')).toBe(true);
    expect(document.getElementById('crm-pipeline-controls').classList.contains('crm-list-controls--filter-open')).toBe(false);
  });

  it('opens companies filter panel when archived quick chip selects archived=1', () => {
    document.body.innerHTML = `
      <div id="crm-companies-controls">
        <button type="button" id="crm-companies-filter-toggle" aria-expanded="false">Filter</button>
        <section id="crm-companies-filter" class="crm-filter-panel" hidden></section>
      </div>
    `;
    const ListToolbar = loadCore();
    ListToolbar.syncFilterPanelsFromUrl('http://localhost/apps/customercheck/companies?archived=1');

    expect(document.getElementById('crm-companies-filter').hasAttribute('hidden')).toBe(false);
    expect(document.getElementById('crm-companies-controls').classList.contains('crm-list-controls--filter-open')).toBe(true);
    expect(document.getElementById('crm-companies-filter-toggle').getAttribute('aria-expanded')).toBe('true');
  });
});
