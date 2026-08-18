import { describe, expect, it } from 'vitest';
import {
  ALLOWED_FOCUS_TABS,
  DEFAULT_FOCUS_TAB,
  SECTION_TAB_MAP,
  computeScrollTop,
  hashBelongsToOtherTab,
  hashHrefToSectionId,
  isFocusDeepLinkSectionId,
  isJumpCurrent,
  normalizeTabId,
  orderedPresentTabIds,
  resolveTabForElement,
  tabForSectionId,
} from '../../js/common/company360-focus-core.js';

describe('tabForSectionId', () => {
  it('maps known section ids to focus tabs', () => {
    expect(tabForSectionId('crm-communication')).toBe('today');
    expect(tabForSectionId('crm-persons')).toBe('people');
    expect(tabForSectionId('crm-deals')).toBe('deals');
    expect(tabForSectionId('crm-billing')).toBe('money');
    expect(tabForSectionId('crm-timeline')).toBe('more');
  });

  it('returns null for unknown or empty ids', () => {
    expect(tabForSectionId('crm-evil')).toBeNull();
    expect(tabForSectionId('')).toBeNull();
    expect(tabForSectionId(null)).toBeNull();
  });
});

describe('resolveTabForElement', () => {
  it('prefers the owning panel over the static map', () => {
    const identity = {
      closest(sel) {
        if (sel === '[data-crm-focus-panel]') {
          return {
            getAttribute(name) {
              return name === 'data-crm-focus-panel' ? 'money' : null;
            },
          };
        }
        return null;
      },
    };
    // Static map says more; DOM says money (cold merge path).
    expect(resolveTabForElement(identity, 'crm-identity-links')).toBe('money');
  });

  it('falls back to SECTION_TAB_MAP when outside panels', () => {
    const glance = { closest() { return null; } };
    expect(resolveTabForElement(glance, 'crm-glance')).toBe('today');
    expect(resolveTabForElement(null, 'crm-deals')).toBe('deals');
    expect(resolveTabForElement(glance, null)).toBeNull();
    expect(resolveTabForElement({ closest() { return null; } }, 'crm-person-12')).toBeNull();
  });

  it('ignores unknown panel attributes', () => {
    const child = {
      closest() {
        return {
          getAttribute() {
            return 'evil';
          },
        };
      },
    };
    expect(resolveTabForElement(child, 'crm-billing')).toBe('money');
  });
});

describe('normalizeTabId', () => {
  it('falls back to today or first allowed tab', () => {
    expect(normalizeTabId('deals', ['today', 'deals', 'money'])).toBe('deals');
    expect(normalizeTabId('nope', ['today', 'deals'])).toBe('today');
    expect(normalizeTabId('', ['money'])).toBe('money');
    expect(normalizeTabId(null, [])).toBe(DEFAULT_FOCUS_TAB);
  });

  it('rejects sessionStorage poison values', () => {
    expect(normalizeTabId('__proto__', ['today', 'deals'])).toBe('today');
    expect(normalizeTabId('constructor', ALLOWED_FOCUS_TABS.slice())).toBe('today');
  });
});

describe('hashHrefToSectionId', () => {
  it('accepts safe fragment ids only', () => {
    expect(hashHrefToSectionId('#crm-tickets')).toBe('crm-tickets');
    expect(hashHrefToSectionId('#')).toBeNull();
    expect(hashHrefToSectionId('#foo/bar')).toBeNull();
    expect(hashHrefToSectionId('#%61')).toBeNull();
    expect(hashHrefToSectionId('#javascript:alert(1)')).toBeNull();
    expect(hashHrefToSectionId('#crm:evil')).toBeNull();
    expect(hashHrefToSectionId('crm-tickets')).toBeNull();
    expect(hashHrefToSectionId('#__proto__')).toBeNull();
    expect(hashHrefToSectionId('#crm tickets')).toBeNull();
    expect(hashHrefToSectionId('#1crm')).toBeNull();
  });
});

