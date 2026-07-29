/**
 * Company 360 jump navigation — scroll spy + accessible in-page jumps (WCAG 2.4.1).
 *
 * Security: only same-document hash hrefs (#id) are handled; no external navigation.
 * Scroll root is #app-content (shell overflow), not the window.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function () {
  'use strict';

  const NAV_SELECTOR = '[data-crm-jump-nav="1"]';
  const LINK_SELECTOR = '.crm-jump-nav__link[href^="#"]';
  const SCROLL_ROOT_SELECTOR = '#app-content';
  /** Prevent IntersectionObserver from fighting a just-clicked chip. */
  const SPY_LOCK_MS = 900;
  const STICKY_GAP_PX = 8;

  /**
   * @param {string|null|undefined} href
   * @returns {string|null}
   */
  function hashHrefToId(href) {
    if (typeof href !== 'string' || href.length < 2 || href.charAt(0) !== '#') {
      return null;
    }
    const id = href.slice(1);
    if (id === '' || id.indexOf(' ') !== -1 || id.indexOf('#') !== -1) {
      return null;
    }
    if (id.indexOf('/') !== -1 || id.indexOf('%') !== -1) {
      return null;
    }
    return id;
  }

  /**
   * @param {string} id
   * @returns {HTMLElement|null}
   */
  function resolveSection(id) {
    if (!id) {
      return null;
    }
    const el = document.getElementById(id);
    return el instanceof HTMLElement ? el : null;
  }

  /**
   * @param {HTMLElement[]} links
   * @param {HTMLElement|null} activeLink
   */
  function setActiveLink(links, activeLink) {
    links.forEach((link) => {
      if (link === activeLink) {
        link.setAttribute('aria-current', 'location');
      } else {
        link.removeAttribute('aria-current');
      }
    });
  }

  /**
   * @param {Array<{ id: string, ratio: number }>} scored
   * @returns {string|null}
   */
  function pickMostVisibleId(scored) {
    let bestId = null;
    let bestRatio = 0;
    scored.forEach((row) => {
      if (row.ratio > bestRatio) {
        bestRatio = row.ratio;
        bestId = row.id;
      }
    });
    return bestRatio > 0 ? bestId : null;
  }

  /**
   * @param {number} rootScrollTop
   * @param {number} sectionTop
   * @param {number} rootTop
   * @param {number} stickyOffsetPx
   * @returns {number}
   */
  function computeScrollTop(rootScrollTop, sectionTop, rootTop, stickyOffsetPx) {
    const next = rootScrollTop + (sectionTop - rootTop) - stickyOffsetPx;
    return next > 0 ? next : 0;
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
   * Open ancestor <details> so collapsed linked-app sections become reachable.
   *
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
   * Scroll #app-content so the section sits under the sticky chip bar.
   * Native scrollIntoView is unreliable when the window itself does not scroll.
   *
   * @param {HTMLElement} section
   * @param {string} id
   */
  function jumpToSection(section, id) {
    revealAncestorDetails(section);
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const behavior = reduceMotion ? 'auto' : 'smooth';
    const root = scrollRoot();
    const nav = document.querySelector(NAV_SELECTOR);
    const stickyOffset =
      nav instanceof HTMLElement ? Math.ceil(nav.getBoundingClientRect().height) + STICKY_GAP_PX : 0;

    if (root) {
      const rootRect = root.getBoundingClientRect();
      const sectionRect = section.getBoundingClientRect();
      const top = computeScrollTop(root.scrollTop, sectionRect.top, rootRect.top, stickyOffset);
      if (typeof root.scrollTo === 'function') {
        root.scrollTo({ top: top, behavior: behavior });
      } else {
        root.scrollTop = top;
      }
    } else {
      section.scrollIntoView({ behavior: behavior, block: 'start' });
    }

    focusSectionHeading(section);
    if (history.replaceState) {
      history.replaceState(null, '', '#' + id);
    } else {
      window.location.hash = id;
    }
  }

  function initJumpNav() {
    const nav = document.querySelector(NAV_SELECTOR);
    if (!(nav instanceof HTMLElement)) {
      return;
    }

    const links = Array.from(nav.querySelectorAll(LINK_SELECTOR)).filter(
      (node) => node instanceof HTMLElement,
    );
    if (links.length === 0) {
      return;
    }

    /** @type {{ link: HTMLElement, section: HTMLElement, id: string }[]} */
    const entries = [];
    links.forEach((link) => {
      const id = hashHrefToId(link.getAttribute('href'));
      if (!id) {
        return;
      }
      const section = resolveSection(id);
      if (section) {
        entries.push({ link, section, id });
      } else {
        link.setAttribute('aria-disabled', 'true');
        link.setAttribute('tabindex', '-1');
        link.classList.add('crm-jump-nav__link--missing');
        link.addEventListener('click', (ev) => {
          ev.preventDefault();
        });
      }
    });

    if (entries.length === 0) {
      return;
    }

    const live = document.getElementById('crm-live-region');
    let spyLockUntil = 0;

    /**
     * @param {string} id
     */
    function activateForId(id) {
      const match = entries.find((e) => e.id === id);
      if (match) {
        setActiveLink(links, match.link);
        if (live) {
          live.textContent = match.link.textContent || '';
        }
      }
    }

    entries.forEach((entry) => {
      entry.link.addEventListener('click', (ev) => {
        ev.preventDefault();
        spyLockUntil = Date.now() + SPY_LOCK_MS;
        jumpToSection(entry.section, entry.id);
        activateForId(entry.id);
      });
    });

    if (window.location.hash) {
      const initialId = hashHrefToId(window.location.hash);
      const initial = initialId ? entries.find((e) => e.id === initialId) : null;
      if (initial) {
        activateForId(initialId);
        spyLockUntil = Date.now() + SPY_LOCK_MS;
        window.requestAnimationFrame(() => {
          jumpToSection(initial.section, initial.id);
        });
      }
    } else {
      setActiveLink(links, entries[0].link);
    }

    window.addEventListener('hashchange', () => {
      const id = hashHrefToId(window.location.hash);
      if (id) {
        const match = entries.find((e) => e.id === id);
        if (match) {
          spyLockUntil = Date.now() + SPY_LOCK_MS;
          jumpToSection(match.section, match.id);
          activateForId(id);
        }
      }
    });

    if (typeof IntersectionObserver !== 'function') {
      return;
    }

    const visible = new Map();
    const root = scrollRoot();
    const observer = new IntersectionObserver(
      (observations) => {
        if (Date.now() < spyLockUntil) {
          return;
        }
        observations.forEach((obs) => {
          visible.set(obs.target, obs.isIntersecting ? obs.intersectionRatio : 0);
        });
        const scored = entries.map((entry) => ({
          id: entry.id,
          ratio: visible.get(entry.section) || 0,
        }));
        const bestId = pickMostVisibleId(scored);
        if (bestId) {
          activateForId(bestId);
        }
      },
      {
        root: root,
        rootMargin: '-15% 0px -60% 0px',
        threshold: [0, 0.1, 0.25, 0.5, 0.75, 1],
      },
    );

    entries.forEach((entry) => observer.observe(entry.section));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initJumpNav);
  } else {
    initJumpNav();
  }
})();
