(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const tr = (text, args) => (window.t ? window.t('customercheck', text, args || []) : text);
  if (C && C.init) { C.init(); }

  const root = document.getElementById('crm-settings-root');
  if (!root) return;
  const canManage = root.getAttribute('data-can-manage') === '1';
  const canManageWorkspace = root.getAttribute('data-can-manage-workspace') === '1' || canManage;

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
    li.innerHTML = '<span></span><button type="button" class="crm-chip__remove" aria-label="' + tr('Remove') + '">×</button>';
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
      if (C) C.announce && C.announce(tr('Copied'));
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
        title: tr('Rotate token'),
        message: tr('The old URL will stop working immediately (HTTP 410). Continue?'),
        confirmLabel: tr('Rotate'),
        cancelLabel: tr('Cancel'),
        variant: 'warning',
      });
      if (!ok || !ok.confirmed) return;
      try {
        await Api.post('/settings/tokens/' + kind + '/rotate', {});
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: tr('Rotate failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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
        await C.alertDialog({ title: tr('Could not save'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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
        if (C && C.announce) C.announce(tr('Preference saved'));
      } catch (e) {
        await C.alertDialog({ title: tr('Could not save'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  // ── Mobile app license (CUC2) ──────────────────────────────────────────
  const licenseForm = document.getElementById('crm-license-form');
  const licenseError = document.getElementById('crm-license-error');

  function showInlineError(el, message) {
    if (!el) return;
    el.textContent = message || '';
    el.hidden = !message;
  }

  if (licenseForm && canManage) {
    licenseForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      showInlineError(licenseError, '');
      const keyField = document.getElementById('crm-license-key');
      const key = (keyField && keyField.value ? keyField.value : '').trim();
      if (key === '') {
        showInlineError(licenseError, tr('Paste the license key first.'));
        if (keyField) keyField.focus();
        return;
      }
      try {
        await Api.post('/license', { key });
        if (C && C.announce) C.announce(tr('License saved'));
        location.reload();
      } catch (e) {
        showInlineError(licenseError, (e.payload && e.payload.message) || e.message);
        if (keyField) keyField.focus();
      }
    });
  }

  const licenseRemove = document.getElementById('crm-license-remove');
  if (licenseRemove && canManage) {
    licenseRemove.addEventListener('click', async () => {
      const ok = await C.confirmDialog({
        title: tr('Remove license'),
        message: tr('The mobile app stops working for everyone and all seat assignments are cleared. The web app is not affected. Continue?'),
        confirmLabel: tr('Remove license'),
        cancelLabel: tr('Cancel'),
        variant: 'danger',
      });
      if (!ok || !ok.confirmed) return;
      try {
        await Api.del('/license');
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: tr('Could not remove'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const seatAdd = document.getElementById('crm-license-seat-add');
  const seatUser = document.getElementById('crm-license-seat-user');
  const seatError = document.getElementById('crm-license-seat-error');
  const seatList = document.getElementById('crm-license-seat-list');

  async function assignSeat() {
    showInlineError(seatError, '');
    const userId = (seatUser && seatUser.value ? seatUser.value : '').trim();
    if (userId === '') {
      showInlineError(seatError, tr('Enter the Nextcloud user id first.'));
      if (seatUser) seatUser.focus();
      return;
    }
    try {
      await Api.post('/license/seats', { userId });
      location.reload();
    } catch (e) {
      showInlineError(seatError, (e.payload && e.payload.message) || e.message);
      if (seatUser) seatUser.focus();
    }
  }

  if (seatAdd && canManage) {
    seatAdd.addEventListener('click', assignSeat);
  }
  if (seatUser && canManage) {
    seatUser.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        assignSeat();
      }
    });
  }

  if (seatList && canManage) {
    seatList.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('[data-remove-seat]');
      if (!btn) return;
      const uid = btn.getAttribute('data-remove-seat');
      const ok = await C.confirmDialog({
        title: tr('Remove mobile seat'),
        message: tr('The mobile app stops working for this colleague immediately. Remove the seat?'),
        confirmLabel: tr('Remove seat'),
        cancelLabel: tr('Cancel'),
        variant: 'warning',
      });
      if (!ok || !ok.confirmed) return;
      try {
        await Api.del('/license/seats/' + encodeURIComponent(uid));
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: tr('Could not remove seat'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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
        await C.alertDialog({ title: tr('Could not save'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  // Agreement catalogue (M1 S4)
  const codesList = document.getElementById('crm-product-codes-list');
  const codesStatus = document.getElementById('crm-product-codes-status');
  const codesSave = document.getElementById('crm-product-codes-save');
  let customCodes = [];

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[char]));
  }

  function renderCustomEditor(row) {
    return '<div class="crm-product-code-editor" data-custom-code="' + escapeHtml(row.code) + '">' +
      '<label class="form-label" for="crm-product-code-label-' + escapeHtml(row.code) + '">' + tr('Label') + '</label>' +
      '<input id="crm-product-code-label-' + escapeHtml(row.code) + '" class="form-input crm-input" data-field="label" value="' + escapeHtml(row.label || row.code) + '" maxlength="80" />' +
      '<label class="form-label" for="crm-product-code-kind-' + escapeHtml(row.code) + '">' + tr('Kind') + '</label>' +
      '<select id="crm-product-code-kind-' + escapeHtml(row.code) + '" class="form-select crm-input" data-field="kind">' +
      '<option value="onetime"' + (row.kind === 'onetime' ? ' selected' : '') + '>' + tr('One-time') + '</option>' +
      '<option value="license"' + (row.kind === 'license' ? ' selected' : '') + '>' + tr('License') + '</option>' +
      '<option value="partner"' + (row.kind === 'partner' ? ' selected' : '') + '>' + tr('Partner') + '</option>' +
      '</select>' +
      '<label class="crm-switch"><input type="checkbox" data-field="active"' + (row.active ? ' checked' : '') + '><span>' + tr('Active') + '</span></label>' +
      '</div>';
  }

  function collectCustomCodesFromDom() {
    if (!codesList) return customCodes.slice();
    return Array.from(codesList.querySelectorAll('[data-custom-code]')).map((item) => ({
      code: item.getAttribute('data-custom-code'),
      label: (item.querySelector('[data-field="label"]') || {}).value || item.getAttribute('data-custom-code'),
      kind: (item.querySelector('[data-field="kind"]') || {}).value || 'onetime',
      active: !!((item.querySelector('[data-field="active"]') || {}).checked),
    }));
  }

  async function refreshProductCodes() {
    if (!codesList) return;
    try {
      const res = await Api.get('/settings/product-codes');
      const rows = res.data || [];
      customCodes = rows.filter((r) => !r.builtin);
      codesList.innerHTML = '';
      rows.forEach((row) => {
        const li = document.createElement('li');
        const badge = row.builtin ? tr('built-in') : (row.active ? tr('custom') : tr('inactive'));
        li.innerHTML = '<code>' + escapeHtml(row.code) + '</code> · '
          + escapeHtml(row.label || row.code)
          + ' · <span class="crm-badge">' + escapeHtml(row.kind || 'onetime') + '</span>'
          + ' · <span class="crm-badge">' + badge + '</span>';
        if (!row.builtin && canManage) {
          li.insertAdjacentHTML('beforeend', renderCustomEditor(row));
        }
        if (!row.builtin && canManage) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'crm-btn crm-btn--ghost crm-btn--small';
          btn.textContent = tr('Remove');
          btn.setAttribute('aria-label', tr('Remove custom code') + ' ' + row.code);
          btn.addEventListener('click', async () => {
            const next = customCodes.filter((c) => c.code !== row.code).map((c) => ({
              code: c.code, label: c.label, kind: c.kind, active: c.active,
            }));
            try {
              await Api.put('/settings/product-codes', { custom: next });
              await refreshProductCodes();
            } catch (e) {
              await C.alertDialog({ title: tr('Could not update catalogue'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
            }
          });
          li.appendChild(document.createTextNode(' '));
          li.appendChild(btn);
        }
        codesList.appendChild(li);
      });
      if (codesStatus) {
        codesStatus.textContent = tr('%s product codes available for agreements.', [String(rows.length)]);
      }
    } catch (e) {
      if (codesStatus) {
        codesStatus.textContent = (e.payload && e.payload.message) || e.message || tr('Could not load catalogue.');
      }
    }
  }

  refreshProductCodes();

  const codesForm = document.getElementById('crm-product-codes-form');
  if (codesForm && canManage) {
    codesForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const code = (document.getElementById('crm-product-code-new') || {}).value || '';
      const label = (document.getElementById('crm-product-code-label') || {}).value || '';
      const kind = (document.getElementById('crm-product-code-kind') || {}).value || 'onetime';
      const active = !!((document.getElementById('crm-product-code-active') || {}).checked);
      const err = document.getElementById('crm-product-codes-error');
      if (err) { err.hidden = true; err.textContent = ''; }
      const next = collectCustomCodesFromDom().map((c) => ({
        code: c.code, label: c.label, kind: c.kind, active: c.active,
      }));
      next.push({ code: String(code).trim().toLowerCase(), label: String(label).trim() || String(code).trim(), kind: String(kind), active });
      try {
        await Api.put('/settings/product-codes', { custom: next });
        document.getElementById('crm-product-code-new').value = '';
        document.getElementById('crm-product-code-label').value = '';
        document.getElementById('crm-product-code-kind').value = 'onetime';
        document.getElementById('crm-product-code-active').checked = true;
        await refreshProductCodes();
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = tr('Custom product code added.');
      } catch (e) {
        if (err) {
          err.hidden = false;
          err.textContent = (e.payload && e.payload.message) || e.message;
        }
      }
    });
  }

  if (codesSave && canManage) {
    codesSave.addEventListener('click', async () => {
      const err = document.getElementById('crm-product-codes-error');
      if (err) { err.hidden = true; err.textContent = ''; }
      try {
        await Api.put('/settings/product-codes', { custom: collectCustomCodesFromDom() });
        await refreshProductCodes();
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = tr('Agreement catalogue saved.');
      } catch (e) {
        if (err) {
          err.hidden = false;
          err.textContent = (e.payload && e.payload.message) || e.message;
        }
      }
    });
  }

  // ── Workspace & mailbox (managers / app admins) ───────────────────────
  let currentWorkspaceId = 0;
  const wsError = document.getElementById('crm-ws-error');
  const wsStatus = document.getElementById('crm-ws-status');
  const wsCurrent = document.getElementById('crm-ws-current');
  const wsCurrentLabel = document.getElementById('crm-ws-current-label');
  const wsMembers = document.getElementById('crm-ws-members');

  function showWsError(message) {
    if (!wsError) return;
    wsError.textContent = message || '';
    wsError.hidden = !message;
  }

  function mailboxPayloadFromForm() {
    const foldersRaw = (document.getElementById('crm-mbox-folders') || {}).value || '';
    const folders = String(foldersRaw).split(/\n/).map((s) => s.trim()).filter(Boolean);
    return {
      label: (document.getElementById('crm-mbox-label') || {}).value || '',
      emailAddress: (document.getElementById('crm-mbox-email') || {}).value || '',
      email: (document.getElementById('crm-mbox-email') || {}).value || '',
      imapHost: (document.getElementById('crm-mbox-imap-host') || {}).value || '',
      imapPort: parseInt(String((document.getElementById('crm-mbox-imap-port') || {}).value || '993'), 10),
      smtpHost: (document.getElementById('crm-mbox-smtp-host') || {}).value || '',
      smtpPort: parseInt(String((document.getElementById('crm-mbox-smtp-port') || {}).value || '587'), 10),
      username: (document.getElementById('crm-mbox-user') || {}).value || '',
      password: (document.getElementById('crm-mbox-pass') || {}).value || '',
      folders: folders,
      dsgvoAck: !!(document.getElementById('crm-mbox-dsgvo') || {}).checked,
    };
  }

  async function refreshWorkspaceMembers() {
    if (!wsMembers || !currentWorkspaceId) return;
    wsMembers.innerHTML = '';
    try {
      const res = await Api.get('/workspaces/' + currentWorkspaceId + '/members');
      const members = (res.data && (res.data.members || res.data.items)) || res.data || [];
      const list = Array.isArray(members) ? members : [];
      if (!list.length) {
        const li = document.createElement('li');
        li.className = 'crm-muted';
        li.textContent = tr('No members yet.');
        wsMembers.appendChild(li);
        return;
      }
      list.forEach((m) => {
        const uid = m.userId || m.id || '';
        const li = document.createElement('li');
        li.className = 'crm-ws-members__item';
        li.innerHTML = '<span><strong></strong> · <span class="crm-badge"></span>' +
          (m.canMailSend || m.can_mail_send ? ' · ' + tr('mail send') : '') + '</span>';
        li.querySelector('strong').textContent = uid;
        li.querySelector('.crm-badge').textContent = m.role || 'member';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'crm-btn crm-btn--ghost';
        btn.textContent = tr('Remove');
        btn.setAttribute('aria-label', tr('Remove member') + ' ' + uid);
        btn.addEventListener('click', async () => {
          try {
            await Api.del('/workspaces/' + currentWorkspaceId + '/members/' + encodeURIComponent(uid));
            await refreshWorkspaceMembers();
          } catch (e) {
            showWsError((e.payload && e.payload.message) || e.message);
          }
        });
        li.appendChild(btn);
        wsMembers.appendChild(li);
      });
    } catch (e) {
      showWsError((e.payload && e.payload.message) || e.message || tr('Could not load members.'));
    }
  }

  async function refreshWorkspaceSettings() {
    if (!currentWorkspaceId) return;
    try {
      const res = await Api.get('/workspaces/' + currentWorkspaceId + '/settings');
      const s = res.data || {};
      const wip = document.getElementById('crm-ws-wip');
      const digest = document.getElementById('crm-ws-digest');
      if (wip) wip.value = String(s.wipLimit != null ? s.wipLimit : 5);
      if (digest) digest.checked = !!s.digestEnabled;
    } catch (e) {
      showWsError((e.payload && e.payload.message) || e.message || tr('Could not load workspace settings.'));
    }
  }

  async function bootWorkspaceSection() {
    if (!canManageWorkspace || !document.getElementById('section-workspace-mailbox')) return;
    showWsError('');
    try {
      const res = await Api.get('/workspaces');
      const data = res.data || {};
      const current = data.current || {};
      currentWorkspaceId = parseInt(String(current.id || 0), 10);
      if (wsStatus) {
        wsStatus.textContent = currentWorkspaceId
          ? tr('Working in workspace %s.', [current.name || ('#' + currentWorkspaceId)])
          : tr('No current workspace resolved yet.');
      }
      if (wsCurrent) wsCurrent.hidden = !currentWorkspaceId;
      if (wsCurrentLabel && currentWorkspaceId) {
        wsCurrentLabel.textContent = (current.name || '') + ' · #' + currentWorkspaceId +
          (current.role ? ' · ' + current.role : '');
      }
      if (currentWorkspaceId) {
        await refreshWorkspaceSettings();
        await refreshWorkspaceMembers();
      }
    } catch (e) {
      if (wsStatus) wsStatus.textContent = '';
      showWsError((e.payload && e.payload.message) || e.message || tr('Could not load workspaces.'));
    }
  }

  const wsCreate = document.getElementById('crm-ws-create-form');
  if (wsCreate && canManage) {
    wsCreate.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      showWsError('');
      const name = (document.getElementById('crm-ws-name') || {}).value || '';
      const slug = (document.getElementById('crm-ws-slug') || {}).value || '';
      try {
        await Api.post('/workspaces', { name: String(name).trim(), slug: String(slug).trim() });
        location.reload();
      } catch (e) {
        showWsError((e.payload && e.payload.message) || e.message || tr('Could not create workspace.'));
      }
    });
  }

  const wsSettingsForm = document.getElementById('crm-ws-settings-form');
  if (wsSettingsForm && canManageWorkspace) {
    wsSettingsForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!currentWorkspaceId) return;
      showWsError('');
      const wip = parseInt(String((document.getElementById('crm-ws-wip') || {}).value || '5'), 10);
      const digestEnabled = !!(document.getElementById('crm-ws-digest') || {}).checked;
      try {
        await Api.put('/workspaces/' + currentWorkspaceId + '/settings', {
          wipLimit: wip,
          digestEnabled: digestEnabled,
        });
        if (C && C.announce) C.announce(tr('Workspace settings saved'));
      } catch (e) {
        showWsError((e.payload && e.payload.message) || e.message);
      }
    });
  }

  const wsMemberForm = document.getElementById('crm-ws-member-form');
  if (wsMemberForm && canManageWorkspace) {
    wsMemberForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!currentWorkspaceId) return;
      showWsError('');
      const userId = String((document.getElementById('crm-ws-member-uid') || {}).value || '').trim();
      const role = String((document.getElementById('crm-ws-member-role') || {}).value || 'member');
      const canMailSend = !!(document.getElementById('crm-ws-member-mail') || {}).checked;
      try {
        await Api.post('/workspaces/' + currentWorkspaceId + '/members', {
          userId: userId,
          role: role,
          can_mail_send: canMailSend,
          canMailSend: canMailSend,
        });
        document.getElementById('crm-ws-member-uid').value = '';
        await refreshWorkspaceMembers();
      } catch (e) {
        showWsError((e.payload && e.payload.message) || e.message);
      }
    });
  }

  let mailboxTestOk = false;
  const mailboxTest = document.getElementById('crm-mailbox-test');
  const mailboxSave = document.getElementById('crm-mailbox-save');
  const mailboxForm = document.getElementById('crm-mailbox-form');
  const mailboxErr = document.getElementById('crm-mailbox-error');
  const mailboxTestStatus = document.getElementById('crm-mailbox-test-status');
  const foldersWrap = document.getElementById('crm-mailbox-folders-wrap');

  if (mailboxTest && canManageWorkspace) {
    mailboxTest.addEventListener('click', async () => {
      if (!currentWorkspaceId) return;
      showInlineError(mailboxErr, '');
      mailboxTestOk = false;
      if (mailboxSave) mailboxSave.disabled = true;
      const payload = mailboxPayloadFromForm();
      try {
        const res = await Api.post('/workspaces/' + currentWorkspaceId + '/mailboxes/test', payload);
        const data = res.data || {};
        if (mailboxTestStatus) {
          mailboxTestStatus.textContent = data.message || (data.ok ? tr('IMAP connection OK.') : tr('IMAP connection failed.'));
        }
        if (data.ok) {
          mailboxTestOk = true;
          if (mailboxSave) mailboxSave.disabled = false;
          if (foldersWrap) foldersWrap.hidden = false;
          const folderField = document.getElementById('crm-mbox-folders');
          if (folderField && Array.isArray(data.folders) && data.folders.length && !folderField.value.trim()) {
            folderField.value = data.folders.join('\n');
          } else if (folderField && !folderField.value.trim()) {
            folderField.value = 'INBOX';
          }
        }
      } catch (e) {
        showInlineError(mailboxErr, (e.payload && e.payload.message) || e.message);
        if (mailboxTestStatus) mailboxTestStatus.textContent = '';
      }
    });
  }

  if (mailboxForm && canManageWorkspace) {
    mailboxForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!currentWorkspaceId || !mailboxTestOk) {
        showInlineError(mailboxErr, tr('Test the connection successfully before saving.'));
        return;
      }
      showInlineError(mailboxErr, '');
      const payload = mailboxPayloadFromForm();
      if (!payload.dsgvoAck) {
        showInlineError(mailboxErr, tr('Confirm the shared-mailbox DSGVO acknowledgement.'));
        return;
      }
      try {
        await Api.post('/workspaces/' + currentWorkspaceId + '/mailboxes', payload);
        if (C && C.announce) C.announce(tr('Mailbox saved'));
        location.reload();
      } catch (e) {
        showInlineError(mailboxErr, (e.payload && e.payload.message) || e.message);
      }
    });
  }

  bootWorkspaceSection();

  async function loadCirclesRollCall() {
    const mount = document.getElementById('crm-circles-roll-call');
    if (!mount || !canManageWorkspace) return;
    try {
      const res = await Api.get('/circles-roll-call');
      const items = (res.data && res.data.items) || res.items || [];
      mount.removeAttribute('aria-busy');
      if (!items.length) {
        mount.innerHTML = '<p class="crm-empty" role="status">' + escapeHtml(tr('No workspaces yet.')) + '</p>';
        return;
      }
      let html = '<div class="table-container crm-table--responsive"><table class="table table--hover" aria-label="' +
        escapeHtml(tr('Circles roll-call')) + '"><thead><tr>';
      html += '<th scope="col">' + escapeHtml(tr('Workspace')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('Week')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('WIP')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('Committed')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('Done')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('Missed')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('Completeness')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('Confidence')) + '</th>';
      html += '</tr></thead><tbody>';
      items.forEach((it) => {
        html += '<tr>';
        html += '<td data-label="' + escapeHtml(tr('Workspace')) + '">' + escapeHtml(it.name || '') + '</td>';
        html += '<td data-label="' + escapeHtml(tr('Week')) + '">' + escapeHtml(it.weekKey || '') + '</td>';
        html += '<td data-label="' + escapeHtml(tr('WIP')) + '">' + escapeHtml(String(it.wipUsed || 0) + '/' + String(it.wipLimit || 5)) + '</td>';
        html += '<td data-label="' + escapeHtml(tr('Committed')) + '">' + escapeHtml(String(it.committed || 0)) + '</td>';
        html += '<td data-label="' + escapeHtml(tr('Done')) + '">' + escapeHtml(String(it.done || 0)) + '</td>';
        html += '<td data-label="' + escapeHtml(tr('Missed')) + '">' + escapeHtml(String(it.missed || 0)) + '</td>';
        html += '<td data-label="' + escapeHtml(tr('Completeness')) + '">' + escapeHtml(String(Math.round((it.completeness || 0) * 100)) + '%') + '</td>';
        html += '<td data-label="' + escapeHtml(tr('Confidence')) + '"><span class="crm-badge">' + escapeHtml(it.confidence || 'unknown') + '</span></td>';
        html += '</tr>';
      });
      html += '</tbody></table></div>';
      mount.innerHTML = html;
    } catch (e) {
      mount.removeAttribute('aria-busy');
      mount.innerHTML = '<p role="alert">' + escapeHtml((e.payload && e.payload.message) || e.message || tr('Could not load Circles roll-call.')) + '</p>';
    }
  }

  loadCirclesRollCall();
})();
