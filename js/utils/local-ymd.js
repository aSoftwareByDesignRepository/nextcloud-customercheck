/**
 * Local / UTC calendar YYYY-MM-DD helpers (vitest + shared contract with IIFE pages).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * Local calendar YYYY-MM-DD — never use Date#toISOString for "today"
 * (UTC can roll the calendar day backward in evening timezones).
 *
 * @param {Date} [d]
 * @returns {string}
 */
export function localYmd(d = new Date()) {
  const x = d instanceof Date ? d : new Date();
  const y = x.getFullYear();
  const m = String(x.getMonth() + 1).padStart(2, '0');
  const day = String(x.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/**
 * UTC calendar YYYY-MM-DD for values built with Date.UTC / getUTC*.
 *
 * @param {Date} d
 * @returns {string}
 */
export function utcYmd(d) {
  const y = d.getUTCFullYear();
  const m = String(d.getUTCMonth() + 1).padStart(2, '0');
  const day = String(d.getUTCDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}
