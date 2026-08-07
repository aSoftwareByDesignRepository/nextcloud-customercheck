/**
 * Workspace capability flags from PageShellTrait → #app-content data-crm-can-*.
 * Shared by persona, company-detail, pipeline, and other mutation UIs.
 */
(function () {
  'use strict';

  function shellCan(flag) {
    const el = document.getElementById('app-content');
    return !!(el && el.getAttribute('data-crm-can-' + String(flag || '')) === '1');
  }

  window.CustomerCheckShellCan = shellCan;
})();
