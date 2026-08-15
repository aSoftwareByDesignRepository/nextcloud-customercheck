/**
 * Browser bootstrap for dialog-present helpers.
 * Keep aligned with js/utils/dialog-present.js (DialogUxContractTest).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function safeExternalHref(href, fallback) {
    const fb = fallback == null ? '#' : String(fallback);
    const raw = String(href == null ? '' : href).trim();
    if (!raw) {
      return fb;
    }
    const lower = raw.toLowerCase();
    if (lower.indexOf('javascript:') === 0 || lower.indexOf('data:') === 0 || lower.indexOf('vbscript:') === 0) {
      return fb;
    }
    if (/^https?:\/\//i.test(raw) || /^mailto:/i.test(raw) || raw.charAt(0) === '/') {
      return raw;
    }
    return fb;
  }

  function buildMailPackDialogHtml(pack, opts) {
    const t = opts && typeof opts.t === 'function' ? opts.t : function (m) { return m; };
    const subject = escapeHtml((pack && pack.subject) || '');
    const body = escapeHtml((pack && pack.bodyText) || '');
    const href = escapeHtml(safeExternalHref((pack && (pack.mailAppUrl || pack.mailtoUrl)) || '#'));
    const openLabel = escapeHtml((opts && opts.openMailLabel) || t('Open mail'));
    const callout = (opts && opts.calloutHtml) ? String(opts.calloutHtml) : '';
    const extra = (opts && opts.extraActionsHtml) ? String(opts.extraActionsHtml) : '';
    return callout
      + '<p><strong>' + subject + '</strong></p>'
      + '<pre class="crm-pre">' + body + '</pre>'
      + '<div class="crm-form-actions">'
      + '<a class="crm-btn crm-btn--primary" href="' + href + '" rel="noopener noreferrer">' + openLabel + '</a> '
      + extra
      + '</div>';
  }

  global.CustomerCheckDialogPresent = {
    escapeHtml: escapeHtml,
    safeExternalHref: safeExternalHref,
    buildMailPackDialogHtml: buildMailPackDialogHtml,
  };
}(typeof window !== 'undefined' ? window : globalThis));
