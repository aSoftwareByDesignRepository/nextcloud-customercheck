/**
 * Pure helpers for Company 360 command-center surfaces (vitest + browser bootstrap).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * Allow only same-origin absolute paths (no protocol-relative, no javascript:).
 * @param {unknown} href
 * @param {string} [fallback='/']
 * @returns {string}
 */
export function safeInternalHref(href, fallback = '/') {
  const s = String(href == null ? '' : href).trim();
  if (!s) {
    return fallback;
  }
  const lower = s.toLowerCase();
  if (lower.startsWith('javascript:') || lower.startsWith('data:') || lower.startsWith('vbscript:')) {
    return fallback;
  }
  // Absolute path only; reject protocol-relative (//evil) and backslash tricks.
  if (s.charAt(0) !== '/' || s.startsWith('//') || s.includes('\\')) {
    return fallback;
  }
  return s;
}

/**
 * @param {{ state?: string, overdueCount?: number, openCount?: number }|null|undefined} billing
 * @param {{
 *   t: (msg: string, args?: string[]) => string,
 *   billingStateLabel: (state: string) => string,
 *   billingTone: (state: string) => 'success'|'warning'|'danger'|'neutral',
 * }} helpers
 * @returns {Array<{ key: string, label: string, value: string, meta: string, tone: string }>}
 */
export function buildBillingSummaryTiles(billing, helpers) {
  const t = helpers.t;
  const b = billing && typeof billing === 'object' ? billing : {};
  const state = b.state ? String(b.state) : 'unknown';
  const overdue = Math.max(0, Number(b.overdueCount || 0) || 0);
  const open = Math.max(0, Number(b.openCount || 0) || 0);
  const tone = helpers.billingTone(state);
  return [
    {
      key: 'status',
      label: t('Status'),
      value: helpers.billingStateLabel(state),
      meta: '',
      tone,
    },
    {
      key: 'overdue',
      label: t('Overdue'),
      value: String(overdue),
      meta: overdue > 0 ? t('Needs collection') : t('None overdue'),
      tone: overdue > 0 ? 'danger' : 'success',
    },
    {
      key: 'open',
      label: t('Open invoices'),
      value: String(open),
      meta: open > 0 ? t('Still unpaid') : t('Nothing open'),
      tone: open > 0 ? 'warning' : 'neutral',
    },
  ];
}
