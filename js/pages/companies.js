(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  if (C && C.init) { C.init(); }

  const ListToolbar = window.CustomerCheckListToolbar;
  if (ListToolbar && typeof ListToolbar.bind === 'function') {
    ListToolbar.bind({
      controlsId: 'crm-companies-controls',
      toggleId: 'crm-companies-filter-toggle',
      panelId: 'crm-companies-filter',
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