describe('isFocusDeepLinkSectionId', () => {
  it('allowlists SECTION_TAB_MAP landmarks and numeric people rows', () => {
    expect(isFocusDeepLinkSectionId('crm-billing')).toBe(true);
    expect(isFocusDeepLinkSectionId('crm-identity-links')).toBe(true);
    expect(isFocusDeepLinkSectionId('crm-person-12')).toBe(true);
    expect(isFocusDeepLinkSectionId('crm-person-form')).toBe(false);
    expect(isFocusDeepLinkSectionId('crm-person-email')).toBe(false);
    expect(isFocusDeepLinkSectionId('crm-person-')).toBe(false);
    expect(isFocusDeepLinkSectionId('crm-plan-goal')).toBe(false);
    expect(isFocusDeepLinkSectionId('crm-evil')).toBe(false);
    expect(isFocusDeepLinkSectionId('')).toBe(false);
    expect(isFocusDeepLinkSectionId(null)).toBe(false);
  });
});

describe('SECTION_TAB_MAP', () => {
  it('covers every company-detail deep-link anchor', () => {
    const required = [
      'crm-glance',
      'crm-communication',
      'crm-persons',
      'crm-next-steps',
      'crm-deals',
      'crm-contracts',
      'crm-billing',
      'crm-documents',
      'crm-projects',
      'crm-tickets',
      'crm-invoices',
      'crm-account-plan',
      'crm-identity-links',
      'crm-timeline',
    ];
    required.forEach((id) => {
      expect(SECTION_TAB_MAP[id], id).toBeTruthy();
      expect(ALLOWED_FOCUS_TABS).toContain(SECTION_TAB_MAP[id]);
    });
  });

  it('defaults to today tab constant', () => {
    expect(DEFAULT_FOCUS_TAB).toBe('today');
  });
});

describe('computeScrollTop', () => {
  it('offsets for sticky chrome and never goes negative', () => {
    expect(computeScrollTop(100, 400, 50, 80)).toBe(370);
    expect(computeScrollTop(0, 40, 50, 80)).toBe(0);
  });

  it('treats NaN and non-numbers as zero', () => {
    expect(computeScrollTop(NaN, 100, 0, 0)).toBe(100);
    expect(computeScrollTop(10, Number.NaN, 0, 5)).toBe(5);
  });
});

describe('isJumpCurrent', () => {
  it('guards stale requestAnimationFrame jumps', () => {
    expect(isJumpCurrent(3, 3)).toBe(true);
    expect(isJumpCurrent(4, 3)).toBe(false);
    expect(isJumpCurrent(null, 1)).toBe(false);
  });
});

describe('orderedPresentTabIds', () => {
  it('follows canonical order even when present order is scrambled', () => {
    expect(orderedPresentTabIds(['more', 'today', 'money', 'deals', 'people'], ALLOWED_FOCUS_TABS)).toEqual([
      'today',
      'people',
      'deals',
      'money',
      'more',
    ]);
  });

  it('drops unknown ids and keeps only present allowlisted tabs', () => {
    expect(orderedPresentTabIds(['evil', 'money', 'today'], ALLOWED_FOCUS_TABS)).toEqual([
      'today',
      'money',
    ]);
    expect(orderedPresentTabIds([], ALLOWED_FOCUS_TABS)).toEqual([]);
    expect(orderedPresentTabIds(null, ALLOWED_FOCUS_TABS)).toEqual([]);
  });
});

describe('hashBelongsToOtherTab', () => {
  it('detects stale deep-links after a manual tab switch', () => {
    const billing = {
      closest(sel) {
        if (sel === '[data-crm-focus-panel]') {
          return {
            getAttribute(name) {
              return name === 'data-crm-focus-panel' ? 'money' : null;
            },
          };
        }
        return null;
      },
    };
    expect(hashBelongsToOtherTab('#crm-tickets', 'today', billing)).toBe(true);
    expect(hashBelongsToOtherTab('#crm-tickets', 'money', billing)).toBe(false);
    expect(hashBelongsToOtherTab('#crm-person-12', 'deals', billing)).toBe(true);
    expect(hashBelongsToOtherTab('#crm-person-form', 'today', billing)).toBe(false);
    expect(hashBelongsToOtherTab('#crm-next-steps', 'today', null)).toBe(false);
    expect(hashBelongsToOtherTab('#crm-plan-goal', 'today', billing)).toBe(false);
    expect(hashBelongsToOtherTab('', 'today', billing)).toBe(false);
  });
});
