/**
 * @vitest-environment jsdom
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it } from 'vitest';

function loadCore() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/pipeline-view-core.js');
  const code = readFileSync(path, 'utf8');
  // eslint-disable-next-line no-eval
  eval(code);
  return window.CustomerCheckPipelineView;
}

function markup() {
  return `
    <div id="crm-live-region"></div>
    <div class="crm-pipeline-toolbar__views" role="group">
      <button type="button" class="crm-btn crm-btn--primary" data-crm-pipeline-view="table" aria-pressed="true">Table</button>
      <button type="button" class="crm-btn crm-btn--secondary" data-crm-pipeline-view="board" aria-pressed="false">Board</button>
    </div>
    <div class="crm-pipeline-toolbar__period">
      <a href="/apps/customercheck/pipeline">All</a>
      <a href="/apps/customercheck/pipeline?period=month">This month</a>
    </div>
    <section id="crm-pipeline-table"></section>
    <section id="crm-pipeline-board" hidden></section>
  `;
}

describe('CustomerCheckPipelineView', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    delete window.CustomerCheckPipelineView;
  });

  it('normalizes only table and board', () => {
    const Core = loadCore();
    expect(Core.normalizePipelineView('board')).toBe('board');
    expect(Core.normalizePipelineView('table')).toBe('table');
    expect(Core.normalizePipelineView('kanban')).toBe('');
    expect(Core.normalizePipelineView('')).toBe('');
    expect(Core.normalizePipelineView(null)).toBe('');
  });

  it('applies board view in place with aria-pressed', () => {
    document.body.innerHTML = markup();
    const Core = loadCore();
    expect(Core.applyPipelineViewDom(document, 'board')).toBe('board');
    expect(document.getElementById('crm-pipeline-table').hasAttribute('hidden')).toBe(true);
    expect(document.getElementById('crm-pipeline-board').hasAttribute('hidden')).toBe(false);
    expect(document.querySelector('[data-crm-pipeline-view="board"]').getAttribute('aria-pressed')).toBe('true');
    expect(document.querySelector('[data-crm-pipeline-view="table"]').getAttribute('aria-pressed')).toBe('false');
    expect(document.querySelector('[data-crm-pipeline-view="board"]').classList.contains('crm-btn--primary')).toBe(true);
    expect(document.getElementById('crm-live-region').textContent).toBe('Board');
  });

  it('rejects unknown views and missing sections', () => {
    document.body.innerHTML = markup();
    const Core = loadCore();
    expect(Core.applyPipelineViewDom(document, 'evil')).toBeNull();
    expect(document.getElementById('crm-pipeline-table').hasAttribute('hidden')).toBe(false);
    document.body.innerHTML = '';
    expect(Core.applyPipelineViewDom(document, 'board')).toBeNull();
  });

  it('builds view href without open redirect', () => {
    const Core = loadCore();
    expect(Core.viewHref('https://cloud.example/apps/customercheck/pipeline?period=month', 'board'))
      .toBe('/apps/customercheck/pipeline?period=month&view=board');
    expect(Core.viewHref('https://cloud.example/apps/customercheck/pipeline?view=board', 'table'))
      .toBe('/apps/customercheck/pipeline');
    expect(Core.viewHref('not a url', 'board')).toBe('');
  });

  it('rewrites period chip hrefs for the active view', () => {
    document.body.innerHTML = markup();
    const Core = loadCore();
    Core.syncPeriodChipHrefs(document, 'board', 'https://cloud.example/apps/customercheck/pipeline');
    const hrefs = [...document.querySelectorAll('.crm-pipeline-toolbar__period a')].map((a) => a.getAttribute('href'));
    expect(hrefs[0]).toContain('view=board');
    expect(hrefs[1]).toContain('view=board');
    expect(hrefs[1]).toContain('period=month');
  });

  it('persists then swaps without reload; last click wins', async () => {
    document.body.innerHTML = markup();
    const Core = loadCore();
    const order = [];
    let releaseBoard;
    const boardPut = new Promise((resolve) => {
      releaseBoard = resolve;
    });
    const api = Core.bind({
      document,
      put: (view) => {
        order.push(view);
        if (view === 'board') {
          return boardPut;
        }
        return Promise.resolve();
      },
      locationHref: 'https://cloud.example/apps/customercheck/pipeline',
      replaceState: () => {},
    });
    const first = api.selectView('board');
    const second = api.selectView('table');
    releaseBoard();
    await Promise.all([first, second]);
    expect(order).toEqual(['board', 'table']);
    expect(api.currentView()).toBe('table');
    expect(document.getElementById('crm-pipeline-table').hasAttribute('hidden')).toBe(false);
    expect(document.getElementById('crm-pipeline-board').hasAttribute('hidden')).toBe(true);
  });

  it('leaves the current view in place when persist fails', async () => {
    document.body.innerHTML = markup();
    const Core = loadCore();
    let saw = false;
    const api = Core.bind({
      document,
      put: () => Promise.reject(new Error('nope')),
      onError: () => {
        saw = true;
      },
    });
    await api.selectView('board');
    expect(saw).toBe(true);
    expect(api.currentView()).toBe('table');
    expect(document.getElementById('crm-pipeline-board').hasAttribute('hidden')).toBe(true);
  });
});
