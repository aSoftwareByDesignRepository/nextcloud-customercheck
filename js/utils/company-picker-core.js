/**
 * Pure company list helpers for the dialog company picker panel.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * @param {unknown} raw
 * @returns {Array<{ id: number, name: string, updatedAt: string }>}
 */
export function normalizeCompanyHits(raw) {
  let list = raw;
  if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
    const obj = /** @type {Record<string, unknown>} */ (raw);
    list = obj.items || obj.data || obj.companies || [];
  }
  if (!Array.isArray(list)) {
    return [];
  }
  return list.map((row) => {
    const r = row && typeof row === 'object' ? /** @type {Record<string, unknown>} */ (row) : {};
    const id = Math.max(0, parseInt(String(r.id || 0), 10) || 0);
    const name = String(r.name || r.label || '').trim() || (id > 0 ? ('#' + id) : '');
    const updatedAt = String(r.updatedAt || r.updated_at || '').trim();
    return { id, name, updatedAt };
  }).filter((c) => c.id > 0);
}

/**
 * @param {Array<{ id: number, name: string }>} items
 * @param {string} query
 * @param {number} [limit=40]
 * @returns {Array<{ id: number, name: string }>}
 */
export function filterLocalCompanies(items, query, limit = 40) {
  const q = String(query || '').trim().toLowerCase();
  const rows = Array.isArray(items) ? items : [];
  const capped = Math.max(0, limit);
  if (!q) {
    return rows.slice(0, capped);
  }
  return rows.filter((c) => String(c.name || '').toLowerCase().includes(q)).slice(0, capped);
}

/**
 * @param {Array<{ id: number, name: string }>} items
 * @param {unknown} excludeIds
 * @returns {Array<{ id: number, name: string }>}
 */
export function applyExclude(items, excludeIds) {
  const ban = new Set();
  (Array.isArray(excludeIds) ? excludeIds : []).forEach((raw) => {
    const id = Math.max(0, parseInt(String(raw || 0), 10) || 0);
    if (id > 0) ban.add(id);
  });
  if (!ban.size) return Array.isArray(items) ? items : [];
  return (Array.isArray(items) ? items : []).filter((c) => !ban.has(c.id));
}
