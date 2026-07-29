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

  document.querySelectorAll('[data-crm-complete-activity]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      await markDone(btn.getAttribute('data-crm-complete-activity'), true, btn.getAttribute('data-updated-at'));
    });
  });

  document.querySelectorAll('[data-crm-undo-activity]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      await markDone(btn.getAttribute('data-crm-undo-activity'), false, btn.getAttribute('data-updated-at'));
    });
  });
})();
