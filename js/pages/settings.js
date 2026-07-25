(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  if (C && C.init) { C.init(); }

  const root = document.getElementById('crm-settings-root');
  if (!root) return;
  const canManage = root.getAttribute('data-can-manage') === '1';

  function checkedValues(selector) {
    return Array.from(document.querySelectorAll(selector + ':checked')).map((el) => el.value);
  }

  function filterList(listId, query) {
    const q = (query || '').trim().toLowerCase();
    const list = document.getElementById(listId);
    if (!list) return;
    list.querySelectorAll('.access-groups-item').forEach((item) => {
      const hay = item.getAttribute('data-search') || '';
      item.hidden = q !== '' && !hay.includes(q);
    });
  }

  function bindSearch(inputId, listId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('input', () => filterList(listId, input.value));
  }

  bindSearch('crm-app-admins-search', 'crm-app-admins-list');
  bindSearch('crm-allowed-groups-search', 'crm-allowed-groups-list');

  const restriction = document.getElementById('crm-access-restriction');
  const confirmWrap = document.getElementById('crm-open-access-confirm-wrap');
  const confirmOpen = document.getElementById('crm-confirm-open-access');
  if (restriction && confirmWrap) {
    restriction.addEventListener('change', () => {
      const on = restriction.checked;
      confirmWrap.hidden = on;
      if (on && confirmOpen) confirmOpen.checked = false;
    });
  }

  // Allowed users chips
  const chipList = document.getElementById('crm-allowed-users-chips');
  const suggest = document.getElementById('crm-allowed-users-suggest');
  const userSearch = document.getElementById('crm-allowed-users-search');
  let searchTimer = null;

  function selectedUids() {
    if (!chipList) return [];
    return Array.from(chipList.querySelectorAll('[data-uid]')).map((el) => el.getAttribute('data-uid'));
  }

  function renderChip(user) {
    if (!chipList || selectedUids().includes(user.id)) return;
    const li = document.createElement('li');
    li.className = 'crm-chip';
    li.setAttribute('data-uid', user.id);
    li.innerHTML = '<span></span><button type="button" class="crm-chip__remove" aria-label="Remove">×</button>';
    li.querySelector('span').textContent = user.displayName || user.id;
    li.querySelector('button').setAttribute('data-remove-uid', user.id);
    chipList.appendChild(li);
  }

  if (chipList) {
    chipList.addEventListener('click', (ev) => {
      const btn = ev.target.closest('[data-remove-uid]');
      if (!btn) return;
      const uid = btn.getAttribute('data-remove-uid');
      const chip = chipList.querySelector('[data-uid="' + CSS.escape(uid) + '"]');
      if (chip) chip.remove();
    });
  }

  if (userSearch && suggest && canManage) {
    userSearch.addEventListener('input', () => {
      clearTimeout(searchTimer);
      const q = userSearch.value.trim();
      if (q.length < 2) {
        suggest.hidden = true;
        suggest.innerHTML = '';
        return;
      }
      searchTimer = setTimeout(async () => {
        try {
          const res = await Api.get('/settings/users?q=' + encodeURIComponent(q));
          const users = (res.data && res.data.users) || [];
          suggest.innerHTML = '';
          users.forEach((u) => {
            const opt = document.createElement('button');
            opt.type = 'button';
            opt.className = 'crm-suggest__item';
            opt.setAttribute('role', 'option');
            opt.textContent = (u.displayName || u.id) + ' (' + u.id + ')';
            opt.addEventListener('click', () => {
              renderChip(u);
              suggest.hidden = true;
              userSearch.value = '';
            });
            suggest.appendChild(opt);
          });
          suggest.hidden = users.length === 0;
        } catch (e) {
          suggest.hidden = true;
        }
      }, 250);
    });
  }

  async function copyField(id) {
    const el = document.getElementById(id);
    if (!el) return;
    try {
      await navigator.clipboard.writeText(el.value);
      if (C) C.announce && C.announce('Copied');
    } catch (e) {
      el.select();
      document.execCommand('copy');
    }
  }

  document.querySelectorAll('[data-copy-target]').forEach((btn) => {
    btn.addEventListener('click', () => copyField(btn.getAttribute('data-copy-target')));
  });

  document.querySelectorAll('[data-rotate-token]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const kind = btn.getAttribute('data-rotate-token');
      const ok = await C.confirmDialog({
        title: 'Rotate token',
        message: 'The old URL will stop working immediately (HTTP 410). Continue?',
        confirmLabel: 'Rotate',
        cancelLabel: 'Cancel',
        variant: 'warning',
      });
      if (!ok || !ok.confirmed) return;
      try {
        await Api.post('/settings/tokens/' + kind + '/rotate', {});
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: 'Rotate failed', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  const accessForm = document.getElementById('crm-settings-access-form');
  if (accessForm) {
    accessForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const restrictionOn = !!(restriction && restriction.checked);
      const payload = {
        app_admin_user_ids: checkedValues('#crm-app-admins-list input[type="checkbox"]'),
        access_restriction_enabled: restrictionOn,
        access_allowed_group_ids: checkedValues('#crm-allowed-groups-list input[type="checkbox"]'),
        access_allowed_user_ids: selectedUids(),
      };
      if (!restrictionOn) {
        payload.confirmOpenAccess = !!(confirmOpen && confirmOpen.checked);
      }
      try {
        await Api.put('/settings', payload);
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: 'Could not save', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const meForm = document.getElementById('crm-settings-me-form');
  if (meForm) {
    meForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const view = document.getElementById('crm-pipeline-view').value;
      try {
        await Api.put('/settings/me', { pipelineView: view });
        if (C && C.announce) C.announce('Preference saved');
      } catch (e) {
        await C.alertDialog({ title: 'Could not save', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const jobsForm = document.getElementById('crm-settings-jobs-form');
  if (jobsForm) {
    jobsForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const fd = new FormData(jobsForm);
      const payload = {
        cadence_enabled: fd.get('cadence_enabled') === '1',
        reminder_hour: parseInt(String(fd.get('reminder_hour') || '5'), 10),
        digest_enabled: fd.get('digest_enabled') === '1',
        digest_weekday: parseInt(String(fd.get('digest_weekday') || '1'), 10),
        digest_hour: parseInt(String(fd.get('digest_hour') || '7'), 10),
        stale_deal_days: parseInt(String(fd.get('stale_deal_days') || '14'), 10),
        mail_related_enabled: fd.get('mail_related_enabled') === '1',
      };
      try {
        await Api.put('/settings', payload);
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: 'Could not save', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }
})();
