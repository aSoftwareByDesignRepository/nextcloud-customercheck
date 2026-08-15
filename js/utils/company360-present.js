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
 * Honest one-line source for the Money card (never claim IC/PC when absent).
 * @param {{
 *   sourceMode?: string,
 *   sources?: {
 *     projectCheckAvailable?: boolean,
 *     invoiceCheckAvailable?: boolean,
 *     projectCheckLinked?: boolean,
 *     invoiceCheckLinked?: boolean,
 *     licenses?: boolean,
 *   },
 *   licenses?: { total?: number, unpaid?: number, none?: number },
 * }|null|undefined} billing
 * @param {(msg: string, args?: string[]) => string} t
 * @returns {{ tone: 'info'|'warning'|'neutral', text: string }}
 */
export function buildBillingHonesty(billing, t) {
  const b = billing && typeof billing === 'object' ? billing : {};
  const sources = b.sources && typeof b.sources === 'object' ? b.sources : {};
  const licenses = b.licenses && typeof b.licenses === 'object' ? b.licenses : {};
  const mode = String(b.sourceMode || 'none');
  const icAvail = !!sources.invoiceCheckAvailable;
  const pcAvail = !!sources.projectCheckAvailable;
  const icLinked = !!sources.invoiceCheckLinked;
  const licenseTotal = Math.max(0, Number(licenses.total || 0) || 0);
  const licenseNone = Math.max(0, Number(licenses.none || 0) || 0);
  const licenseUnpaid = Math.max(0, Number(licenses.unpaid || 0) || 0);

  if (mode === 'invoicecheck') {
    return {
      tone: 'neutral',
      text: t('Numbers come from InvoiceCheck for this linked customer.'),
    };
  }
  if (mode === 'licenses_fallback') {
    return {
      tone: 'warning',
      text: t('InvoiceCheck has no open invoices — showing license payment status instead.'),
    };
  }
  if (mode === 'licenses') {
    return {
      tone: 'info',
      text: t('InvoiceCheck is off or not linked — showing license payment status.'),
    };
  }
  if (licenseTotal > 0 && licenseNone === licenseTotal) {
    return {
      tone: 'warning',
      text: t('Licenses are here, but invoice status is still “none”. Open Licenses & renewals to mark sent, paid, or overdue.'),
    };
  }
  if (!icAvail && !pcAvail && licenseTotal === 0) {
    return {
      tone: 'info',
      text: t('No InvoiceCheck / ProjectCheck and no licenses yet. Add a license to track payment here.'),
    };
  }
  if (!icAvail && !icLinked) {
    return {
      tone: 'info',
      text: pcAvail
        ? t('InvoiceCheck is not available. ProjectCheck hours may still show when linked. Add licenses to track payment.')
        : t('InvoiceCheck and ProjectCheck are not available. Track payment on licenses.'),
    };
  }
  if (icAvail && !icLinked) {
    return {
      tone: 'warning',
      text: t('InvoiceCheck is on — link ProjectCheck on this customer to load invoices.'),
    };
  }
  if (licenseUnpaid > 0) {
    return {
      tone: 'warning',
      text: t('%s license invoice(s) still unpaid.', [String(licenseUnpaid)]),
    };
  }
  return {
    tone: 'neutral',
    text: t('Overview from linked apps and licenses — not a tax audit.'),
  };
}

/**
 * @param {{ state?: string, overdueCount?: number, openCount?: number, licenses?: { unpaid?: number } }|null|undefined} billing
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
  const licenseUnpaid = Math.max(0, Number((b.licenses && b.licenses.unpaid) || 0) || 0);
  const tone = helpers.billingTone(state);
  const tiles = [
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
  if (licenseUnpaid > 0 || (b.licenses && Number(b.licenses.total || 0) > 0)) {
    tiles.push({
      key: 'licenses',
      label: t('License invoices'),
      value: String(licenseUnpaid),
      meta: licenseUnpaid > 0 ? t('Unpaid on licenses') : t('Licenses paid or unset'),
      tone: licenseUnpaid > 0 ? 'warning' : 'neutral',
    });
  }
  return tiles;
}
