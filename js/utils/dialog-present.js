/**
 * Pure dialog/mail-pack helpers (vitest + components bootstrap).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * Escape text for HTML text/attribute contexts.
 * @param {unknown} str
 * @returns {string}
 */
export function escapeHtml(str) {
  return String(str == null ? '' : str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Allow only http(s) and mailto URLs for dialog action links (blocks javascript:).
 * @param {unknown} href
 * @param {string} [fallback='#']
 * @returns {string}
 */
export function safeExternalHref(href, fallback = '#') {
  const raw = String(href == null ? '' : href).trim();
  if (!raw) {
    return fallback;
  }
  const lower = raw.toLowerCase();
  if (lower.startsWith('javascript:') || lower.startsWith('data:') || lower.startsWith('vbscript:')) {
    return fallback;
  }
  if (/^https?:\/\//i.test(raw) || /^mailto:/i.test(raw) || raw.startsWith('/')) {
    return raw;
  }
  return fallback;
}

/**
 * Build XSS-safe delivery/prepared-mail body HTML for openDialog content.
 *
 * @param {{ subject?: string, bodyText?: string, mailAppUrl?: string, mailtoUrl?: string }} pack
 * @param {{
 *   t: (msg: string, args?: string[]) => string,
 *   openMailLabel?: string,
 *   extraActionsHtml?: string,
 *   calloutHtml?: string,
 * }} opts
 * @returns {string}
 */
export function buildMailPackDialogHtml(pack, opts) {
  const t = opts && typeof opts.t === 'function' ? opts.t : (m) => m;
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
