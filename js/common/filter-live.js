/**
 * Live filter forms — selects apply immediately; search debounces.
 * Keeps Apply/Clear for keyboard users and progressive enhancement.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function () {
  'use strict';

  const DEBOUNCE_MS = 350;

  /**
   * @param {HTMLFormElement} form
   */
  function submitForm(form) {
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  }

  /**
   * @param {HTMLFormElement} form
   */
  function wireLiveFilter(form) {
    if (!(form instanceof HTMLFormElement) || form.dataset.crmLiveFilterWired === '1') {
      return;
    }
    form.dataset.crmLiveFilterWired = '1';

    /** @type {ReturnType<typeof setTimeout>|null} */
    let searchTimer = null;

    form.querySelectorAll('select').forEach((select) => {
      select.addEventListener('change', () => {
        submitForm(form);
      });
    });

    form.querySelectorAll('input[type="search"], input[name="q"]').forEach((input) => {
      input.addEventListener('input', () => {
        if (searchTimer) {
          clearTimeout(searchTimer);
        }
        searchTimer = setTimeout(() => {
          submitForm(form);
        }, DEBOUNCE_MS);
      });
      input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          if (searchTimer) {
            clearTimeout(searchTimer);
          }
          submitForm(form);
        }
      });
    });
  }

  function init() {
    document.querySelectorAll('form.crm-filter-panel__form, form[data-crm-live-filter]').forEach((form) => {
      if (form instanceof HTMLFormElement) {
        wireLiveFilter(form);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.CustomerCheckFilterLive = { init: init, wireLiveFilter: wireLiveFilter };
})();
