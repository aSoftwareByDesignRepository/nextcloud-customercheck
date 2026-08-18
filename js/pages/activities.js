(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg) => (window.t ? window.t('customercheck', msg) : msg);
  if (C && C.init) { C.init(); }

  const ListToolbar = window.CustomerCheckListToolbar;
  if (ListToolbar && typeof ListToolbar.bind === 'function') {
    ListToolbar.bind({
      controlsId: 'crm-activities-controls',
      toggleId: 'crm-activities-filter-toggle',
      panelId: 'crm-activities-filter',
    });
  }

  async function markDone(id, done, updatedAt, btn) {
    try {
      const res = await Api.post('/activities/' + id + '/done', { done: done, updatedAt: updatedAt || null });
      const stamp = (res && res.data && res.data.updatedAt) ? String(res.data.updatedAt) : (updatedAt || '');
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = done ? t('Activity marked done.') : t('Activity reopened.');
      patchActivityRow(btn, done, id, stamp);
      if (window.CustomerCheckFilterLive && typeof window.CustomerCheckFilterLive.refresh === 'function') {
        await window.CustomerCheckFilterLive.refresh();
      } else {
        location.reload();
      }
    } catch (e) {
      if (C) await C.alertDialog({ title: t('Update failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    }
  }

  /**
   * Keep the row honest if the fragment refresh fails (no location.assign after a write).
   *
   * @param {Element|null} btn
   * @param {boolean} done
   * @param {string} id
   * @param {string} updatedAt
   */
  function patchActivityRow(btn, done, id, updatedAt) {
    if (!btn || typeof btn.closest !== 'function') {
      return;
    }
    const row = btn.closest('tr');
    if (!row) {
      return;
    }
    const scopeEl = document.getElementById('crm-filter-scope');
    const scope = scopeEl ? String(scopeEl.value || 'open') : 'open';
    if (done && (scope === 'open' || scope === '')) {
      if (row.parentNode) {
        row.parentNode.removeChild(row);
      }
      return;
    }
    if (!done && scope === 'done') {
      if (row.parentNode) {
        row.parentNode.removeChild(row);
      }
      return;
    }
    const actions = row.querySelector('.crm-table-actions');
    if (!actions) {
      return;
    }
    const old = actions.querySelector('[data-crm-complete-activity], [data-crm-undo-activity]');
    const next = document.createElement('button');
    next.type = 'button';
    next.className = done ? 'crm-btn crm-btn--sm crm-btn--ghost' : 'crm-btn crm-btn--sm crm-btn--secondary';
    if (done) {
      next.setAttribute('data-crm-undo-activity', String(id || ''));
      next.textContent = t('Undo');
    } else {
      next.setAttribute('data-crm-complete-activity', String(id || ''));
      next.textContent = t('Done');
    }
    if (updatedAt) {
      next.setAttribute('data-updated-at', updatedAt);
    }
    if (old && old.parentNode) {
      old.parentNode.replaceChild(next, old);
    } else {
      actions.appendChild(next);
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
    await markDone(id, !!complete, btn.getAttribute('data-updated-at'), btn);
    btn.disabled = false;
    btn.removeAttribute('aria-busy');
  });
})();
