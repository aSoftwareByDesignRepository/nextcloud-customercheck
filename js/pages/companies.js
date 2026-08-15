(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  if (C && C.init) { C.init(); }

  const toggle = document.getElementById('crm-companies-filter-toggle');
  const panel = document.getElementById('crm-companies-filter');
  if (toggle && panel) {
    toggle.addEventListener('click', () => {
      const open = panel.hasAttribute('hidden');
      if (open) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Granny focus: land in the always-visible search when the page has no deep hash.
  const searchInput = document.getElementById('crm-companies-q');
  if (searchInput && !window.location.hash && document.activeElement === document.body) {
    try {
      searchInput.focus({ preventScroll: true });
    } catch (e) {
      searchInput.focus();
    }
  }
})();
