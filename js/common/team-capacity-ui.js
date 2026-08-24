/**
 * Manager tools — exclusive step accordion + submit guard (Team page).
 */
(function (global) {
  'use strict';

  /**
   * Keep one capacity step open; reset browser-restored multi-open state.
   * @param {ParentNode|null|undefined} root
   * @param {number} [defaultOpenIndex=0]
   */
  function bindExclusiveAccordion(root, defaultOpenIndex) {
    if (!root || typeof root.querySelectorAll !== 'function') {
      return;
    }
    const panels = root.querySelectorAll('details.crm-team-capacity__panel');
    if (!panels.length) {
      return;
    }
    const openIdx = typeof defaultOpenIndex === 'number' ? defaultOpenIndex : 0;
    panels.forEach(function (panel, idx) {
      panel.open = idx === openIdx;
      panel.addEventListener('toggle', function () {
        if (!panel.open) {
          return;
        }
        panels.forEach(function (other) {
          if (other !== panel) {
            other.open = false;
          }
        });
      });
    });
    root.querySelectorAll('details.crm-team-capacity__options').forEach(function (el) {
      el.open = false;
    });
  }

  /**
   * Prevent double submit while an async handler runs.
   * @param {HTMLFormElement|null|undefined} form
   * @param {() => (void|Promise<void>)} handler
   */
  async function withSubmitGuard(form, handler) {
    if (!form || form.dataset.crmSubmitting === '1') {
      return;
    }
    const btn = form.querySelector('button[type="submit"]');
    form.dataset.crmSubmitting = '1';
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }
    try {
      await handler();
    } finally {
      delete form.dataset.crmSubmitting;
      if (btn) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
      }
    }
  }

  /**
   * OOO cover client validation (mirrors OooLogic rules except overlap).
   * @returns {null|string} error code
   */
  function validateOooCoverInput(input) {
    input = input || {};
    const primary = String(input.primaryUserId || '').trim();
    const backup = String(input.backupUserId || '').trim();
    const startsOn = String(input.startsOn || '').trim();
    const endsOn = String(input.endsOn || '').trim();
    const maxDays = typeof input.maxDays === 'number' ? input.maxDays : 60;
    if (!primary || !backup) {
      return 'missing_people';
    }
    if (primary === backup) {
      return 'same_person';
    }
    if (!startsOn || !endsOn) {
      return 'missing_dates';
    }
    if (endsOn < startsOn) {
      return 'invalid_range';
    }
    const start = new Date(startsOn + 'T12:00:00');
    const end = new Date(endsOn + 'T12:00:00');
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
      return 'missing_dates';
    }
    const diffDays = Math.round((end.getTime() - start.getTime()) / 86400000);
    if (diffDays > maxDays) {
      return 'too_long';
    }
    return null;
  }

  /**
   * Bulk reassign client validation before API call.
   * @returns {null|string} error code
   */
  function validateBulkReassignInput(input) {
    input = input || {};
    const fromUser = String(input.fromUser || '').trim();
    const toUser = String(input.toUser || '').trim();
    const confirmed = !!input.confirmed;
    const entityTypes = Array.isArray(input.entityTypes) ? input.entityTypes : [];
    if (!fromUser || !toUser) {
      return 'missing_people';
    }
    if (fromUser === toUser) {
      return 'same_person';
    }
    if (!confirmed) {
      return 'not_confirmed';
    }
    if (entityTypes.length === 0) {
      return 'nothing_selected';
    }
    return null;
  }

  global.CustomerCheckTeamCapacityUi = {
    bindExclusiveAccordion: bindExclusiveAccordion,
    withSubmitGuard: withSubmitGuard,
    validateOooCoverInput: validateOooCoverInput,
    validateBulkReassignInput: validateBulkReassignInput,
  };
})(typeof window !== 'undefined' ? window : globalThis);
