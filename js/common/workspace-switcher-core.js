/**
 * Pure helpers for the CustomerCheck workspace switcher (testable without DOM).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/** Pages that must leave their object URL when the workspace changes. */
export const WORKSPACE_SWITCH_LIST_FALLBACK = Object.freeze({
  'company-detail': 'companies',
  'company-form': 'companies',
  'agreement-form': 'agreements',
});

/**
 * @param {string|null|undefined} raw
 * @returns {number}
 */
export function parseWorkspaceId(raw) {
  if (raw === null || raw === undefined || raw === '') {
    return 0;
  }
  const n = Number.parseInt(String(raw), 10);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

/**
 * Read the active workspace from URL, then SSR data attribute, then legacy control value.
 *
 * @param {{ search?: string, activeAttr?: string|null, controlValue?: string|null }} input
 * @returns {number}
 */
export function resolveActiveWorkspaceId(input) {
  const fromUrl = parseWorkspaceId(
    typeof input.search === 'string'
      ? new URLSearchParams(input.search.startsWith('?') ? input.search.slice(1) : input.search).get('workspaceId')
      : null,
  );
  if (fromUrl > 0) {
    return fromUrl;
  }
  const fromAttr = parseWorkspaceId(input.activeAttr);
  if (fromAttr > 0) {
    return fromAttr;
  }
  return parseWorkspaceId(input.controlValue);
}

/**
 * Build a same-origin relative href with workspaceId replaced.
 * Rejects absolute/external URLs to prevent open redirects.
 *
 * @param {string} baseHref
 * @param {number} workspaceId
 * @returns {string}
 */
export function withWorkspaceId(baseHref, workspaceId) {
  const id = parseWorkspaceId(workspaceId);
  if (id <= 0 || typeof baseHref !== 'string' || baseHref === '') {
    return '#';
  }
  if (/^[a-z][a-z0-9+.-]*:/i.test(baseHref) || baseHref.startsWith('//')) {
    return '#';
  }
  try {
    const url = new URL(baseHref, 'https://crm.invalid');
    if (url.host !== 'crm.invalid') {
      return '#';
    }
    url.searchParams.set('workspaceId', String(id));
    return url.pathname + url.search + url.hash;
  } catch (e) {
    return '#';
  }
}

/**
 * @param {string} pageId
 * @returns {string|null} shell URL key to use instead of the entity path
 */
export function listFallbackKeyForPage(pageId) {
  if (typeof pageId !== 'string' || pageId === '') {
    return null;
  }
  return WORKSPACE_SWITCH_LIST_FALLBACK[pageId] || null;
}

/**
 * Decide whether the disclosure may open (multi-workspace only).
 *
 * @param {number} workspaceCount
 * @returns {boolean}
 */
export function canOpenWorkspaceMenu(workspaceCount) {
  return Number.isFinite(workspaceCount) && workspaceCount > 1;
}
