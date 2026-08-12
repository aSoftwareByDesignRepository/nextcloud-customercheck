/**
 * Company 360 focus tabs — one panel at a time (Pipedrive-style), hash-safe deep links.
 *
 * Classic IIFE (Util::addScript) — helpers stay in sync with company360-focus-core.js (Vitest).
 * Security: only same-document #ids; no javascript:/data:; document-level capture for glance KPIs.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-crm-360-focus="1"]';
  const TAB_SELECTOR = '[data-crm-focus-tab]';
  const PANEL_SELECTOR = '[data-crm-focus-panel]';
  const SCROLL_ROOT_SELECTOR = '#app-content';
  const STICKY_GAP_PX = 8;
  const STORAGE_PREFIX = 'crm360-focus-tab:';
  const DEFAULT_FOCUS_TAB = 'today';
  const ALLOWED_FOCUS_TABS = ['today', 'deals', 'money', 'more'];

  /** @type {Readonly<Record<string, string>>} */
  const SECTION_TAB_MAP = Object.freeze({
    'crm-glance': 'today',
    'crm-welcome-coach': 'today',
    'crm-next-steps': 'today',
    'crm-activities': 'today',
    'crm-promises': 'today',
    'crm-open-tasks': 'today',
    'crm-communication': 'today',
    'crm-persons': 'today',
    'crm-deals': 'deals',
    'crm-contracts': 'deals',
    'crm-billing': 'money',
    'crm-documents': 'money',
    'crm-linked-bundle': 'money',
    'crm-projects': 'money',
    'crm-tickets': 'money',
    'crm-invoices': 'money',
    'crm-identity-links': 'more',
    'crm-account-plan': 'more',
    'crm-emails': 'more',
    'crm-platform-links': 'more',
    'crm-prepare-meeting': 'more',
    'crm-timeline': 'more',
  });

  /**
   * @param {string|null|undefined} sectionId
   * @returns {string|null}
   */
  function tabForSectionId(sectionId) {
    if (typeof sectionId !== 'string' || sectionId === '') {
      return null;
    }
    const tab = SECTION_TAB_MAP[sectionId];
    return typeof tab === 'string' && tab !== '' ? tab : null;
  }

  /**
   * @param {Element|null|undefined} el
   * @param {string|null|undefined} fallbackSectionId
   * @returns {string|null}
   */
  function resolveTabForElement(el, fallbackSectionId) {
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
   * @param {string[]} allowed
   * @returns {string}
   */
  function normalizeTabId(tabId, allowed) {
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
   * @param {string|null|undefined} href
   * @returns {string|null}
   */
  function hashHrefToSectionId(href) {
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
    if (!/^[A-Za-z][A-Za-z0-9_-]*$/.test(id)) {
      return null;
    }
    return id;
  }

  /**
   * @param {string|null|undefined} sectionId
   * @returns {boolean}
   */
  function isFocusDeepLinkSectionId(sectionId) {
    return tabForSectionId(sectionId) !== null;
  }

  /**
   * @param {number} rootScrollTop
   * @param {number} sectionTop
   * @param {number} rootTop
   * @param {number} stickyOffsetPx
   * @returns {number}
   */
  function computeScrollTop(rootScrollTop, sectionTop, rootTop, stickyOffsetPx) {
    const scrollTop = typeof rootScrollTop === 'number' && Number.isFinite(rootScrollTop) ? rootScrollTop : 0;
    const section = typeof sectionTop === 'number' && Number.isFinite(sectionTop) ? sectionTop : 0;
    const root = typeof rootTop === 'number' && Number.isFinite(rootTop) ? rootTop : 0;
    const sticky = typeof stickyOffsetPx === 'number' && Number.isFinite(stickyOffsetPx) && stickyOffsetPx > 0 ? stickyOffsetPx : 0;
    const next = scrollTop + (section - root) - sticky;
    return next > 0 ? next : 0;
  }

  /**
   * @param {number} current
   * @param {number} expected
   * @returns {boolean}
   */
  function isJumpCurrent(current, expected) {
    return typeof current === 'number' && typeof expected === 'number' && current === expected;
  }

  /**
   * @param {Iterable<string>|null|undefined} presentIds
   * @param {string[]|null|undefined} canonicalOrder
   * @returns {string[]}
   */
  function orderedPresentTabIds(presentIds, canonicalOrder) {
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
   * @param {string|null|undefined} hashHref
   * @param {string|null|undefined} activeTabId
   * @param {Element|null|undefined} sectionEl
   * @returns {boolean}
   */
  function hashBelongsToOtherTab(hashHref, activeTabId, sectionEl) {
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

  /**
   * @returns {HTMLElement|null}
   */
  function scrollRoot() {
    const el = document.querySelector(SCROLL_ROOT_SELECTOR);
    return el instanceof HTMLElement ? el : null;
  }

  /**
   * @param {HTMLElement} section
   */
  function revealAncestorDetails(section) {
    let el = section.parentElement;
    while (el && el !== document.documentElement) {
      if (el.tagName === 'DETAILS' && !el.open) {
        el.open = true;
      }
      el = el.parentElement;
    }
  }

  /**
   * @param {HTMLElement} section
   */
  function focusSectionHeading(section) {
    const heading = section.querySelector('h2, h3, [id$="-title"]');
    const target = heading instanceof HTMLElement ? heading : section;
    if (!target.hasAttribute('tabindex')) {
      target.setAttribute('tabindex', '-1');
    }
    try {
      target.focus({ preventScroll: true });
    } catch (e) {
      target.focus();
    }
  }

  /**
   * Fail-open: if tabs never init, never leave panels permanently hidden.
   *
   * @param {HTMLElement} root
   */
  function revealAllPanels(root) {
    root.querySelectorAll(PANEL_SELECTOR).forEach(function (node) {
      if (node instanceof HTMLElement) {
        node.removeAttribute('hidden');
        node.removeAttribute('aria-hidden');
      }
    });
  }

  function initFocusTabs() {
    const root = document.querySelector(ROOT_SELECTOR);
    if (!(root instanceof HTMLElement)) {
      return;
    }
    if (root.getAttribute('data-crm-focus-ready') === '1') {
      return;
    }

    /** @type {Map<string, HTMLElement>} */
    const tabs = new Map();
    /** @type {Map<string, HTMLElement>} */
    const panels = new Map();
    let jumpGeneration = 0;
    let ignoreHashChange = false;
    let quietHashSeq = 0;

    /**
     * Programmatic hash edits must not re-enter jump handlers (esp. location.hash fallback).
     * Clear on microtask so the synthetic hashchange (if any) is ignored, but the next
     * user/back-forward navigation is not dropped for a full event-loop turn.
     *
     * @param {function(): void} mutate
     */
    function withQuietHash(mutate) {
      const seq = ++quietHashSeq;
      ignoreHashChange = true;
      try {
        mutate();
      } finally {
        Promise.resolve().then(function () {
          if (seq === quietHashSeq) {
            ignoreHashChange = false;
          }
        });
      }
    }

    /**
     * @param {string} id
     */
    function setDocumentHash(id) {
      withQuietHash(function () {
        if (history.replaceState) {
          history.replaceState(null, '', '#' + id);
        } else {
          window.location.hash = id;
        }
      });
    }

    /**
     * Drop a stale deep-link when the user picks a different tab manually.
     *
     * @param {string} tabId
     */
    function clearStaleHashForTab(tabId) {
      const hash = window.location.hash;
      const sectionId = hashHrefToSectionId(hash);
      const section = sectionId ? document.getElementById(sectionId) : null;
      if (!hashBelongsToOtherTab(hash, tabId, section)) {
        return;
      }
      withQuietHash(function () {
        if (history.replaceState) {
          history.replaceState(null, '', window.location.pathname + window.location.search);
        } else if (window.location.hash) {
          window.location.hash = '';
        }
      });
    }

    try {
      root.querySelectorAll(TAB_SELECTOR).forEach(function (node) {
        if (!(node instanceof HTMLElement)) {
          return;
        }
        const id = node.getAttribute('data-crm-focus-tab');
        if (id && ALLOWED_FOCUS_TABS.indexOf(id) !== -1) {
          tabs.set(id, node);
        }
      });

      root.querySelectorAll(PANEL_SELECTOR).forEach(function (node) {
        if (!(node instanceof HTMLElement)) {
          return;
        }
        const id = node.getAttribute('data-crm-focus-panel');
        if (id && ALLOWED_FOCUS_TABS.indexOf(id) !== -1) {
          panels.set(id, node);
        }
      });

      if (tabs.size === 0 || panels.size === 0) {
        revealAllPanels(root);
        root.setAttribute('data-crm-focus-ready', '1');
        root.setAttribute('data-crm-focus-degraded', '1');
        return;
      }

      const chrome = root.querySelector('.crm-360-focus__chrome');
      const stickyChrome = chrome instanceof HTMLElement ? chrome : null;

      /**
       * @param {string} tabId
       * @param {boolean} persist
       */
      function activateTab(tabId, persist) {
        const allowed = Array.from(panels.keys());
        const next = normalizeTabId(tabId, allowed);

        panels.forEach(function (panel, id) {
          const active = id === next;
          if (active) {
            panel.removeAttribute('hidden');
            panel.removeAttribute('aria-hidden');
          } else {
            panel.setAttribute('hidden', '');
            panel.setAttribute('aria-hidden', 'true');
          }
        });

        tabs.forEach(function (tab, id) {
          const selected = id === next;
          tab.setAttribute('aria-selected', selected ? 'true' : 'false');
          tab.tabIndex = selected ? 0 : -1;
        });

        root.setAttribute('data-crm-focus-active', next);

        if (persist) {
          const companyId = root.getAttribute('data-company-id') || '';
          if (companyId && /^\d+$/.test(companyId)) {
            try {
              sessionStorage.setItem(STORAGE_PREFIX + companyId, next);
            } catch (e) {
              /* quota / private mode */
            }
          }
        }
      }

      /**
       * @param {HTMLElement} section
       * @param {string} id
       * @param {number} generation
       */
      function scrollToSection(section, id, generation) {
        if (!isJumpCurrent(jumpGeneration, generation)) {
          return;
        }
        revealAncestorDetails(section);
        const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const behavior = reduceMotion ? 'auto' : 'smooth';
        const scrollEl = scrollRoot();
        const stickyOffset =
          stickyChrome instanceof HTMLElement
            ? Math.ceil(stickyChrome.getBoundingClientRect().height) + STICKY_GAP_PX
            : 0;

        if (scrollEl) {
          const rootRect = scrollEl.getBoundingClientRect();
          const sectionRect = section.getBoundingClientRect();
          const top = computeScrollTop(scrollEl.scrollTop, sectionRect.top, rootRect.top, stickyOffset);
          if (typeof scrollEl.scrollTo === 'function') {
            scrollEl.scrollTo({ top: top, behavior: behavior });
          } else {
            scrollEl.scrollTop = top;
          }
        } else {
          section.scrollIntoView({ behavior: behavior, block: 'start' });
        }

        if (!isJumpCurrent(jumpGeneration, generation)) {
          return;
        }
        focusSectionHeading(section);
        setDocumentHash(id);
      }

      /**
       * @param {string} sectionId
       */
      function jumpToSectionId(sectionId) {
        if (!isFocusDeepLinkSectionId(sectionId)) {
          return;
        }
        const section = document.getElementById(sectionId);
        if (!(section instanceof HTMLElement)) {
          return;
        }
        const tabId = resolveTabForElement(section, sectionId);
        if (!tabId) {
          return;
        }
        activateTab(tabId, true);
        const generation = ++jumpGeneration;
        window.requestAnimationFrame(function () {
          scrollToSection(section, sectionId, generation);
        });
      }

      let initialTab = DEFAULT_FOCUS_TAB;
      const hashId = hashHrefToSectionId(window.location.hash);
      if (hashId && isFocusDeepLinkSectionId(hashId)) {
        const section = document.getElementById(hashId);
        const fromHash = resolveTabForElement(section, hashId);
        if (fromHash) {
          initialTab = fromHash;
        }
      } else {
        const companyId = root.getAttribute('data-company-id') || '';
        if (companyId && /^\d+$/.test(companyId)) {
          try {
            const stored = sessionStorage.getItem(STORAGE_PREFIX + companyId);
            if (stored) {
              initialTab = normalizeTabId(stored, Array.from(panels.keys()));
            }
          } catch (e) {
            /* private mode */
          }
        }
      }

      activateTab(initialTab, false);

      if (hashId && isFocusDeepLinkSectionId(hashId) && document.getElementById(hashId)) {
        jumpToSectionId(hashId);
      }

      tabs.forEach(function (tab, tabId) {
        tab.addEventListener('click', function () {
          activateTab(tabId, true);
          clearStaleHashForTab(tabId);
          const scrollEl = scrollRoot();
          if (scrollEl) {
            scrollEl.scrollTo({ top: 0, behavior: 'auto' });
          }
        });

        tab.addEventListener('keydown', function (ev) {
          const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
          if (keys.indexOf(ev.key) === -1) {
            return;
          }
          ev.preventDefault();
          const order = orderedPresentTabIds(tabs.keys(), ALLOWED_FOCUS_TABS);
          const idx = order.indexOf(tabId);
          if (idx === -1) {
            return;
          }
          let nextIdx = idx;
          if (ev.key === 'ArrowLeft') {
            nextIdx = idx > 0 ? idx - 1 : order.length - 1;
          } else if (ev.key === 'ArrowRight') {
            nextIdx = idx < order.length - 1 ? idx + 1 : 0;
          } else if (ev.key === 'Home') {
            nextIdx = 0;
          } else if (ev.key === 'End') {
            nextIdx = order.length - 1;
          }
          const nextId = order[nextIdx];
          const nextTab = tabs.get(nextId);
          if (nextTab instanceof HTMLElement) {
            activateTab(nextId, true);
            clearStaleHashForTab(nextId);
            nextTab.focus();
          }
        });
      });

      // Document capture: glance KPIs live outside the focus root.
      document.addEventListener(
        'click',
        function (ev) {
          const target = ev.target;
          if (!(target instanceof Element)) {
            return;
          }
          const link = target.closest('a[href^="#"]');
          if (!(link instanceof HTMLAnchorElement)) {
            return;
          }
          // Only handle in-app company page anchors (never hijack NC shell).
          if (!link.closest('.crm-page-stack, #crm-main-content, ' + ROOT_SELECTOR)) {
            return;
          }
          const sectionId = hashHrefToSectionId(link.getAttribute('href'));
          if (!sectionId || !isFocusDeepLinkSectionId(sectionId)) {
            return;
          }
          const section = document.getElementById(sectionId);
          if (!(section instanceof HTMLElement)) {
            return;
          }
          const tabId = resolveTabForElement(section, sectionId);
          if (!tabId || !panels.has(tabId)) {
            return;
          }
          ev.preventDefault();
          jumpToSectionId(sectionId);
        },
        true,
      );

      window.addEventListener('hashchange', function () {
        if (ignoreHashChange) {
          return;
        }
        const id = hashHrefToSectionId(window.location.hash);
        if (id && isFocusDeepLinkSectionId(id) && document.getElementById(id)) {
          jumpToSectionId(id);
        }
      });

      root.setAttribute('data-crm-focus-ready', '1');
    } catch (err) {
      revealAllPanels(root);
      root.setAttribute('data-crm-focus-ready', '1');
      root.setAttribute('data-crm-focus-degraded', '1');
      if (typeof console !== 'undefined' && console.error) {
        console.error('CustomerCheck focus tabs failed open', err);
      }
    }
  }

  function bootFocusTabs() {
    if (document.querySelector(ROOT_SELECTOR)) {
      initFocusTabs();
      return;
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bootFocusTabs, { once: true });
      return;
    }
    if (typeof MutationObserver === 'function') {
      const obs = new MutationObserver(function () {
        if (document.querySelector(ROOT_SELECTOR)) {
          obs.disconnect();
          initFocusTabs();
        }
      });
      obs.observe(document.documentElement, { childList: true, subtree: true });
      window.setTimeout(function () {
        obs.disconnect();
        initFocusTabs();
      }, 8000);
      return;
    }
    initFocusTabs();
  }

  bootFocusTabs();
})();
