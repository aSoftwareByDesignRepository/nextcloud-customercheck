/**
 * Pure helpers for Company 360 focus tabs (Pipedrive-style progressive disclosure).
 * Kept ESM for Vitest; company360-focus.js must keep identical logic as a classic script
 * (Nextcloud Util::addScript cannot load ES modules).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/** @type {Readonly<Record<string, string>>} section id → tab id (fallback when not in a panel) */
export const SECTION_TAB_MAP = Object.freeze({
  'crm-glance': 'today',
  'crm-welcome-coach': 'today',
  'crm-next-steps': 'today',
  'crm-activities': 'today',
  'crm-promises': 'today',
  'crm-open-tasks': 'today',
  'crm-communication': 'today',
  'crm-persons': 'people',
  'crm-deals': 'deals',
  'crm-contracts': 'deals',
  'crm-billing': 'money',
  'crm-documents': 'money',
  'crm-linked-bundle': 'money',
  'crm-projects': 'money',
  'crm-tickets': 'money',
  'crm-invoices': 'money',
  // Identity may live in Money (cold merge) OR More (warm manage).
  // SECTION_TAB_MAP is fallback only — resolveTabForElement(DOM panel) is authoritative.
  'crm-identity-links': 'more',
  'crm-account-plan': 'more',
  'crm-emails': 'more',
  'crm-platform-links': 'more',
  'crm-prepare-meeting': 'more',
  'crm-timeline': 'more',
});

export const DEFAULT_FOCUS_TAB = 'today';
export const ALLOWED_FOCUS_TABS = Object.freeze(['today', 'people', 'deals', 'money', 'more']);

/**
 * @param {string|null|undefined} sectionId
 * @returns {string|null}
 */
export function tabForSectionId(sectionId) {
  if (typeof sectionId !== 'string' || sectionId === '') {
    return null;
  }
  const tab = SECTION_TAB_MAP[sectionId];
  return typeof tab === 'string' && tab !== '' ? tab : null;
}

/**
 * Prefer the panel that currently owns the node (identity links move between Money/More).
 *
 * @param {Element|null|undefined} el
 * @param {string|null|undefined} fallbackSectionId
 * @returns {string|null}
 */
export function resolveTabForElement(el, fallbackSectionId) {
  if (el && typeof el.closest === 'function') {
    const panel = el.closest('[data-crm-focus-panel]');
    if (panel) {
      const tab = panel.getAttribute('data-crm-focus-panel');
      if (typeof tab === 'string' && tab !== '' && ALLOWED_FOCUS_TABS.indexOf(tab) !== -1) {
        return tab;
      }
    }
  }
  return tabForSectionId(fallbackSectionId);
}

/**
 * @param {string|null|undefined} tabId
 * @param {string[]|null|undefined} allowed
 * @returns {string}
 */
export function normalizeTabId(tabId, allowed) {
  const list = Array.isArray(allowed) ? allowed : [];
  if (typeof tabId === 'string' && tabId !== '' && list.indexOf(tabId) !== -1) {
    return tabId;
  }
  if (list.indexOf(DEFAULT_FOCUS_TAB) !== -1) {
    return DEFAULT_FOCUS_TAB;
  }
  return list.length > 0 ? list[0] : DEFAULT_FOCUS_TAB;
}

/**
 * Accept only a same-document fragment id (no spaces, protocols, encoded paths).
 * Character class is intentionally narrow (crm-* ids) to block exotic DOM ids.
 *
 * @param {string|null|undefined} href
 * @returns {string|null}
 */
export function hashHrefToSectionId(href) {
  if (typeof href !== 'string' || href.length < 2 || href.charAt(0) !== '#') {
    return null;
  }
  const id = href.slice(1);
  if (id === '' || id.indexOf(' ') !== -1 || id.indexOf('#') !== -1) {
    return null;
  }
  if (id.indexOf('/') !== -1 || id.indexOf('%') !== -1 || id.indexOf(':') !== -1) {
    return null;
  }
  // Letters first — rejects __proto__, constructor, numeric-only, unicode tricks.
  if (!/^[A-Za-z][A-Za-z0-9_-]*$/.test(id)) {
    return null;
  }
  return id;
}

