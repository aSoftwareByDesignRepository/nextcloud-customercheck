/**
 * Pure Sales Inbox paging helpers (cursor pages + merge).
 * No DOM — vitest + mutation friendly.
 */
(function (global) {
  'use strict';

  /**
   * window.t / OC.L10N.translate fills {name} tokens only — %s/%d pass
   * through raw. Wrap an injected translator so leftover sprintf
   * placeholders are always filled (idempotent when already filled).
   * @param {unknown} base
   * @returns {(msg: string, args?: string[]) => string}
   */
  function sprintfT(base) {
    const baseT = typeof base === 'function' ? base : function (m) { return m; };
    return function (msg, args) {
      let out = baseT(msg, args);
      const list = Array.isArray(args) ? args : [];
      if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
        let i = 0;
        out = out.replace(/%%|%(?:\d+\$)?[sd]/g, function (m) {
          if (m === '%%') { return '%'; }
          const v = list[i];
          i += 1;
          return v == null ? '' : String(v);
        });
      }
      return out;
    };
  }

  /**
   * @param {unknown} existing
   * @param {unknown} incoming
   * @returns {Array<Record<string, mixed>>}
   */
  function mergeInboxItems(existing, incoming) {
    const seen = Object.create(null);
    const out = [];
    const push = function (row) {
      if (!row || typeof row !== 'object') return;
      const id = parseInt(String(row.id || 0), 10);
      if (!(id > 0) || seen[id]) return;
      seen[id] = true;
      out.push(row);
    };
    (Array.isArray(existing) ? existing : []).forEach(push);
    (Array.isArray(incoming) ? incoming : []).forEach(push);
    return out;
  }

  /**
   * @param {unknown} nextCursor
   * @param {unknown} hasMore
   * @returns {{ nextCursor: string|null, hasMore: boolean }}
   */
  function normalizePagingMeta(nextCursor, hasMore) {
    const cursor = typeof nextCursor === 'string' && nextCursor !== '' ? nextCursor : null;
    return {
      nextCursor: cursor,
      hasMore: !!hasMore && !!cursor,
    };
  }

  /**
   * @param {{ tab?: string, hasMore?: boolean, loadedCount?: number, visibleCount?: number, moreBusy?: boolean }} opts
   */
  function shouldShowLoadMore(opts) {
    const o = opts && typeof opts === 'object' ? opts : {};
    const tab = String(o.tab || '');
    if (tab === 'unmatched') return false;
    if (!o.hasMore) return false;
    const loaded = parseInt(String(o.loadedCount || 0), 10) || 0;
    if (loaded <= 0) return false;
    return true;
  }

  /**
   * @param {{ count?: number, hasMore?: boolean, tab?: string, filteredEmpty?: boolean, t?: Function }} opts
   */
  function inboxStatusText(opts) {
    const o = opts && typeof opts === 'object' ? opts : {};
    const t = sprintfT(o.t);
    const n = parseInt(String(o.count || 0), 10) || 0;
    if (n === 0) {
      if (o.filteredEmpty && o.hasMore && String(o.tab || '') !== 'unmatched') {
        return t('No matches in loaded messages — load older to search further');
      }
      return t('No messages');
    }
    const base = n === 1 ? t('1 message') : t('%s messages', [String(n)]);
    if (String(o.tab || '') !== 'unmatched' && o.hasMore) {
      return base + ' — ' + t('more available');
    }
    return base;
  }

  /**
   * Honest panel copy for a FAILED load: the surface must admit the load
   * failed (error chrome), never claim "no messages here" (dishonest empty).
   * @param {{ errorText?: unknown, t?: Function }} opts
   */
  function failedEmptyCopy(opts) {
    const o = opts && typeof opts === 'object' ? opts : {};
    const t = sprintfT(o.t);
    const err = String(o.errorText || '').trim();
    return {
      title: t('Could not load inbox.'),
      text: err !== '' ? err : t('Could not reach the server. Check your connection and try again.'),
      cta: null,
    };
  }

  /**
   * @param {string|null|undefined} cursor
   * @returns {boolean}
   */
  function looksLikeInboxCursor(cursor) {
    const c = String(cursor || '').trim();
    if (c === '' || c.indexOf('|') < 0 || c.length > 96) return false;
    const parts = c.split('|');
    if (parts.length !== 2) return false;
    const id = parts[1];
    if (!/^\d+$/.test(id) || parseInt(id, 10) <= 0) return false;
    const at = parts[0];
    if (at === '') return true;
    return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(at);
  }

  const api = {
    mergeInboxItems: mergeInboxItems,
    normalizePagingMeta: normalizePagingMeta,
    shouldShowLoadMore: shouldShowLoadMore,
    inboxStatusText: inboxStatusText,
    failedEmptyCopy: failedEmptyCopy,
    looksLikeInboxCursor: looksLikeInboxCursor,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckInboxPage = api;
})(typeof window !== 'undefined' ? window : globalThis);
