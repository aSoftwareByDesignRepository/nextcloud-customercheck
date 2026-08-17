/**
 * Pure list-filter helpers (debounce, same-origin URL, swap safety).
 * No DOM fetch — vitest + mutation friendly. Classic script for Util::addScript.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  /** Pause after the last key before fetching. Slow typists must not reload per char. */
  const SEARCH_DEBOUNCE_MS = 450;
  const MAX_FILTER_URL_LENGTH = 2048;

  /**
   * @param {unknown} ev
   * @returns {boolean}
   */
  function isComposingEvent(ev) {
    if (!ev || typeof ev !== 'object') {
      return false;
    }
    if (ev.isComposing === true) {
      return true;
    }
    const keyCode = parseInt(String(ev.keyCode || 0), 10);
    return keyCode === 229;
  }

  /**
   * @param {unknown} locHref
   * @returns {URL|null}
   */
  function parseHref(locHref) {
    try {
      return new URL(String(locHref || ''));
    } catch (e) {
      return null;
    }
  }

  /**
   * Merge GET fields from every live-filter form. Empty values delete the key
   * so "All statuses" does not keep a leftover hidden status.
   *
   * @param {unknown} formEntryLists array of [key, value][] (FormData order)
   * @param {unknown} actionHref
   * @param {unknown} locHref
   * @returns {URL|null}
   */
  function buildFilterUrl(formEntryLists, actionHref, locHref) {
    const loc = parseHref(locHref);
    if (!loc) {
      return null;
    }
    const url = new URL(loc.href);
    const params = new URLSearchParams();
    const lists = Array.isArray(formEntryLists) ? formEntryLists : [];
    for (let i = 0; i < lists.length; i++) {
      const entries = Array.isArray(lists[i]) ? lists[i] : [];
      for (let j = 0; j < entries.length; j++) {
        const pair = entries[j];
        if (!Array.isArray(pair) || pair.length < 2) {
          continue;
        }
        const k = String(pair[0] || '');
        if (k === '') {
          continue;
        }
        const v = pair[1];
        if (typeof v !== 'string') {
          continue;
        }
        if (v === '') {
          params.delete(k);
        } else {
          params.set(k, v);
        }
      }
    }
    url.search = params.toString();
    url.hash = '';
    return url;
  }

  /**
   * In-place fetch may only replace the current list page — never a third-party URL.
   *
   * @param {URL|null|undefined} nextUrl
   * @param {{ origin?: string, pathname?: string, protocol?: string }|URL|null|undefined} loc
   * @returns {boolean}
   */
  function isSafeFilterUrl(nextUrl, loc) {
    if (!nextUrl || typeof nextUrl.href !== 'string') {
      return false;
    }
    if (!loc || typeof loc.origin !== 'string' || typeof loc.pathname !== 'string') {
      return false;
    }
    if (nextUrl.protocol !== 'http:' && nextUrl.protocol !== 'https:') {
      return false;
    }
    if (typeof loc.protocol === 'string' && loc.protocol !== '' && nextUrl.protocol !== loc.protocol) {
      return false;
    }
    if (nextUrl.origin !== loc.origin) {
      return false;
    }
    if (nextUrl.username !== '' || nextUrl.password !== '') {
      return false;
    }
    if (nextUrl.pathname !== loc.pathname) {
      const stripIndex = function (p) {
        return String(p || '').replace(/^\/index\.php/, '') || '/';
      };
      if (stripIndex(nextUrl.pathname) !== stripIndex(loc.pathname)) {
        return false;
      }
    }
    if (nextUrl.href.length > MAX_FILTER_URL_LENGTH) {
      return false;
    }
    return true;
  }

  /**
   * @param {unknown} a
   * @param {unknown} b
   * @returns {boolean}
   */
  function urlsEqual(a, b) {
    const left = typeof a === 'string' ? a : (a && typeof a.href === 'string' ? a.href : '');
    const right = typeof b === 'string' ? b : (b && typeof b.href === 'string' ? b.href : '');
    return left !== '' && left === right;
  }

  /**
   * @param {URLSearchParams|{ get?: Function }|null|undefined} params
   * @returns {boolean}
   */
  function hasActiveListFilters(params) {
    const get = function (k) {
      if (params && typeof params.get === 'function') {
        return String(params.get(k) || '');
      }
      return '';
    };
    if (get('q') !== '') {
      return true;
    }
    if (get('status') !== '') {
      return true;
    }
    if (get('archived') === '1') {
      return true;
    }
    if (get('kind') !== '') {
      return true;
    }
    if (get('type') !== '') {
      return true;
    }
    if (get('state') !== '') {
      return true;
    }
    if (get('expiry') !== '') {
      return true;
    }
    const scope = get('scope');
    if (scope !== '' && scope !== 'open') {
      return true;
    }
    return false;
  }

  /**
   * Clear search/filters but keep the workspace.
   *
   * @param {URL|null|undefined} url
   * @returns {string}
   */
  function clearListHref(url) {
    if (!url || typeof url.origin !== 'string') {
      return '';
    }
    const next = new URL(url.href);
    ['q', 'status', 'archived', 'kind', 'type', 'scope', 'state', 'expiry'].forEach(function (k) {
      next.searchParams.delete(k);
    });
    next.hash = '';
    return next.pathname + (next.searchParams.toString() !== '' ? '?' + next.searchParams.toString() : '');
  }

  /**
   * @param {Array<{ empty?: boolean }|null|undefined>|null|undefined} rows
   * @returns {number}
   */
  function countResultRows(rows) {
    if (!Array.isArray(rows)) {
      return 0;
    }
    let n = 0;
    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      if (!row || row.empty) {
        continue;
      }
      n++;
    }
    return n;
  }

  /**
   * @param {{ busy?: boolean, failed?: boolean, count?: number, t?: Function, emptyLabel?: string, resultsLabel?: string, failedLabel?: string }|null|undefined} opts
   * @returns {string}
   */
  function statusMessage(opts) {
    const o = opts && typeof opts === 'object' ? opts : {};
    const t = typeof o.t === 'function' ? o.t : function (s) { return s; };
    if (o.busy) {
      return t('Updating the list…');
    }
    if (o.failed) {
      const failed = typeof o.failedLabel === 'string' ? o.failedLabel.trim() : '';
      return failed !== '' ? failed : t('Could not update the list. Try again.');
    }
    const n = parseInt(String(o.count || 0), 10) || 0;
    if (n <= 0) {
      const empty = typeof o.emptyLabel === 'string' ? o.emptyLabel.trim() : '';
      return empty !== '' ? empty : t('No matches');
    }
    const done = typeof o.resultsLabel === 'string' ? o.resultsLabel.trim() : '';
    return done !== '' ? done : t('List updated.');
  }

  /**
   * Ctrl/Cmd/Shift/Alt or non-primary button must keep native navigation (new tab).
   *
   * @param {unknown} ev
   * @returns {boolean}
   */
  function isModifiedClick(ev) {
    if (!ev || typeof ev !== 'object') {
      return true;
    }
    if (Object.prototype.hasOwnProperty.call(ev, 'button')) {
      const btn = parseInt(String(ev.button), 10);
      if (btn !== 0) {
        return true;
      }
    }
    return !!(ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey);
  }

  /**
   * Quick-filter chips and Clear links that should fetch in place.
   *
   * @param {unknown} el
   * @returns {boolean}
   */
  function isLiveFilterNavAnchor(el) {
    if (!el || typeof el !== 'object') {
      return false;
    }
    const id = String(el.id || '');
    if (id === 'crm-companies-search-clear' || id === 'btn-clear-filter') {
      return true;
    }
    const cls = typeof el.className === 'string' ? el.className : '';
    return (' ' + cls + ' ').indexOf(' crm-quick-filter ') !== -1;
  }

  /**
   * @param {unknown} name
   * @param {unknown} optionValues
   * @returns {string}
   */
  function defaultSelectValue(name, optionValues) {
    const n = String(name || '');
    const opts = Array.isArray(optionValues) ? optionValues.map(function (v) { return String(v); }) : [];
    const has = function (v) {
      return opts.indexOf(v) !== -1;
    };
    if (n === 'archived' && has('0')) {
      return '0';
    }
    if (n === 'scope' && has('open')) {
      return 'open';
    }
    if (has('')) {
      return '';
    }
    return opts.length ? opts[0] : '';
  }

  /**
   * @param {unknown} name
   * @param {unknown} optionValues
   * @param {{ get?: Function }|null|undefined} params
   * @returns {string}
   */
  function selectValueFromParams(name, optionValues, params) {
    const opts = Array.isArray(optionValues) ? optionValues.map(function (v) { return String(v); }) : [];
    let raw = '';
    if (params && typeof params.get === 'function') {
      const got = params.get(String(name || ''));
      raw = got == null ? '' : String(got);
    }
    if (raw !== '' && opts.indexOf(raw) !== -1) {
      return raw;
    }
    return defaultSelectValue(name, opts);
  }

  /**
   * Hidden status/archived on the companies search form must match the URL
   * or a later keystroke rebuilds the query from stale PHP-rendered hiddens.
   *
   * @param {unknown} name
   * @param {{ get?: Function }|null|undefined} params
   * @returns {string}
   */
  function hiddenFilterValue(name, params) {
    const n = String(name || '');
    let raw = '';
    if (params && typeof params.get === 'function') {
      const got = params.get(n);
      raw = got == null ? '' : String(got);
    }
    if (n === 'archived') {
      return raw === '1' ? '1' : '';
    }
    return raw;
  }

  /**
   * @param {unknown} html
   * @returns {string[]}
   */
  function extractSwapNames(html) {
    const src = String(html || '');
    const names = [];
    const seen = Object.create(null);
    const re = /data-crm-filter-swap=["']([^"']+)["']/g;
    let m = re.exec(src);
    while (m) {
      const name = m[1];
      if (name && !seen[name]) {
        seen[name] = true;
        names.push(name);
      }
      m = re.exec(src);
    }
    return names;
  }

  /**
   * @param {{ querySelector?: Function }|null|undefined} liveRoot
   * @returns {boolean}
   */
  function canSwapInPlace(liveRoot) {
    if (!liveRoot || typeof liveRoot.querySelector !== 'function') {
      return false;
    }
    return !!liveRoot.querySelector('[data-crm-filter-swap="main"]');
  }

  const api = {
    SEARCH_DEBOUNCE_MS: SEARCH_DEBOUNCE_MS,
    MAX_FILTER_URL_LENGTH: MAX_FILTER_URL_LENGTH,
    isComposingEvent: isComposingEvent,
    buildFilterUrl: buildFilterUrl,
    isSafeFilterUrl: isSafeFilterUrl,
    urlsEqual: urlsEqual,
    hasActiveListFilters: hasActiveListFilters,
    clearListHref: clearListHref,
    countResultRows: countResultRows,
    statusMessage: statusMessage,
    isModifiedClick: isModifiedClick,
    isLiveFilterNavAnchor: isLiveFilterNavAnchor,
    defaultSelectValue: defaultSelectValue,
    selectValueFromParams: selectValueFromParams,
    hiddenFilterValue: hiddenFilterValue,
    extractSwapNames: extractSwapNames,
    canSwapInPlace: canSwapInPlace,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckFilterLiveCore = api;
})(typeof window !== 'undefined' ? window : globalThis);
