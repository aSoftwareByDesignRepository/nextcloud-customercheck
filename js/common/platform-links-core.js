/**
 * Platform-hub chip helpers (same-origin /apps/ hrefs only).
 * No DOM fetch — vitest + mutation friendly. Classic script for Util::addScript.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  /**
   * Only http(s) URLs on this Nextcloud whose path is a sibling app home.
   * Rejects javascript:, data:, protocol-relative tricks, and off-origin.
   *
   * @param {unknown} raw
   * @param {string} origin
   * @returns {string}
   */
  function safeHref(raw, origin) {
    if (raw == null) {
      return '';
    }
    const base = String(origin || '').trim();
    if (!base) {
      return '';
    }
    let parsed;
    try {
      parsed = new URL(String(raw), base);
    } catch (e) {
      return '';
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      return '';
    }
    let expectedOrigin;
    try {
      expectedOrigin = new URL(base).origin;
    } catch (e) {
      return '';
    }
    if (parsed.origin !== expectedOrigin) {
      return '';
    }
    const path = parsed.pathname || '';
    if (path.indexOf('/apps/') === -1) {
      return '';
    }
    if (path.indexOf('//') !== -1 || path.indexOf('\\') !== -1 || path.indexOf('..') !== -1) {
      return '';
    }
    return parsed.href;
  }

  /**
   * @param {unknown} links
   * @param {string} origin
   * @returns {Array<{id:string,href:string,label:string}>}
   */
  function enabledChips(links, origin) {
    const out = [];
    if (!links || typeof links !== 'object' || Array.isArray(links)) {
      return out;
    }
    Object.keys(links).forEach(function (key) {
      const info = links[key];
      if (!info || typeof info !== 'object' || Array.isArray(info)) {
        return;
      }
      if (info.available === false) {
        return;
      }
      const href = safeHref(info.url, origin);
      if (!href) {
        return;
      }
      const label = String(info.label || key).trim();
      if (!label) {
        return;
      }
      out.push({ id: String(key), href: href, label: label });
    });
    return out;
  }

  const api = {
    safeHref: safeHref,
    enabledChips: enabledChips,
  };

  global.CustomerCheckPlatformLinks = api;
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
