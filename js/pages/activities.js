(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg) => (window.t ? window.t('customercheck', msg) : msg);
  if (C && C.init) { C.init(); }

  const toggle = document.getElementById('crm-activities-filter-toggle');
  const panel = document.getElementById('crm-activities-filter');
  if (toggle && panel) {
    toggle.addEventListener('click', () => {
      const open = panel.hasAttribute('hidden');
      if (open) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  async function markDone(id, done, updatedAt) {
    try {
      await Api.post('/activities/' + id + '/done', { done: done, updatedAt: updatedAt || null });
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = done ? t('Activity marked done.') : t('Activity reopened.');
      location.reload();
    } catch (e) {
      if (C) await C.alertDialog({ title: t('Update failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    }
  }

  document.addEventListener('click', async (ev) => {
    const target = ev.target;
    if (!target || typeof target.closest !== 'function') {
      return;
    }
    const complete = target.closest('[data-crm-complete-activity]');
    const undo = target.closest('[data-crm-undo-activity]');
    const btn = complete || undo;
    if (!btn) {
      return;
    }
    ev.preventDefault();
    if (btn.disabled || btn.getAttribute('aria-busy') === 'true') {
      return;
    }
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    const id = complete
      ? btn.getAttribute('data-crm-complete-activity')
      : btn.getAttribute('data-crm-undo-activity');
    await markDone(id, !!complete, btn.getAttribute('data-updated-at'));
    btn.disabled = false;
    btn.removeAttribute('aria-busy');
  });
})();
