/**
 * Pure agreement-form helpers (vitest + shared contract with pages/agreement-form.js).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * Local calendar YYYY-MM-DD (timezone-safe — never toISOString).
 * @param {Date} [d]
 * @returns {string}
 */
export function todayIso(d = new Date()) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/**
 * Add whole years to a YYYY-MM-DD (UTC date parts — no TZ drift).
 * @param {string|null|undefined} isoDate
 * @param {number} years
 * @returns {string}
 */
export function addYears(isoDate, years) {
  const y = Number(years);
  if (!Number.isFinite(y)) {
    return todayIso();
  }
  if (!isoDate) {
    const d = new Date();
    d.setFullYear(d.getFullYear() + y);
    return todayIso(d);
  }
  const parts = String(isoDate).split('-').map((n) => parseInt(n, 10));
  if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) {
    const d = new Date();
    d.setFullYear(d.getFullYear() + y);
    return todayIso(d);
  }
  const d = new Date(Date.UTC(parts[0] + y, parts[1] - 1, parts[2]));
  const yy = d.getUTCFullYear();
  const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(d.getUTCDate()).padStart(2, '0');
  return `${yy}-${mm}-${dd}`;
}

/**
 * Build API body for create/update. Never sends limits on edit (preserves partner hoursQuota).
 * Create omits limits so the server applies product defaults (license seats / partner hours).
 *
 * @param {{
 *   mode: 'create'|'edit',
 *   label: string,
 *   kind: string,
 *   productCode: string,
 *   startsAt: string|null,
 *   validUntil: string|null,
 *   amountCents: number,
 *   invoiceStatus: string,
 *   companyId?: number,
 *   updatedAt?: string|null,
 * }} input
 * @returns {Record<string, mixed>}
 */
export function buildAgreementSaveBody(input) {
  const mode = input.mode === 'edit' ? 'edit' : 'create';
  const kind = String(input.kind || 'license');
  let validUntil = input.validUntil || null;
  if (kind === 'onetime') {
    validUntil = null;
  }
  /** @type {Record<string, mixed>} */
  const body = {
    label: String(input.label || '').trim(),
    kind,
    productCode: String(input.productCode || '').trim(),
    startsAt: input.startsAt || null,
    validUntil,
    amountCents: Number.isFinite(input.amountCents) ? Math.round(input.amountCents) : 0,
    invoiceStatus: String(input.invoiceStatus || 'none'),
  };
  // SECURITY: never attach limits on edit — wiping hoursQuota / seats is a data-loss bug.
  if (mode === 'edit') {
    if (input.updatedAt) {
      body.updatedAt = input.updatedAt;
    }
    return body;
  }
  body.companyId = Number.isFinite(input.companyId) ? input.companyId : 0;
  body.status = 'active';
  return body;
}

/**
 * @param {unknown} amountEur
 * @returns {number}
 */
export function eurosToCents(amountEur) {
  const n = typeof amountEur === 'number' ? amountEur : parseFloat(String(amountEur ?? '0'));
  return Math.round((Number.isFinite(n) ? n : 0) * 100);
}
