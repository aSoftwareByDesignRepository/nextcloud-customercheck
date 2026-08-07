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

	/**
	 * @param {{
	 *   searchId: string,
	 *   suggestId: string,
	 *   hiddenId: string,
	 *   clearId?: string,
	 *   enabled: boolean,
	 *   workspaceIdFn?: () => number,
	 *   excludeUidsFn?: () => string[],
	 * }} opts
	 */
  function bindDirectoryUserPicker(opts) {
    const search = document.getElementById(opts.searchId);
    const suggest = document.getElementById(opts.suggestId);
    const hidden = document.getElementById(opts.hiddenId);
    const clearBtn = opts.clearId ? document.getElementById(opts.clearId) : null;
    if (!search || !suggest || !hidden || !opts.enabled) {
      return {
        selectedUid: () => '',
        clear: () => {},
      };
    }
    let timer = null;
    let searchGeneration = 0;
    const nav = (window.CustomerCheckUserPickerNav && window.CustomerCheckUserPickerNav.bindListboxKeyboard)
      ? window.CustomerCheckUserPickerNav.bindListboxKeyboard(search, suggest)
      : { reset: function () {} };

    function syncClear() {
      if (!clearBtn) return;
      clearBtn.hidden = !hidden.value;
    }

    function clearSelection() {
      hidden.value = '';
      search.value = '';
      suggest.innerHTML = '';
      suggest.hidden = true;
      search.setAttribute('aria-expanded', 'false');
      search.removeAttribute('aria-busy');
      nav.reset();
      syncClear();
    }

    function commitUser(user) {
      hidden.value = String(user.id || '');
      search.value = (user.displayName || user.id || '') + (user.id ? ' (' + user.id + ')' : '');
      suggest.innerHTML = '';
      suggest.hidden = true;
      search.setAttribute('aria-expanded', 'false');
      search.removeAttribute('aria-busy');
      nav.reset();
      syncClear();
    }

    function excludedSet() {
      const set = Object.create(null);
      if (typeof opts.excludeUidsFn === 'function') {
        (opts.excludeUidsFn() || []).forEach((uid) => {
          const id = String(uid || '').trim();
          if (id !== '') set[id] = true;
        });
      }
      return set;
    }

    function showSuggestNote(message, isError) {
      suggest.innerHTML = '';
      const note = document.createElement('li');
      note.className = 'user-picker__item user-picker__item--muted';
      note.setAttribute('role', isError ? 'alert' : 'presentation');
      note.textContent = message;
      suggest.appendChild(note);
      suggest.hidden = false;
      search.setAttribute('aria-expanded', 'true');
      nav.reset();
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        clearSelection();
        search.focus();
      });
    }

    search.addEventListener('input', () => {
      // Typing after a commit invalidates the selection until a new pick.
      if (hidden.value) {
        hidden.value = '';
        syncClear();
      }
      clearTimeout(timer);
      const q = search.value.trim();
      if (q.length < 2) {
        suggest.hidden = true;
        suggest.innerHTML = '';
        search.setAttribute('aria-expanded', 'false');
        search.removeAttribute('aria-busy');
        nav.reset();
        return;
      }
      searchGeneration += 1;
      const myGeneration = searchGeneration;
      timer = setTimeout(async () => {
        search.setAttribute('aria-busy', 'true');
        showSuggestNote(tr('Searching…'), false);
        try {
          let url = '/settings/users?q=' + encodeURIComponent(q);
          if (typeof opts.workspaceIdFn === 'function') {
            const wid = opts.workspaceIdFn();
            if (wid > 0) {
              url += '&workspaceId=' + encodeURIComponent(String(wid));
            }
          }
          const res = await Api.get(url);
          if (myGeneration !== searchGeneration) {
            return;
          }
          search.removeAttribute('aria-busy');
          // Prefer envelope data.users; also accept a bare users array if a proxy changes shape.
          const raw = (res && res.data && Array.isArray(res.data.users))
            ? res.data.users
            : (res && Array.isArray(res.users) ? res.users : []);
          const excluded = excludedSet();
          const users = raw.filter((u) => u && u.id && !excluded[String(u.id)]);
          suggest.innerHTML = '';
          if (users.length === 0) {
            showSuggestNote(tr('No matching users found.'), false);
            return;
          }
          users.forEach((u) => {
            const opt = document.createElement('li');
            opt.className = 'user-picker__item';
            opt.setAttribute('role', 'option');
            opt.setAttribute('aria-selected', 'false');
            opt.tabIndex = -1;
            const name = document.createElement('span');
            name.className = 'user-picker__name';
            name.textContent = u.displayName || u.id;
            const meta = document.createElement('span');
            meta.className = 'user-picker__meta';
            meta.textContent = u.id || '';
            opt.appendChild(name);
            opt.appendChild(meta);
            opt.addEventListener('click', () => commitUser(u));
            suggest.appendChild(opt);
          });
          suggest.hidden = false;
          search.setAttribute('aria-expanded', 'true');
          nav.reset();
        } catch (e) {
          if (myGeneration !== searchGeneration) {
            return;
          }
          search.removeAttribute('aria-busy');
          showSuggestNote(tr('Could not search the directory. Try again.'), true);
        }
      }, 250);
    });

    syncClear();
    return {
      selectedUid: () => String(hidden.value || '').trim(),
      clear: clearSelection,
    };
  }

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

  bindSearch('crm-allowed-groups-search', 'crm-allowed-groups-list');

  const modeSelect = document.getElementById('crm-access-mode');
  const confirmWrap = document.getElementById('crm-open-access-confirm-wrap');
  const confirmOpen = document.getElementById('crm-confirm-open-access');
  if (modeSelect && confirmWrap) {
    const syncConfirm = () => {
      const open = modeSelect.value === 'open';
      confirmWrap.hidden = !open;
      if (!open && confirmOpen) confirmOpen.checked = false;
    };
    modeSelect.addEventListener('change', syncConfirm);
    syncConfirm();
  }

  /**
   * Multi-select directory chips (app admins / allowed users).
   * @param {{ chipListId: string, searchId: string, suggestId: string, enabled: boolean }} opts
   */
  function bindUserChipPicker(opts) {
    const chipList = document.getElementById(opts.chipListId);
    const suggest = document.getElementById(opts.suggestId);
    const userSearch = document.getElementById(opts.searchId);
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

    if (userSearch && suggest && opts.enabled) {
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

    return { selectedUids };
  }

  const appAdminChips = bindUserChipPicker({
    chipListId: 'crm-app-admins-chips',
    searchId: 'crm-app-admins-search',
    suggestId: 'crm-app-admins-suggest',
    enabled: canManage,
  });
  const allowedUserChips = bindUserChipPicker({
    chipListId: 'crm-allowed-users-chips',
    searchId: 'crm-allowed-users-search',
    suggestId: 'crm-allowed-users-suggest',
    enabled: canManage,
  });

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

  const wsIcsRotate = document.getElementById('crm-ws-ics-rotate');
  if (wsIcsRotate) {
    wsIcsRotate.addEventListener('click', async () => {
      if (!currentWorkspaceId) return;
      const ok = await C.confirmDialog({
        title: tr('Rotate token'),
        message: tr('The old URL will stop working immediately (HTTP 410). Continue?'),
        confirmLabel: tr('Rotate'),
        cancelLabel: tr('Cancel'),
        variant: 'warning',
      });
      if (!ok || !ok.confirmed) return;
      try {
        const res = await Api.post('/workspaces/' + currentWorkspaceId + '/ics-token/rotate', {});
        const url = (res.data && res.data.icsFeedUrl) || '';
        const icsUrl = document.getElementById('crm-ws-ics-url');
        if (icsUrl && url) icsUrl.value = url;
        if (C && C.announce) C.announce(tr('Token rotated'));
      } catch (e) {
        await C.alertDialog({ title: tr('Rotate failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const accessForm = document.getElementById('crm-settings-access-form');
  if (accessForm) {
    accessForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const restrictionOn = !(modeSelect && modeSelect.value === 'open');
      const payload = {
        app_admin_user_ids: appAdminChips.selectedUids(),
        access_restriction_enabled: restrictionOn,
        access_allowed_group_ids: checkedValues('#crm-allowed-groups-list input[type="checkbox"]'),
        access_allowed_user_ids: allowedUserChips.selectedUids(),
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
  const seatError = document.getElementById('crm-license-seat-error');
  const seatList = document.getElementById('crm-license-seat-list');
  const seatPicker = bindDirectoryUserPicker({
    searchId: 'crm-license-seat-search',
    suggestId: 'crm-license-seat-suggest',
    hiddenId: 'crm-license-seat-user',
    clearId: 'crm-license-seat-clear',
    enabled: canManage,
    excludeUidsFn: () => {
      if (!seatList) return [];
      return Array.from(seatList.querySelectorAll('li[data-seat-uid]'))
        .map((el) => el.getAttribute('data-seat-uid') || '')
        .filter(Boolean);
    },
  });

  function updateSeatFactsCount() {
    if (!seatList) return;
    const count = seatList.querySelectorAll('li[data-seat-uid]').length;
    const seatsDd = document.getElementById('crm-license-seats-count');
    if (!seatsDd) return;
    const limit = parseInt(seatsDd.getAttribute('data-seat-limit') || '0', 10) || 0;
    seatsDd.setAttribute('data-seat-assigned', String(count));
    seatsDd.textContent = tr('%1$d of %2$d assigned', [count, limit]);
  }

  function appendSeatRow(seat) {
    if (!seatList || !seat || !seat.uid) return;
    if (seatList.querySelector('li[data-seat-uid="' + CSS.escape(String(seat.uid)) + '"]')) {
      return;
    }
    const li = document.createElement('li');
    li.setAttribute('data-seat-uid', String(seat.uid));
    const name = document.createElement('strong');
    name.textContent = String(seat.displayName || seat.uid);
    const uid = document.createElement('span');
    uid.className = 'crm-muted';
    uid.textContent = String(seat.uid);
    li.appendChild(name);
    li.appendChild(document.createTextNode(' '));
    li.appendChild(uid);
    if (seat.withinLimit === false) {
      li.appendChild(document.createTextNode(' · '));
      const badge = document.createElement('span');
      badge.className = 'crm-badge crm-badge--warning';
      badge.textContent = tr('over limit — paused');
      li.appendChild(badge);
    }
    if (canManage) {
      li.appendChild(document.createTextNode(' '));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'crm-btn crm-btn--ghost crm-btn--small';
      btn.setAttribute('data-remove-seat', String(seat.uid));
      btn.setAttribute('aria-label', tr('Remove mobile seat of %s', [String(seat.displayName || seat.uid)]));
      btn.textContent = tr('Remove');
      li.appendChild(btn);
    }
    seatList.appendChild(li);
    updateSeatFactsCount();
  }

  let seatAssignInFlight = false;
  async function assignSeat() {
    if (seatAssignInFlight) {
      return;
    }
    showInlineError(seatError, '');
    const userId = seatPicker.selectedUid();
    if (userId === '') {
      showInlineError(seatError, tr('Select a colleague from the directory first.'));
      const search = document.getElementById('crm-license-seat-search');
      if (search) search.focus();
      return;
    }
    seatAssignInFlight = true;
    if (seatAdd) {
      seatAdd.disabled = true;
    }
    try {
      const seat = await Api.post('/license/seats', { userId });
      appendSeatRow(seat);
      seatPicker.clear();
      const search = document.getElementById('crm-license-seat-search');
      if (search) search.focus();
      if (C && C.announce) {
        C.announce(tr('Seat assigned.'));
      }
    } catch (e) {
      showInlineError(seatError, (e.payload && e.payload.message) || e.message);
      const search = document.getElementById('crm-license-seat-search');
      if (search) search.focus();
    } finally {
      seatAssignInFlight = false;
      if (seatAdd) {
        seatAdd.disabled = false;
      }
    }
  }

  if (seatAdd && canManage) {
    seatAdd.addEventListener('click', assignSeat);
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
        const row = seatList.querySelector('li[data-seat-uid="' + CSS.escape(String(uid)) + '"]');
        if (row) row.remove();
        updateSeatFactsCount();
        if (C && C.announce) {
          C.announce(tr('Seat removed.'));
        }
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
        const label = m.displayName || uid;
        const li = document.createElement('li');
        li.className = 'crm-ws-members__item';
        li.innerHTML = '<span><strong></strong> · <span class="crm-muted"></span> · <span class="crm-badge"></span>' +
          (m.canMailSend || m.can_mail_send ? ' · ' + tr('mail send') : '') + '</span>';
        li.querySelector('strong').textContent = label;
        li.querySelector('.crm-muted').textContent = uid;
        li.querySelector('.crm-badge').textContent = m.role || 'member';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'crm-btn crm-btn--ghost';
        btn.textContent = tr('Remove');
        btn.setAttribute('aria-label', tr('Remove member') + ' ' + label);
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
      const leadSla = document.getElementById('crm-ws-lead-sla');
      const stuck = document.getElementById('crm-ws-stuck');
      const mailRetention = document.getElementById('crm-ws-mail-retention');
      const digest = document.getElementById('crm-ws-digest');
      if (wip) wip.value = String(s.wipLimit != null ? s.wipLimit : 5);
      if (leadSla) leadSla.value = String(s.leadSlaHours != null ? s.leadSlaHours : 24);
      if (stuck) stuck.value = String(s.stuckChaseDays != null ? s.stuckChaseDays : 3);
      if (mailRetention) mailRetention.value = String(s.mailRetentionDays != null ? s.mailRetentionDays : 365);
      if (digest) digest.checked = !!s.digestEnabled;
      const icsWrap = document.getElementById('crm-ws-ics-wrap');
      const icsUrl = document.getElementById('crm-ws-ics-url');
      if (icsWrap && icsUrl) {
        if (s.icsFeedEditable && s.icsFeedUrl) {
          icsWrap.hidden = false;
          icsUrl.value = String(s.icsFeedUrl);
        } else {
          icsWrap.hidden = true;
          icsUrl.value = '';
        }
      }
      const sources = s.reviewSources || {};
      const kindMap = {
        shared_mail: 'crm-review-src-shared-mail',
        contact_suggest: 'crm-review-src-contacts',
        file: 'crm-review-src-files',
        calendar_event: 'crm-review-src-calendar',
        task: 'crm-review-src-tasks',
        import_anomaly: 'crm-review-src-import',
      };
      Object.keys(kindMap).forEach((kind) => {
        const el = document.getElementById(kindMap[kind]);
        if (el && sources[kind]) {
          el.checked = !!sources[kind].enabled;
        }
      });
      const contactsActor = document.getElementById('crm-review-contacts-actor');
      if (contactsActor) {
        contactsActor.value = (sources.contact_suggest && sources.contact_suggest.scanActor) || '';
      }
      const fileFolder = document.getElementById('crm-review-file-folder');
      const fileActor = document.getElementById('crm-review-file-actor');
      if (fileFolder) {
        fileFolder.value = sources.file && sources.file.rootFolderId
          ? String(sources.file.rootFolderId)
          : '';
      }
      if (fileActor) {
        fileActor.value = (sources.file && sources.file.scanActor) || '';
      }
      const calUris = document.getElementById('crm-review-cal-uris');
      const calActor = document.getElementById('crm-review-cal-actor');
      if (calUris) {
        calUris.value = sources.calendar_event && Array.isArray(sources.calendar_event.calendarUris)
          ? sources.calendar_event.calendarUris.join('\n')
          : '';
      }
      if (calActor) {
        calActor.value = (sources.calendar_event && sources.calendar_event.scanActor) || '';
      }
      const taskLists = document.getElementById('crm-review-task-lists');
      const taskActor = document.getElementById('crm-review-task-actor');
      if (taskLists) {
        taskLists.value = sources.task && Array.isArray(sources.task.listIds)
          ? sources.task.listIds.join('\n')
          : '';
      }
      if (taskActor) {
        taskActor.value = (sources.task && sources.task.scanActor) || '';
      }
      if (typeof loadValueMaps === 'function') {
        await loadValueMaps();
      }
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
      const leadSlaHours = parseInt(String((document.getElementById('crm-ws-lead-sla') || {}).value || '24'), 10);
      const stuckChaseDays = parseInt(String((document.getElementById('crm-ws-stuck') || {}).value || '3'), 10);
      const mailRetentionDays = parseInt(String((document.getElementById('crm-ws-mail-retention') || {}).value || '365'), 10);
      const digestEnabled = !!(document.getElementById('crm-ws-digest') || {}).checked;
      try {
        await Api.put('/workspaces/' + currentWorkspaceId + '/settings', {
          wipLimit: wip,
          leadSlaHours: leadSlaHours,
          stuckChaseDays: stuckChaseDays,
          mailRetentionDays: mailRetentionDays,
          digestEnabled: digestEnabled,
        });
        if (C && C.announce) C.announce(tr('Workspace settings saved'));
      } catch (e) {
        showWsError((e.payload && e.payload.message) || e.message);
      }
    });
  }

  const wsMemberPicker = bindDirectoryUserPicker({
    searchId: 'crm-ws-member-search',
    suggestId: 'crm-ws-member-suggest',
    hiddenId: 'crm-ws-member-uid',
    clearId: 'crm-ws-member-clear',
    enabled: canManageWorkspace,
    workspaceIdFn: () => currentWorkspaceId || 0,
  });

  const wsMemberForm = document.getElementById('crm-ws-member-form');
  if (wsMemberForm && canManageWorkspace) {
    wsMemberForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!currentWorkspaceId) return;
      showWsError('');
      const userId = wsMemberPicker.selectedUid();
      if (userId === '') {
        showWsError(tr('Select a colleague from the directory first.'));
        const search = document.getElementById('crm-ws-member-search');
        if (search) search.focus();
        return;
      }
      const role = String((document.getElementById('crm-ws-member-role') || {}).value || 'member');
      const canMailSend = !!(document.getElementById('crm-ws-member-mail') || {}).checked;
      try {
        await Api.post('/workspaces/' + currentWorkspaceId + '/members', {
          userId: userId,
          role: role,
          can_mail_send: canMailSend,
          canMailSend: canMailSend,
        });
        wsMemberPicker.clear();
        await refreshWorkspaceMembers();
      } catch (e) {
        showWsError((e.payload && e.payload.message) || e.message);
      }
    });
  }

  const wsImportForm = document.getElementById('crm-ws-member-import-form');
  if (wsImportForm && canManageWorkspace) {
    wsImportForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!currentWorkspaceId) return;
      showWsError('');
      const groupId = String((document.getElementById('crm-ws-import-group') || {}).value || '').trim();
      if (groupId === '') {
        showWsError(tr('Pick a Nextcloud group to import.'));
        const sel = document.getElementById('crm-ws-import-group');
        if (sel) sel.focus();
        return;
      }
      const role = String((document.getElementById('crm-ws-import-role') || {}).value || 'member');
      const canMailSend = !!(document.getElementById('crm-ws-import-mail') || {}).checked;
      const submitBtn = document.getElementById('crm-ws-import-submit');
      if (submitBtn) submitBtn.disabled = true;
      try {
        const res = await Api.post('/workspaces/' + currentWorkspaceId + '/members/import-group', {
          groupId: groupId,
          role: role,
          canMailSend: canMailSend,
        });
        const data = res.data || res || {};
        const added = Number(data.added || 0);
        const updated = Number(data.updated || 0);
        const skipped = Number(data.skipped || 0);
        let msg = tr('Imported %s people from the group.', [String(added)]);
        if (updated > 0) {
          msg += ' ' + tr('%s already in the book had their role updated.', [String(updated)]);
        }
        if (skipped > 0) {
          msg += ' ' + tr('%s skipped.', [String(skipped)]);
        }
        if (data.limitReached) {
          msg += ' ' + tr('Member limit reached — some people were not added.');
        }
        if (C && C.announce) C.announce(msg);
        if (wsStatus) {
          wsStatus.hidden = false;
          wsStatus.textContent = msg;
        }
        await refreshWorkspaceMembers();
      } catch (e) {
        showWsError((e.payload && e.payload.message) || e.message);
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  const wsImportCircleForm = document.getElementById('crm-ws-member-import-circle-form');
  if (wsImportCircleForm && canManageWorkspace) {
    wsImportCircleForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!currentWorkspaceId) return;
      showWsError('');
      const circleId = String((document.getElementById('crm-ws-import-circle') || {}).value || '').trim();
      if (circleId === '') {
        showWsError(tr('Pick a Circle to import.'));
        const sel = document.getElementById('crm-ws-import-circle');
        if (sel) sel.focus();
        return;
      }
      const role = String((document.getElementById('crm-ws-import-circle-role') || {}).value || 'member');
      const canMailSend = !!(document.getElementById('crm-ws-import-circle-mail') || {}).checked;
      const submitBtn = document.getElementById('crm-ws-import-circle-submit');
      if (submitBtn) submitBtn.disabled = true;
      try {
        const res = await Api.post('/workspaces/' + currentWorkspaceId + '/members/import-circle', {
          circleId: circleId,
          role: role,
          canMailSend: canMailSend,
        });
        const data = res.data || res || {};
        const added = Number(data.added || 0);
        const updated = Number(data.updated || 0);
        const skipped = Number(data.skipped || 0);
        let msg = tr('Imported %s people from the Circle.', [String(added)]);
        if (updated > 0) {
          msg += ' ' + tr('%s already in the book had their role updated.', [String(updated)]);
        }
        if (skipped > 0) {
          msg += ' ' + tr('%s skipped.', [String(skipped)]);
        }
        if (data.limitReached) {
          msg += ' ' + tr('Member limit reached — some people were not added.');
        }
        if (C && C.announce) C.announce(msg);
        if (wsStatus) {
          wsStatus.hidden = false;
          wsStatus.textContent = msg;
        }
        await refreshWorkspaceMembers();
      } catch (e) {
        showWsError((e.payload && e.payload.message) || e.message);
      } finally {
        if (submitBtn) submitBtn.disabled = false;
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

  async function loadValueMaps() {
    const list = document.getElementById('crm-value-maps-list');
    if (!list || !canManageWorkspace || !currentWorkspaceId) return;
    list.setAttribute('aria-busy', 'true');
    try {
      const res = await Api.get('/workspaces/' + currentWorkspaceId + '/value-maps');
      const items = (res.data && res.data.items) || res.items || [];
      if (!items.length) {
        list.innerHTML = '<p class="crm-muted">' + escapeHtml(tr('No learned country maps yet.')) + '</p>';
        return;
      }
      list.innerHTML = items.map((it) => {
        const id = String(it.id || '');
        return '<div class="crm-form crm-form--row" data-vm-id="' + escapeHtml(id) + '">' +
          '<span>' + escapeHtml(String(it.raw || '')) + ' → ' + escapeHtml(String(it.canonical || '')) +
          ' <span class="crm-muted">(' + escapeHtml(String(it.fieldKey || 'country')) + ')</span></span>' +
          '<button type="button" class="crm-btn crm-btn--secondary" data-vm-delete="' + escapeHtml(id) +
          '" style="min-height:44px;min-width:44px">' + escapeHtml(tr('Remove')) + '</button></div>';
      }).join('');
      list.querySelectorAll('[data-vm-delete]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-vm-delete');
          try {
            await Api.del('/workspaces/' + currentWorkspaceId + '/value-maps/' + id);
            await loadValueMaps();
          } catch (e) {
            const status = document.getElementById('crm-value-maps-status');
            if (status) status.textContent = (e.payload && e.payload.message) || e.message;
          }
        });
      });
    } catch (e) {
      list.innerHTML = '<p class="form-error" role="alert">' +
        escapeHtml((e.payload && e.payload.message) || e.message || tr('Could not load maps.')) + '</p>';
    } finally {
      list.setAttribute('aria-busy', 'false');
    }
  }

  const valueMapsForm = document.getElementById('crm-value-maps-form');
  if (valueMapsForm && canManageWorkspace) {
    valueMapsForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!currentWorkspaceId) return;
      const status = document.getElementById('crm-value-maps-status');
      const raw = String((document.getElementById('crm-vm-raw') || {}).value || '').trim();
      const canonical = String((document.getElementById('crm-vm-canonical') || {}).value || '').trim();
      try {
        await Api.post('/workspaces/' + currentWorkspaceId + '/value-maps', {
          fieldKey: 'country',
          raw: raw,
          canonical: canonical,
          learn: true,
        });
        if (status) status.textContent = tr('Country map saved.');
        await loadValueMaps();
      } catch (e) {
        if (status) status.textContent = (e.payload && e.payload.message) || e.message;
      }
    });
  }

  const reviewSourcesForm = document.getElementById('crm-review-sources-form');
  if (reviewSourcesForm && canManageWorkspace) {
    reviewSourcesForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!currentWorkspaceId) return;
      const status = document.getElementById('crm-review-sources-status');
      const dsgvo = !!(document.getElementById('crm-review-src-dsgvo') || {}).checked;
      const sources = {};
      reviewSourcesForm.querySelectorAll('[data-review-kind]').forEach((el) => {
        const kind = el.getAttribute('data-review-kind');
        sources[kind] = { enabled: !!el.checked, dsgvoAck: dsgvo };
      });
      const contactsActor = document.getElementById('crm-review-contacts-actor');
      if (sources.contact_suggest) {
        sources.contact_suggest.scanActor = contactsActor ? String(contactsActor.value || '').trim() : '';
      }
      const fileFolder = document.getElementById('crm-review-file-folder');
      const fileActor = document.getElementById('crm-review-file-actor');
      if (sources.file) {
        sources.file.rootFolderId = fileFolder ? parseInt(fileFolder.value || '0', 10) : 0;
        sources.file.scanActor = fileActor ? String(fileActor.value || '').trim() : '';
      }
      const calUris = document.getElementById('crm-review-cal-uris');
      const calActor = document.getElementById('crm-review-cal-actor');
      if (sources.calendar_event) {
        sources.calendar_event.calendarUris = calUris
          ? String(calUris.value || '').split(/\n+/).map((s) => s.trim()).filter(Boolean)
          : [];
        sources.calendar_event.scanActor = calActor ? String(calActor.value || '').trim() : '';
      }
      const taskLists = document.getElementById('crm-review-task-lists');
      const taskActor = document.getElementById('crm-review-task-actor');
      if (sources.task) {
        sources.task.listIds = taskLists
          ? String(taskLists.value || '').split(/\n+/).map((s) => s.trim()).filter(Boolean)
          : [];
        sources.task.scanActor = taskActor ? String(taskActor.value || '').trim() : '';
      }
      try {
        await Api.put('/workspaces/' + currentWorkspaceId + '/review/sources', { sources: sources });
        if (status) status.textContent = tr('Review sources saved.');
        if (C && C.announce) C.announce(tr('Review sources saved.'));
      } catch (e) {
        if (status) status.textContent = (e.payload && e.payload.message) || e.message;
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
        escapeHtml(tr('Weekly promise check')) + '"><thead><tr>';
      html += '<th scope="col">' + escapeHtml(tr('Workspace')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('Week')) + '</th>';
      html += '<th scope="col">' + escapeHtml(tr('Promises')) + '</th>';
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
        html += '<td data-label="' + escapeHtml(tr('Promises')) + '">' + escapeHtml(String(it.wipUsed || 0) + '/' + String(it.wipLimit || 5)) + '</td>';
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
      mount.innerHTML = '<p role="alert">' + escapeHtml((e.payload && e.payload.message) || e.message || tr('Could not load weekly promise check.')) + '</p>';
    }
  }

  loadCirclesRollCall();

  // Deep-link from Review empty CTA — scroll Review sources into view.
  (function focusReviewSourcesHash() {
    if (window.location.hash !== '#crm-review-sources') return;
    const el = document.getElementById('crm-review-sources');
    if (!el) return;
    try {
      el.scrollIntoView({ block: 'start', behavior: 'smooth' });
      el.classList.add('crm-card--focus');
      el.setAttribute('tabindex', '-1');
      el.focus({ preventScroll: true });
    } catch (e) { /* ignore */ }
  })();
})();
