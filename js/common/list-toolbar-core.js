/**
 * List toolbar: filter toggle sits directly above expandable filter panels.
 * Classic script for Util::addScript (vitest + mutation friendly).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  /**
   * @typedef {Object} ListToolbarOptions
   * @property {string} controlsId
   * @property {string} toggleId
   * @property {string} panelId
   * @property {string} [openClass]
   */

  /**
   * @param {URLSearchParams} params
   * @returns {boolean}
   */
  function shouldOpenFilterPanel(panelId, params) {
    if (!(params instanceof URLSearchParams)) {
      return false;
    }
    switch (panelId) {
      case 'crm-companies-filter':
        return (params.get('status') || '') !== '' || params.get('archived') === '1';
      case 'crm-agreements-filter':
        return (params.get('kind') || '') !== ''
          || (params.get('status') || '') !== ''
          || (params.get('expiry') || '') !== '';
      case 'crm-money-filter':
        return (params.get('state') || '') !== '';
      case 'crm-activities-filter':
        return (params.get('type') || '') !== '' || (params.get('scope') || 'open') !== 'open';
      case 'crm-pipeline-filter':
        return (params.get('stage') || '') !== ''
          || (params.get('period') || '') !== ''
          || (params.get('from') || '') !== ''
          || (params.get('to') || '') !== '';
      default:
        return false;
    }
  }

  /**
   * @param {string} panelId
   * @returns {{ controlsId: string, toggleId: string, openClass: string }|null}
   */
  function panelMeta(panelId) {
    if (typeof panelId !== 'string' || !panelId.endsWith('-filter')) {
      return null;
    }
    const prefix = panelId.slice(0, -'-filter'.length);
    return {
      controlsId: prefix + '-controls',
      toggleId: prefix + '-filter-toggle',
      openClass: prefix === 'crm-pipeline'
        ? 'crm-pipeline-controls--filter-open'
        : 'crm-list-controls--filter-open',
    };
  }

  /**
   * Keep expandable filter panels aligned with the current URL after live-filter swaps.
   *
   * @param {string} [locHref]
   */
  function syncFilterPanelsFromUrl(locHref) {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
      return;
    }
    let params;
    try {
      params = new URL(String(locHref || window.location.href), window.location.href).searchParams;
    } catch (e) {
      return;
    }
    [
      'crm-companies-filter',
      'crm-agreements-filter',
      'crm-money-filter',
      'crm-activities-filter',
      'crm-pipeline-filter',
    ].forEach(function (panelId) {
      const panel = document.getElementById(panelId);
      const meta = panelMeta(panelId);
      if (!panel || !meta) {
        return;
      }
      const open = shouldOpenFilterPanel(panelId, params);
      if (open) {
        panel.removeAttribute('hidden');
      } else {
        panel.setAttribute('hidden', '');
      }
      const wrap = document.getElementById(meta.controlsId);
      if (wrap) {
        wrap.classList.toggle(meta.openClass, open);
      }
      const toggle = document.getElementById(meta.toggleId);
      if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
    });
  }

  /**
   * @param {ListToolbarOptions} options
   * @returns {(() => void)|null} sync function when bound, else null
   */
  function bindListToolbar(options) {
    if (!options || typeof options !== 'object') {
      return null;
    }
    const controlsId = String(options.controlsId || '');
    const toggleId = String(options.toggleId || '');
    const panelId = String(options.panelId || '');
    const openClass = String(options.openClass || 'crm-list-controls--filter-open');
    const wrap = controlsId ? document.getElementById(controlsId) : null;
    const toggle = toggleId ? document.getElementById(toggleId) : null;
    const panel = panelId ? document.getElementById(panelId) : null;
    if (!panel) {
      return null;
    }

    /**
     * @returns {boolean}
     */
    function isOpen() {
      return !panel.hasAttribute('hidden');
    }

    function sync() {
      const open = isOpen();
      if (wrap) {
        wrap.classList.toggle(openClass, open);
      }
      if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
    }

    if (toggle) {
      toggle.addEventListener('click', () => {
        if (panel.hasAttribute('hidden')) {
          panel.removeAttribute('hidden');
        } else {
          panel.setAttribute('hidden', '');
        }
        sync();
      });
    }

    sync();
    return sync;
  }

  global.CustomerCheckListToolbar = {
    bind: bindListToolbar,
    shouldOpenFilterPanel: shouldOpenFilterPanel,
    syncFilterPanelsFromUrl: syncFilterPanelsFromUrl,
  };
})(typeof window !== 'undefined' ? window : globalThis);
