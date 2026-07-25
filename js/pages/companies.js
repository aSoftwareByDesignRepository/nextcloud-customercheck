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
})();
