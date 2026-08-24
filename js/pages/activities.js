(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg, args) => (window.t ? window.t('customercheck', msg, args) : msg);
  if (C && C.init) { C.init(); }

  const ListToolbar = window.CustomerCheckListToolbar;
  if (ListToolbar && typeof ListToolbar.bind === 'function') {
    ListToolbar.bind({
      controlsId: 'crm-activities-controls',
      toggleId: 'crm-activities-filter-toggle',
      panelId: 'crm-activities-filter',
    });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function workspaceId() {
    const stack = document.querySelector('[data-workspace-id]');
    const raw = stack ? stack.getAttribute('data-workspace-id') : '';
    const n = parseInt(String(raw || '0'), 10);
    return Number.isFinite(n) && n > 0 ? n : 0;
  }

  async function loadMemberOptions() {
    const opts = [{ value: '', label: t('Anyone (shared queue)') }];
    const wsId = workspaceId();
    if (!(wsId > 0) || !Api) {
      return opts;
    }
    try {
      const res = await Api.get('/workspaces/' + wsId + '/members');
      const raw = (res && res.data) || [];
      const rows = Array.isArray(raw) ? raw : (raw.members || raw.items || []);
      (Array.isArray(rows) ? rows : []).forEach((m) => {
        const uid = String(m.userId || m.uid || m.id || '').trim();
        if (!uid) return;
        const name = String(m.displayName || m.name || uid).trim();
        opts.push({ value: uid, label: name + (name !== uid ? ' (' + uid + ')' : '') });
      });
    } catch (e) {
      /* keep Anyone only */
    }
    return opts;
  }

  function displayWho(opts, uid) {
    const next = String(uid || '');
    if (next === '') {
      return t('Anyone');
    }
    const match = opts.find((o) => String(o.value) === next);
    if (!match) {
      return next;
    }
    return String(match.label).replace(/\s*\([^)]*\)\s*$/, '');
  }

  async function assignActivity(btn) {
    if (!C || !Api) {
      return;
    }
    const id = btn.getAttribute('data-crm-assign-activity');
    const updatedAt = btn.getAttribute('data-updated-at') || '';
    const current = btn.getAttribute('data-assignee-user-id') || '';
    const opts = await loadMemberOptions();
    const dlgId = 'crm-assign-activity-dlg';
    const selectId = 'crm-assign-activity-select';
    const optionsHtml = opts.map((o) => {
      const sel = String(o.value) === String(current) ? ' selected' : '';
      return '<option value="' + escapeHtml(o.value) + '"' + sel + '>' + escapeHtml(o.label) + '</option>';
    }).join('');
    const body =
      '<form id="' + dlgId + '-form" class="crm-form crm-form--stack">' +
      '<p class="form-help">' + escapeHtml(t('Pick who should do this. They get a notification.')) + '</p>' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + selectId + '">' + escapeHtml(t('Who should do this?')) + '</label>' +
      '<select class="crm-input form-select" id="' + selectId + '" name="assigneeUserId">' + optionsHtml + '</select>' +
      '</div>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save')) + '</button> ' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button>' +
      '</div></form>';

    await new Promise((resolve) => {
      C.openDialog({
        id: dlgId,
        title: t('Assign to-do'),
        content: body,
        size: 'sm',
        onClose: () => resolve(null),
      });
      const form = document.getElementById(dlgId + '-form');
      if (!form) {
        resolve(null);
        return;
      }
      const close = () => C.closeModal(document.getElementById(dlgId));
      const cancel = form.querySelector('[data-cancel]');
      if (cancel) {
        cancel.addEventListener('click', () => {
          close();
          resolve(null);
        });
      }
      const select = form.querySelector('#' + selectId);
      if (select && typeof select.focus === 'function') {
        setTimeout(() => select.focus(), 50);
      }
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const next = select ? String(select.value || '') : '';
        try {
          const res = await Api.put('/activities/' + id, {
            assigneeUserId: next === '' ? null : next,
            updatedAt: updatedAt || null,
          });
          const stamp = (res && res.data && res.data.updatedAt) ? String(res.data.updatedAt) : updatedAt;
          const live = document.getElementById('crm-live-region');
          if (live) live.textContent = t('Assignment saved.');
          const row = btn.closest('tr');
          if (row) {
            row.setAttribute('data-assignee-user-id', next);
            row.setAttribute('data-updated-at', stamp);
            const whoCell = row.querySelector('.crm-activity-who');
            if (whoCell) {
              whoCell.textContent = displayWho(opts, next);
            }
            btn.setAttribute('data-updated-at', stamp);
            btn.setAttribute('data-assignee-user-id', next);
            const doneBtn = row.querySelector('[data-crm-complete-activity]');
            if (doneBtn) doneBtn.setAttribute('data-updated-at', stamp);
          }
          close();
          if (window.CustomerCheckFilterLive && typeof window.CustomerCheckFilterLive.refresh === 'function') {
            await window.CustomerCheckFilterLive.refresh();
          }
          resolve(true);
        } catch (e) {
          await C.alertDialog({
            title: t('Could not assign'),
            message: (e.payload && e.payload.message) || e.message,
            variant: 'danger',
          });
          resolve(null);
        }
      });
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
    if (done && (scope === 'open' || scope === '' || scope === 'mine')) {
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
    const assign = target.closest('[data-crm-assign-activity]');
    if (assign) {
      ev.preventDefault();
      if (assign.disabled || assign.getAttribute('aria-busy') === 'true') {
        return;
      }
      assign.disabled = true;
      assign.setAttribute('aria-busy', 'true');
      await assignActivity(assign);
      assign.disabled = false;
      assign.removeAttribute('aria-busy');
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
