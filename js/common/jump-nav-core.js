/**
 * Pure helpers for company-detail jump navigation (testable without DOM bootstrap).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * Accept only a same-document fragment id (no spaces, no protocol tricks).
 *
 * @param {string|null|undefined} href
 * @returns {string|null} id without leading #
 */
export function hashHrefToId(href) {
  if (typeof href !== 'string' || href.length < 2 || href.charAt(0) !== '#') {
    return null;
  }
  const id = href.slice(1);
  if (id === '' || id.indexOf(' ') !== -1 || id.indexOf('#') !== -1) {
    return null;
  }
  // Block encoded / path-like fragments that could confuse getElementById.
  if (id.indexOf('/') !== -1 || id.indexOf('%') !== -1) {
    return null;
  }
  return id;
}

/**
 * Pick the most visible section from IntersectionObserver ratios.
 *
 * @param {Array<{ id: string, ratio: number }>} entries
 * @returns {string|null} winning id
 */
export function pickMostVisibleId(entries) {
  if (!Array.isArray(entries) || entries.length === 0) {
    return null;
  }
  let bestId = null;
  let bestRatio = 0;
  for (let i = 0; i < entries.length; i++) {
    const row = entries[i];
    if (!row || typeof row.id !== 'string' || typeof row.ratio !== 'number') {
      continue;
    }
    if (row.ratio > bestRatio) {
      bestRatio = row.ratio;
      bestId = row.id;
    }
  }
  return bestRatio > 0 ? bestId : null;
}

/**
 * Whether scroll-spy may update aria-current (locked briefly after manual jump).
 *
 * @param {number} nowMs
 * @param {number} lockUntilMs
 * @returns {boolean}
 */
export function canApplyScrollSpy(nowMs, lockUntilMs) {
  return !(typeof lockUntilMs === 'number' && typeof nowMs === 'number' && nowMs < lockUntilMs);
}

/**
 * Compute scrollTop so `sectionTop` lands just below a sticky bar inside the scroll root.
 *
 * @param {number} rootScrollTop
 * @param {number} sectionTop relative to viewport
 * @param {number} rootTop relative to viewport
 * @param {number} stickyOffsetPx height of sticky jump bar (+ gap)
 * @returns {number}
 */
export function computeScrollTop(rootScrollTop, sectionTop, rootTop, stickyOffsetPx) {
  const scrollTop = typeof rootScrollTop === 'number' ? rootScrollTop : 0;
  const section = typeof sectionTop === 'number' ? sectionTop : 0;
  const root = typeof rootTop === 'number' ? rootTop : 0;
  const sticky = typeof stickyOffsetPx === 'number' && stickyOffsetPx > 0 ? stickyOffsetPx : 0;
  const next = scrollTop + (section - root) - sticky;
  return next > 0 ? next : 0;
}
