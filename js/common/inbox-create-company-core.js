/**
 * Pure helpers for inline company create + assign from Sales Inbox.
 * No DOM — vitest + mutation friendly.
 */
(function (global) {
  'use strict';

  var MAX_NAME_LEN = 255;

  /**
   * @param {unknown} raw
   * @returns {{ ok: boolean, name: string, error: ''|'empty'|'too_long' }}
   */
  function validateInlineCompanyName(raw) {
    var name = String(raw || '').trim();
    if (!name) {
      return { ok: false, name: '', error: 'empty' };
    }
    if (name.length > MAX_NAME_LEN) {
      return { ok: false, name: name, error: 'too_long' };
    }
    return { ok: true, name: name, error: '' };
  }

  /**
   * Minimal POST /companies body — server fills slug, status, country, owner.
   * @param {string} name
   * @returns {{ name: string }}
   */
  function buildMinimalCompanyCreateBody(name) {
    return { name: String(name || '').trim() };
  }

  /**
   * @param {unknown} res
   * @returns {number}
   */
  function extractCompanyIdFromCreateResponse(res) {
    var payload = res && typeof res === 'object' ? res : {};
    if (payload.data !== undefined && payload.data !== null && typeof payload.data === 'object') {
      payload = payload.data;
    }
    var id = Math.max(0, parseInt(String(payload.id || 0), 10) || 0);
    if (id > 0) {
      return id;
    }
    if (payload.company && typeof payload.company === 'object') {
      id = Math.max(0, parseInt(String(payload.company.id || 0), 10) || 0);
      if (id > 0) {
        return id;
      }
    }
    return 0;
  }

  var api = {
    MAX_NAME_LEN: MAX_NAME_LEN,
    validateInlineCompanyName: validateInlineCompanyName,
    buildMinimalCompanyCreateBody: buildMinimalCompanyCreateBody,
    extractCompanyIdFromCreateResponse: extractCompanyIdFromCreateResponse,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckInboxCreateCompanyCore = api;
}(typeof window !== 'undefined' ? window : globalThis));
