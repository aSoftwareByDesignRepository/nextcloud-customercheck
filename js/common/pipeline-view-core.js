/**
 * Pipeline table/board switch — persist then swap in place (no full reload).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  /**
   * @param {unknown} raw
   * @returns {'table'|'board'|''}
   */
  function normalizePipelineView(raw) {
    const v = String(raw || '');
    if (v === 'board' || v === 'table') {
      return v;
    }
    return '';
  }

  /**
   * @param {{ getElementById?: Function }|null|undefined} root
   * @returns {'table'|'board'}
   */
  function currentPipelineView(root) {
    if (!root || typeof root.getElementById !== 'function') {
      return 'table';
    }
    const board = root.getElementById('crm-pipeline-board');
    if (board && !board.hasAttribute('hidden')) {
      return 'board';
    }
    return 'table';
  }

  /**
   * @param {{ getElementById?: Function, querySelectorAll?: Function, getElementById?: Function }|null|undefined} root
   * @param {unknown} view
   * @returns {'table'|'board'|null}
   */
  function applyPipelineViewDom(root, view) {
    const v = normalizePipelineView(view);
    if (!v || !root || typeof root.getElementById !== 'function') {
      return null;
    }
    const table = root.getElementById('crm-pipeline-table');
    const board = root.getElementById('crm-pipeline-board');
    if (!table || !board) {
      return null;
    }
    const isBoard = v === 'board';
    if (isBoard) {
      table.setAttribute('hidden', '');
      board.removeAttribute('hidden');
    } else {
      board.setAttribute('hidden', '');
      table.removeAttribute('hidden');
    }
    if (typeof root.querySelectorAll === 'function') {
      const buttons = root.querySelectorAll('[data-crm-pipeline-view]');
      for (let i = 0; i < buttons.length; i++) {
        const btn = buttons[i];
        const on = btn.getAttribute('data-crm-pipeline-view') === v;
        btn.classList.toggle('crm-btn--primary', on);
        btn.classList.toggle('crm-btn--secondary', !on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      }
    }
    const live = typeof root.getElementById === 'function' ? root.getElementById('crm-live-region') : null;
    if (live) {
      const selected = typeof root.querySelector === 'function'
        ? root.querySelector('[data-crm-pipeline-view="' + v + '"]')
        : null;
      const label = selected && selected.textContent ? String(selected.textContent).trim() : v;
      live.textContent = label;
    }
    return v;
  }

  /**
   * @param {unknown} locHref
   * @param {unknown} view
   * @returns {string}
   */
  function viewHref(locHref, view) {
    let url;
    try {
      url = new URL(String(locHref || ''));
    } catch (e) {
      return '';
    }
    const v = normalizePipelineView(view);
    if (v === 'board') {
      url.searchParams.set('view', 'board');
    } else {
      url.searchParams.delete('view');
    }
    url.hash = '';
    return url.pathname + (url.searchParams.toString() !== '' ? '?' + url.searchParams.toString() : '');
  }

  /**
   * @param {{ querySelectorAll?: Function }|null|undefined} root
   * @param {unknown} view
   * @param {unknown} baseHref
   */
  function syncPeriodChipHrefs(root, view, baseHref) {
    if (!root || typeof root.querySelectorAll !== 'function') {
      return;
    }
    const links = root.querySelectorAll('.crm-pipeline-toolbar__period a[href]');
    for (let i = 0; i < links.length; i++) {
      const a = links[i];
      const href = a.getAttribute('href') || '';
      try {
        const u = new URL(href, String(baseHref || ''));
        const v = normalizePipelineView(view);
        if (v === 'board') {
          u.searchParams.set('view', 'board');
        } else {
          u.searchParams.delete('view');
        }
        a.setAttribute('href', u.pathname + (u.searchParams.toString() !== '' ? '?' + u.searchParams.toString() : ''));
      } catch (e) {
        // leave the original href
      }
    }
  }

  /**
   * @param {{ querySelector?: Function }|null|undefined} root
   * @param {boolean} busy
   */
  function setViewBusy(root, busy) {
    if (!root || typeof root.querySelector !== 'function') {
      return;
    }
    const group = root.querySelector('.crm-pipeline-toolbar__views');
    if (!group) {
      return;
    }
    if (busy) {
      group.setAttribute('aria-busy', 'true');
    } else {
      group.removeAttribute('aria-busy');
    }
  }

  /**
   * @param {{
   *   document?: Document,
   *   put?: (view: 'table'|'board') => Promise<unknown>,
   *   onError?: (err: unknown) => unknown,
   *   locationHref?: string,
   *   replaceState?: (href: string) => void
   * }} [options]
   */
  function bindPipelineView(options) {
    const opts = options && typeof options === 'object' ? options : {};
    const root = opts.document || (typeof document !== 'undefined' ? document : null);
    if (!root || typeof root.querySelectorAll !== 'function') {
      return null;
    }
    const put = typeof opts.put === 'function' ? opts.put : null;
    const onError = typeof opts.onError === 'function' ? opts.onError : null;
    /** @type {Promise<void>|null} */
    let running = null;
    /** @type {'table'|'board'|null} */
    let pending = null;

    function drain() {
      if (running) {
        return running;
      }
      running = (async function () {
        setViewBusy(root, true);
        try {
          while (pending) {
            const v = pending;
            pending = null;
            if (put) {
              await put(v);
            }
            if (pending) {
              continue;
            }
            applyPipelineViewDom(root, v);
            const base = typeof opts.locationHref === 'string'
              ? opts.locationHref
              : (typeof window !== 'undefined' ? window.location.href : '');
            syncPeriodChipHrefs(root, v, base);
            const href = viewHref(base, v);
            if (href && typeof opts.replaceState === 'function') {
              opts.replaceState(href);
            } else if (href && typeof window !== 'undefined' && window.history && typeof window.history.replaceState === 'function') {
              window.history.replaceState(window.history.state, '', href);
            }
          }
        } catch (e) {
          pending = null;
          if (onError) {
            await onError(e);
          }
        } finally {
          setViewBusy(root, false);
          running = null;
        }
      })();
      return running;
    }

    /**
     * @param {unknown} view
     * @returns {Promise<void>}
     */
    function selectView(view) {
      const next = normalizePipelineView(view);
      if (!next) {
        return Promise.resolve();
      }
      if (!running && next === currentPipelineView(root)) {
        return Promise.resolve();
      }
      pending = next;
      return drain();
    }

    const buttons = root.querySelectorAll('[data-crm-pipeline-view]');
    for (let i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function () {
        selectView(buttons[i].getAttribute('data-crm-pipeline-view'));
      });
    }

    return { selectView: selectView, currentView: function () { return currentPipelineView(root); } };
  }

  const api = {
    normalizePipelineView: normalizePipelineView,
    currentPipelineView: currentPipelineView,
    applyPipelineViewDom: applyPipelineViewDom,
    viewHref: viewHref,
    syncPeriodChipHrefs: syncPeriodChipHrefs,
    setViewBusy: setViewBusy,
    bind: bindPipelineView,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckPipelineView = api;
})(typeof window !== 'undefined' ? window : globalThis);
