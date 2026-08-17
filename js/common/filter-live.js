/**
 * Live filter forms — search updates the list in place (never a full reload).
 * Selects still apply on change. Apply/Clear remain for no-JS and keyboard users.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function () {
  'use strict';

  const Core = window.CustomerCheckFilterLiveCore;
  if (!Core) {
    return;
  }

  /**
   * @param {string} key
   * @returns {string}
   */
  function t(key) {
    if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.translate === 'function') {
      return OC.L10N.translate('customercheck', key);
    }
    if (typeof window.t === 'function') {
      return window.t('customercheck', key);
    }
    return key;
  }

  /**
   * @returns {HTMLElement|null}
   */
  function liveRegion() {
    return document.getElementById('crm-live-region');
  }

  /**
   * @param {string} msg
   */
  function announce(msg) {
    const el = liveRegion();
    if (el) {
      el.textContent = msg;
    }
  }

  /**
   * @param {ParentNode|Document} root
   * @returns {HTMLFormElement[]}
   */
  function liveForms(root) {
    const list = [];
    const nodes = root.querySelectorAll('form[data-crm-live-filter]');
    for (let i = 0; i < nodes.length; i++) {
      if (nodes[i] instanceof HTMLFormElement) {
        list.push(nodes[i]);
      }
    }
    return list;
  }

  /**
   * @param {HTMLFormElement} form
   * @returns {Array<[string, string]>}
   */
  function formEntries(form) {
    const out = [];
    if (typeof FormData !== 'function') {
      return out;
    }
    const fd = new FormData(form);
    fd.forEach(function (v, k) {
      if (typeof v === 'string') {
        out.push([k, v]);
      }
    });
    return out;
  }

  /**
   * @param {HTMLFormElement} form
   * @returns {URL|null}
   */
  function urlFromForms(form) {
    const lists = liveForms(document).map(formEntries);
    const overlay = [];
    document.querySelectorAll('form[data-crm-live-filter] input[type="search"]').forEach(function (el) {
      if (el instanceof HTMLInputElement && el.name) {
        overlay.push([el.name, el.value]);
      }
    });
    if (overlay.length) {
      lists.push(overlay);
    }
    return Core.buildFilterUrl(lists, form.getAttribute('action') || window.location.pathname, window.location.href);
  }

  let searchTimer = null;
  let generation = 0;
  /** @type {AbortController|null} */
  let inflight = null;
  let lastAppliedHref = window.location.pathname + window.location.search;
  let composing = false;

  /**
   * @param {ParentNode} root
   */
  function swapFromDocument(root) {
    const incoming = root.querySelectorAll('[data-crm-filter-swap]');
    for (let i = 0; i < incoming.length; i++) {
      const src = incoming[i];
      const name = src.getAttribute('data-crm-filter-swap');
      if (!name) {
        continue;
      }
      const dest = document.querySelector('[data-crm-filter-swap="' + name + '"]');
      if (!dest) {
        continue;
      }
      dest.replaceWith(src.cloneNode(true));
    }
  }

  /**
   * @param {URL} url
   */
  function syncClearAndHiddens(url) {
    const clear = document.getElementById('crm-companies-search-clear');
    if (clear instanceof HTMLAnchorElement) {
      const href = Core.clearListHref(url);
      if (href) {
        clear.href = href;
      }
      clear.hidden = !Core.hasActiveListFilters(url.searchParams);
    }
    const q = url.searchParams.get('q') || '';
    document.querySelectorAll('form[data-crm-live-filter] input[name="q"][type="hidden"]').forEach(function (el) {
      if (el instanceof HTMLInputElement) {
        el.value = q;
      }
    });
  }

  /**
   * @param {ParentNode} root
   * @returns {number}
   */
  function countLiveRows(root) {
    const main = root.querySelector('[data-crm-filter-swap="main"]') || root;
    const rows = [];
    const trs = main.querySelectorAll('tbody tr');
    for (let i = 0; i < trs.length; i++) {
      rows.push({ empty: !!trs[i].querySelector('.crm-table-empty') });
    }
    return Core.countResultRows(rows);
  }

  /**
   * @returns {{ emptyLabel: string, resultsLabel: string, failedLabel: string }}
   */
  function statusCopy() {
    const main = document.querySelector('[data-crm-filter-swap="main"]');
    if (!(main instanceof HTMLElement)) {
      return { emptyLabel: '', resultsLabel: '', failedLabel: '' };
    }
    return {
      emptyLabel: main.getAttribute('data-crm-filter-empty') || '',
      resultsLabel: main.getAttribute('data-crm-filter-done') || '',
      failedLabel: main.getAttribute('data-crm-filter-failed') || '',
    };
  }

  /**
   * @param {HTMLFormElement} form
   * @param {string} name
   * @param {string} value
   */
  function ensureHiddenParam(form, name, value) {
    let el = form.querySelector('input[type="hidden"][name="' + name + '"]');
    if (value === '') {
      if (el && el.parentNode) {
        el.parentNode.removeChild(el);
      }
      return;
    }
    if (!(el instanceof HTMLInputElement)) {
      el = document.createElement('input');
      el.type = 'hidden';
      el.name = name;
      form.insertBefore(el, form.firstChild);
    }
    el.value = value;
  }

  /**
   * Filter forms stay outside the swapped HTML. After a chip click the URL is
   * source of truth — selects and companies search hiddens must follow it.
   *
   * @param {URL} url
   */
  function syncFormsFromUrl(url) {
    const params = url.searchParams;
    liveForms(document).forEach(function (form) {
      form.querySelectorAll('select[name]').forEach(function (sel) {
        if (!(sel instanceof HTMLSelectElement) || !sel.name) {
          return;
        }
        const optionValues = [];
        for (let i = 0; i < sel.options.length; i++) {
          optionValues.push(sel.options[i].value);
        }
        sel.value = Core.selectValueFromParams(sel.name, optionValues, params);
      });
      form.querySelectorAll('input[name="q"]').forEach(function (el) {
        if (el instanceof HTMLInputElement) {
          el.value = params.get('q') || '';
        }
      });
    });
    const searchForm = document.querySelector('form.crm-companies-search');
    if (searchForm instanceof HTMLFormElement) {
      ensureHiddenParam(searchForm, 'status', Core.hiddenFilterValue('status', params));
      ensureHiddenParam(searchForm, 'archived', Core.hiddenFilterValue('archived', params));
    }
    syncClearAndHiddens(url);
  }

  /**
   * @param {URL} url
   * @param {{ immediate?: boolean, force?: boolean }} [opts]
   * @returns {Promise<boolean>|undefined}
   */
  function applyUrl(url, opts) {
    const immediate = !!(opts && opts.immediate);
    const force = !!(opts && opts.force);
    if (searchTimer) {
      clearTimeout(searchTimer);
      searchTimer = null;
    }
    const run = function () {
      if (!url) {
        return Promise.resolve(false);
      }
      if (!Core.canSwapInPlace(document) || !Core.isSafeFilterUrl(url, window.location)) {
        return Promise.resolve(false);
      }
      const href = url.pathname + url.search;
      if (!force && (Core.urlsEqual(href, lastAppliedHref) || Core.urlsEqual(url.href, window.location.href))) {
        return Promise.resolve(true);
      }
      const my = ++generation;
      if (inflight) {
        inflight.abort();
      }
      inflight = typeof AbortController === 'function' ? new AbortController() : null;
      const main = document.querySelector('[data-crm-filter-swap="main"]');
      if (main) {
        main.setAttribute('aria-busy', 'true');
      }
      announce(Core.statusMessage(Object.assign({ busy: true, t: t }, statusCopy())));
      const focusId = document.activeElement && document.activeElement.id ? document.activeElement.id : '';
      const headers = Core.fragmentRequestHeaders();
      const init = { method: 'GET', credentials: 'same-origin', headers: headers };
      if (inflight) {
        init.signal = inflight.signal;
      }
      return fetch(url.href, init).then(function (res) {
        if (my !== generation) {
          return null;
        }
        if (!res.ok) {
          throw new Error('http');
        }
        return res.text();
      }).then(function (html) {
        if (html === null || my !== generation) {
          return false;
        }
        if (typeof html !== 'string' || html === '') {
          throw new Error('empty');
        }
        const names = Core.extractSwapNames(html);
        if (names.indexOf('main') === -1) {
          throw new Error('no-main');
        }
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        swapFromDocument(parsed);
        lastAppliedHref = href;
        if (window.history && typeof window.history.replaceState === 'function') {
          window.history.replaceState(window.history.state, '', url.href);
        }
        syncFormsFromUrl(url);
        announce(Core.statusMessage(Object.assign({ count: countLiveRows(document), t: t }, statusCopy())));
        if (focusId) {
          const el = document.getElementById(focusId);
          if (el && typeof el.focus === 'function' && document.activeElement !== el) {
            try {
              el.focus({ preventScroll: true });
            } catch (e) {
              el.focus();
            }
          }
        }
        return true;
      }).catch(function (err) {
        if (err && err.name === 'AbortError') {
          return false;
        }
        if (my !== generation) {
          return false;
        }
        announce(Core.statusMessage(Object.assign({ failed: true, t: t }, statusCopy())));
        if (immediate && !force) {
          window.location.assign(url.href);
        }
        return false;
      }).then(function (ok) {
        if (my === generation) {
          const liveMain = document.querySelector('[data-crm-filter-swap="main"]');
          if (liveMain) {
            liveMain.removeAttribute('aria-busy');
          }
        }
        return !!ok;
      });
    };
    if (immediate) {
      return run();
    }
    searchTimer = setTimeout(run, Core.SEARCH_DEBOUNCE_MS);
  }

  /**
   * Re-fetch the current list after a write. URL is often unchanged, so force
   * must skip the urlsEqual early-out. Falls back to a full reload only when
   * this page has no swap island.
   *
   * @returns {Promise<boolean>}
   */
  function refresh() {
    if (!Core.canSwapInPlace(document)) {
      window.location.reload();
      return Promise.resolve(false);
    }
    let url;
    try {
      url = new URL(window.location.href);
    } catch (e) {
      window.location.reload();
      return Promise.resolve(false);
    }
    url.hash = '';
    if (!Core.isSafeFilterUrl(url, window.location)) {
      window.location.reload();
      return Promise.resolve(false);
    }
    return applyUrl(url, { immediate: true, force: true }) || Promise.resolve(false);
  }

  /**
   * @param {HTMLFormElement} form
   * @param {{ immediate?: boolean }} [opts]
   */
  function applyFilter(form, opts) {
    const url = urlFromForms(form);
    if (!url) {
      return;
    }
    applyUrl(url, opts);
  }

  /**
   * Native GET only when we cannot swap in place (no-JS already works without us).
   * Search-as-you-type must never requestSubmit — that reloads the whole page.
   *
   * @param {HTMLFormElement} form
   */
  function wireLiveFilter(form) {
    if (!(form instanceof HTMLFormElement) || form.dataset.crmLiveFilterWired === '1') {
      return;
    }
    form.dataset.crmLiveFilterWired = '1';

    form.addEventListener('submit', function (ev) {
      if (!Core.canSwapInPlace(document)) {
        return;
      }
      const url = urlFromForms(form);
      if (!url || !Core.isSafeFilterUrl(url, window.location)) {
        return;
      }
      ev.preventDefault();
      applyFilter(form, { immediate: true });
    });

    form.querySelectorAll('select').forEach(function (select) {
      select.addEventListener('change', function () {
        if (Core.canSwapInPlace(document) && Core.isSafeFilterUrl(urlFromForms(form), window.location)) {
          applyFilter(form, { immediate: true });
          return;
        }
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    });

    form.querySelectorAll('input[type="search"], input[name="q"]').forEach(function (input) {
      if (!(input instanceof HTMLInputElement) || input.type === 'hidden') {
        return;
      }
      input.addEventListener('compositionstart', function () {
        composing = true;
      });
      input.addEventListener('compositionend', function () {
        composing = false;
        applyFilter(form, { immediate: false });
      });
      input.addEventListener('input', function (ev) {
        if (composing || Core.isComposingEvent(ev)) {
          return;
        }
        applyFilter(form, { immediate: false });
      });
      input.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') {
          return;
        }
        ev.preventDefault();
        applyFilter(form, { immediate: true });
      });
    });
  }

  function init() {
    liveForms(document).forEach(wireLiveFilter);
    if (document.documentElement.dataset.crmFilterLiveDoc === '1') {
      return;
    }
    document.documentElement.dataset.crmFilterLiveDoc = '1';
    document.addEventListener('click', function (ev) {
      const target = ev.target;
      if (!target || typeof target.closest !== 'function') {
        return;
      }
      if (Core.isModifiedClick(ev)) {
        return;
      }
      const a = target.closest('a.crm-quick-filter, a#crm-companies-search-clear, a#btn-clear-filter');
      if (!(a instanceof HTMLAnchorElement) || !Core.isLiveFilterNavAnchor(a)) {
        return;
      }
      if (!Core.canSwapInPlace(document)) {
        return;
      }
      let url;
      try {
        url = new URL(a.href, window.location.href);
      } catch (e) {
        return;
      }
      if (!Core.isSafeFilterUrl(url, window.location)) {
        return;
      }
      ev.preventDefault();
      syncFormsFromUrl(url);
      applyUrl(url, { immediate: true });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.CustomerCheckFilterLive = { init: init, wireLiveFilter: wireLiveFilter, refresh: refresh };
})();