/**
 * People-row landmarks (`#crm-person-12`) — numeric id only.
 * Must not match `#crm-person-form` / `#crm-person-email` (identity widgets).
 *
 * @param {string|null|undefined} sectionId
 * @returns {boolean}
 */
export function isPersonRowDeepLinkSectionId(sectionId) {
  return typeof sectionId === 'string' && /^crm-person-\d+$/.test(sectionId);
}

/**
 * Deep-links may only target allowlisted section landmarks (SECTION_TAB_MAP keys)
 * or a people-row id. Prevents hijacking arbitrary in-panel form/widget ids.
 *
 * @param {string|null|undefined} sectionId
 * @returns {boolean}
 */
export function isFocusDeepLinkSectionId(sectionId) {
  return tabForSectionId(sectionId) !== null || isPersonRowDeepLinkSectionId(sectionId);
}

/**
 * @param {number} rootScrollTop
 * @param {number} sectionTop
 * @param {number} rootTop
 * @param {number} stickyOffsetPx
 * @returns {number}
 */
export function computeScrollTop(rootScrollTop, sectionTop, rootTop, stickyOffsetPx) {
  const scrollTop = typeof rootScrollTop === 'number' && Number.isFinite(rootScrollTop) ? rootScrollTop : 0;
  const section = typeof sectionTop === 'number' && Number.isFinite(sectionTop) ? sectionTop : 0;
  const root = typeof rootTop === 'number' && Number.isFinite(rootTop) ? rootTop : 0;
  const sticky = typeof stickyOffsetPx === 'number' && Number.isFinite(stickyOffsetPx) && stickyOffsetPx > 0 ? stickyOffsetPx : 0;
  const next = scrollTop + (section - root) - sticky;
  return next > 0 ? next : 0;
}

/**
 * Whether a jump generation is still current (race guard for hashchange + rAF).
 *
 * @param {number} current
 * @param {number} expected
 * @returns {boolean}
 */
export function isJumpCurrent(current, expected) {
  return typeof current === 'number' && typeof expected === 'number' && current === expected;
}

/**
 * Stable tab order for roving tabindex (never rely on Map insertion / DOM quirks).
 *
 * @param {Iterable<string>|null|undefined} presentIds
 * @param {readonly string[]|string[]|null|undefined} canonicalOrder
 * @returns {string[]}
 */
export function orderedPresentTabIds(presentIds, canonicalOrder) {
  const order = Array.isArray(canonicalOrder) && canonicalOrder.length > 0
    ? canonicalOrder
    : ALLOWED_FOCUS_TABS;
  /** @type {Record<string, true>} */
  const present = Object.create(null);
  if (presentIds && typeof presentIds[Symbol.iterator] === 'function') {
    for (const id of presentIds) {
      if (typeof id === 'string' && id !== '') {
        present[id] = true;
      }
    }
  }
  const out = [];
  for (let i = 0; i < order.length; i++) {
    const id = order[i];
    if (typeof id === 'string' && present[id]) {
      out.push(id);
    }
  }
  return out;
}

/**
 * Whether the current URL fragment belongs to a different focus tab than the one
 * the user just chose (stale deep-link after a manual tab click).
 *
 * @param {string|null|undefined} hashHref window.location.hash or '#crm-…'
 * @param {string|null|undefined} activeTabId
 * @param {Element|null|undefined} sectionEl document.getElementById(sectionId)
 * @returns {boolean}
 */
export function hashBelongsToOtherTab(hashHref, activeTabId, sectionEl) {
  if (typeof activeTabId !== 'string' || activeTabId === '') {
    return false;
  }
  const sectionId = hashHrefToSectionId(hashHref);
  if (!sectionId || !isFocusDeepLinkSectionId(sectionId)) {
    return false;
  }
  const owning = resolveTabForElement(sectionEl, sectionId);
  return typeof owning === 'string' && owning !== '' && owning !== activeTabId;
}
