(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const PersonDlg = window.CustomerCheckPersonDialogCore || {};
  const Api = window.CustomerCheckApi;
  const t = (msg, args) => {
    const list = Array.isArray(args) ? args : [];
    let out = window.t ? window.t('customercheck', msg, list) : msg;
    // Nextcloud t() sometimes leaves %s / %1$s — fill locally (same as settings.js).
    if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
      let i = 0;
      out = out.replace(/%(?:\d+\$)?s/g, function () {
        const v = list[i];
        i += 1;
        return v == null ? '' : String(v);
      });
    }
    return out;
  };
  if (C && C.init) { C.init(); }

  function shellCan(flag) {
    if (typeof window.CustomerCheckShellCan === 'function') {
      return window.CustomerCheckShellCan(flag);
    }
    const el = document.getElementById('app-content');
    return !!(el && el.getAttribute('data-crm-can-' + flag) === '1');
  }

  const root = document.querySelector('.crm-page-stack[data-company-id]');
  const companyId = root ? parseInt(root.getAttribute('data-company-id'), 10) : 0;
  const SharedMail = window.CustomerCheckSharedMail || null;

  const exportBtn = document.getElementById('crm-company-gdpr-export');
  if (exportBtn) {
    exportBtn.addEventListener('click', async () => {
      try {
        const res = await Api.post('/companies/' + companyId + '/export', {});
        const blob = new Blob([JSON.stringify(res.data || res, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'customercheck-company-' + (exportBtn.getAttribute('data-slug') || companyId) + '.json';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        const live = document.getElementById('crm-live-region');
        if (live) { live.textContent = t('Export downloaded.'); }
      } catch (e) {
        await C.alertDialog({ title: t('Export failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const eraseBtn = document.getElementById('crm-company-gdpr-erase');
  if (eraseBtn) {
    eraseBtn.addEventListener('click', async () => {
      const slug = eraseBtn.getAttribute('data-slug') || '';
      const result = await C.confirmDialog({
        title: t('Erase company (GDPR)'),
        message: t('This permanently deletes personal data for this company. Type the short address to confirm: %s', [slug]),
        confirmLabel: t('Erase'),
        cancelLabel: t('Cancel'),
        variant: 'danger',
        requireTypedConfirm: true,
        typedConfirmPhrase: slug,
      });
      if (!result || !result.confirmed) return;
      try {
        await Api.del('/companies/' + companyId, { confirmSlug: slug });
        window.location.href = OC.generateUrl('/apps/customercheck/companies');
      } catch (e) {
        await C.alertDialog({ title: t('Erase failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const mergeBtn = document.getElementById('crm-company-merge');
  if (mergeBtn && !mergeBtn.disabled) {
    mergeBtn.addEventListener('click', async () => {
      const data = await promptFields(t('Merge into another company'), [
        {
          name: 'intoCompanyId',
          label: t('Keep this company'),
          type: 'company',
          required: true,
          excludeIds: [companyId],
          hint: t('People, agreements, deals, activities and history move to the company you pick. This company is archived afterwards.'),
        },
      ]);
      if (!data) return;
      const intoId = parseInt(String(data.intoCompanyId || '0'), 10);
      if (!(intoId > 0) || intoId === companyId) {
        await C.alertDialog({
          title: t('Almost there'),
          message: t('Choose a company to merge into.'),
          variant: 'info',
        });
        return;
      }
      const keeperLabel = String(data.intoCompanyName || ('#' + intoId));
      const ok = await C.confirmDialog({
        title: t('Confirm merge'),
        message: t('Merge this company into “%s”? This cannot be undone automatically — the source becomes archived.', [keeperLabel]),
        confirmLabel: t('Merge'),
        cancelLabel: t('Cancel'),
        variant: 'warning',
      });
      if (!ok || !ok.confirmed) return;
      mergeBtn.disabled = true;
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Merging companies…');
      try {
        const res = await Api.post('/companies/' + companyId + '/merge', {
          intoCompanyId: intoId,
          updatedAt: mergeBtn.getAttribute('data-updated-at') || null,
          intoUpdatedAt: data.intoCompanyUpdatedAt || null,
        });
        const keeper = (res.data && res.data.keeperId) || intoId;
        if (live) live.textContent = t('Companies merged.');
        window.location.href = OC.generateUrl('/apps/customercheck/companies/' + keeper);
      } catch (e) {
        mergeBtn.disabled = false;
        if (live) live.textContent = '';
        await C.alertDialog({ title: t('Merge failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const moveWsBtn = document.getElementById('crm-company-move-workspace');
  if (moveWsBtn) {
    moveWsBtn.remove();
  }

  async function promptFields(title, fields) {
    // Lightweight modal form via openDialog — supports progressive "More options", checkboxes, company picker.
    return new Promise((resolve) => {
      const id = 'crm-prompt-' + Date.now();
      const primary = fields.filter((f) => !f.advanced);
      const advanced = fields.filter((f) => f.advanced);
      const companyFields = fields.filter((f) => f.type === 'company');
      const renderField = (f) => {
        const fname = String(f.name || '').replace(/[^a-zA-Z0-9_-]/g, '');
        const flabel = escapeHtml(f.label || fname);
        let html = '<div class="form-group"><label class="form-label" for="' + id + '-' + fname + '">' + flabel + '</label>';
        if (f.type === 'company') {
          html =
            '<div class="form-group">' +
            '<span class="form-label" id="' + id + '-' + fname + '-label">' + flabel + '</span>' +
            '<div class="crm-company-picker" id="' + id + '-' + fname + '-picker" aria-labelledby="' + id + '-' + fname + '-label">' +
            '<div class="crm-company-choice" id="' + id + '-' + fname + '-choice" hidden>' +
            '<div class="crm-company-choice__card">' +
            '<p class="crm-company-choice__name" id="' + id + '-' + fname + '-choice-name"></p>' +
            '<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm crm-company-choice__change" id="' +
            id + '-' + fname + '-change">' + escapeHtml(t('Change company')) + '</button></div></div>' +
            '<div class="crm-company-browse" id="' + id + '-' + fname + '-browse">' +
            '<div class="crm-company-picker__filter-wrap" id="' + id + '-' + fname + '-filter-wrap" hidden>' +
            '<label class="visually-hidden" for="' + id + '-' + fname + '-filter">' + escapeHtml(t('Filter companies')) + '</label>' +
            '<input type="search" id="' + id + '-' + fname + '-filter" class="form-input crm-input crm-company-picker__filter" ' +
            'autocomplete="off" placeholder="' + escapeHtml(t('Search by company name…')) + '" ' +
            'aria-controls="' + id + '-' + fname + '-list" /></div>' +
            '<ul id="' + id + '-' + fname + '-list" class="crm-company-list" role="listbox" aria-labelledby="' +
            id + '-' + fname + '-label"></ul>' +
            '<p class="crm-muted crm-company-picker__status" id="' + id + '-' + fname + '-status" role="status" aria-live="polite" hidden></p>' +
            '</div>' +
            '<input type="hidden" id="' + id + '-' + fname + '" name="' + fname + '" value=""' + (f.required ? ' required' : '') + ' />' +
            '</div>';
          if (f.hint) {
            html += '<p class="form-help">' + escapeHtml(String(f.hint)) + '</p>';
          }
          html += '</div>';
          return html;
        }
        if (f.type === 'textarea') {
          // rows: default 3; callers may pass 2 for short multi-line titles (granny-readable, not a tiny strip).
          const rowsRaw = parseInt(String(f.rows != null ? f.rows : 3), 10);
          const rows = Number.isFinite(rowsRaw) && rowsRaw >= 2 ? Math.min(rowsRaw, 12) : 3;
          html += '<textarea class="crm-input form-input form-textarea" id="' + id + '-' + fname + '" name="' + fname + '" rows="' + rows + '"' +
            (f.maxlength !== undefined ? ' maxlength="' + escapeHtml(String(f.maxlength)) + '"' : '') +
            (f.required ? ' required' : '') + '>' +
            (f.value ? escapeHtml(String(f.value)) : '') + '</textarea>';
        } else if (f.type === 'select') {
          html += '<select class="crm-input form-select" id="' + id + '-' + fname + '" name="' + fname + '"' + (f.required ? ' required' : '') + '>' +
            f.options.map((o) => {
              const value = typeof o === 'object' && o !== null ? String(o.value) : String(o);
              const label = typeof o === 'object' && o !== null ? String(o.label || o.value) : String(o);
              const selected = f.value !== undefined && String(f.value) === value ? ' selected' : '';
              return '<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(label) + '</option>';
            }).join('') + '</select>';
        } else if (f.type === 'date') {
          const val = f.value !== undefined && f.value !== null ? ' value="' + escapeHtml(String(f.value)) + '"' : '';
          html += '<input class="crm-input form-input datepicker-input" id="' + id + '-' + fname + '" name="' + fname + '" type="date"' + val +
            ' autocomplete="off" placeholder="' + escapeHtml(t('dd.mm.yyyy')) + '" ' + (f.required ? 'required' : '') + ' />';
        } else if (f.type === 'checkbox') {
          const checked = f.value ? ' checked' : '';
          html = '<div class="form-group"><label class="crm-choice" for="' + id + '-' + fname + '">' +
            '<input class="crm-input" id="' + id + '-' + fname + '" name="' + fname + '" type="checkbox" value="1"' + checked + ' />' +
            '<span>' + flabel + '</span></label>';
        } else {
          const val = f.value !== undefined && f.value !== null ? ' value="' + escapeHtml(String(f.value)) + '"' : '';
          const inputType = String(f.type || 'text').replace(/[^a-zA-Z0-9-]/g, '') || 'text';
          html += '<input class="crm-input form-input" id="' + id + '-' + fname + '" name="' + fname + '" type="' + inputType + '"' + val +
            (f.min !== undefined ? ' min="' + escapeHtml(String(f.min)) + '"' : '') +
            (f.step !== undefined ? ' step="' + escapeHtml(String(f.step)) + '"' : '') +
            (f.maxlength !== undefined ? ' maxlength="' + escapeHtml(String(f.maxlength)) + '"' : '') +
            ' ' + (f.required ? 'required' : '') + ' />';
        }
        if (f.hint) {
          html += '<p class="form-help">' + escapeHtml(String(f.hint)) + '</p>';
        }
        html += '</div>';
        return html;
      };
      let html = '<form id="' + id + '-form" class="crm-form crm-form--stack">';
      primary.forEach((f) => { html += renderField(f); });
      if (advanced.length) {
        html += '<details class="crm-advanced"><summary class="crm-advanced__summary">' + escapeHtml(t('More options')) + '</summary>';
        html += '<div class="crm-advanced__body">';
        advanced.forEach((f) => { html += renderField(f); });
        html += '</div></details>';
      }
      html += '<p class="crm-form-inline-error crm-callout crm-callout--danger" role="alert" hidden></p>';
      html += '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save')) + '</button> ' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button></div></form>';
      const panels = [];
      C.openDialog({
        id: id,
        title: title,
        content: html,
        size: 'md',
        onClose: () => {
          panels.forEach((p) => {
            if (p && typeof p.destroy === 'function') p.destroy();
          });
        },
      });
      const form = document.getElementById(id + '-form');
      const errEl = form.querySelector('.crm-form-inline-error');
      const close = () => C.closeModal(document.getElementById(id));
      form.querySelector('[data-cancel]').addEventListener('click', () => { close(); resolve(null); });
      const Picker = window.CustomerCheckCompanyPicker;
      companyFields.forEach((f) => {
        const fname = String(f.name || '').replace(/[^a-zA-Z0-9_-]/g, '');
        if (!Picker || typeof Picker.mountListPanel !== 'function') {
          return;
        }
        const panel = Picker.mountListPanel({
          listEl: document.getElementById(id + '-' + fname + '-list'),
          filterEl: document.getElementById(id + '-' + fname + '-filter'),
          filterWrapEl: document.getElementById(id + '-' + fname + '-filter-wrap'),
          browseEl: document.getElementById(id + '-' + fname + '-browse'),
          choiceEl: document.getElementById(id + '-' + fname + '-choice'),
          choiceNameEl: document.getElementById(id + '-' + fname + '-choice-name'),
          changeBtn: document.getElementById(id + '-' + fname + '-change'),
          hiddenEl: document.getElementById(id + '-' + fname),
          statusEl: document.getElementById(id + '-' + fname + '-status'),
          api: Api,
          t: t,
          browseLimit: 40,
          filterMinCount: 8,
          excludeIds: Array.isArray(f.excludeIds) ? f.excludeIds : [],
          onReady: (count) => {
            if (count < 1 && f.required) {
              if (errEl) {
                errEl.hidden = false;
                errEl.textContent = t('Create or restore another active company first, then merge this duplicate into it.');
              }
            }
          },
        });
        panels.push(panel);
      });
      const first = form.querySelector('input:not([type="hidden"]):not([type="checkbox"]), textarea, select, .crm-company-list__pick');
      if (first && typeof first.focus === 'function') {
        setTimeout(() => first.focus(), 50);
      }
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        if (errEl) {
          errEl.hidden = true;
          errEl.textContent = '';
        }
        const missing = [];
        fields.forEach((f) => {
          if (!f.required || f.type === 'checkbox') return;
          const el = form.elements[f.name];
          const val = el ? String(el.value || '').trim() : '';
          if (!val) missing.push(f.label);
        });
        if (missing.length) {
          if (errEl) {
            errEl.hidden = false;
            errEl.textContent = t('Please fill in: %s', [missing.join(', ')]);
          }
          return;
        }
        const data = {};
        fields.forEach((f) => {
          const el = form.elements[f.name];
          if (!el) {
            data[f.name] = f.type === 'checkbox' ? false : '';
            return;
          }
          if (f.type === 'checkbox') {
            data[f.name] = !!el.checked;
          } else if (f.type === 'company') {
            data[f.name] = el.value;
            const metaBase = String(f.name || '').replace(/Id$/, '');
            data[metaBase + 'Name'] = (el.dataset && el.dataset.companyName) || '';
            data[metaBase + 'UpdatedAt'] = (el.dataset && el.dataset.updatedAt) || '';
          } else if (f.type === 'date') {
            const DP = window.CustomerCheckDatepicker;
            data[f.name] = (DP && typeof DP.isoValue === 'function') ? DP.isoValue(el) : el.value;
          } else {
            data[f.name] = el.value;
          }
        });
        panels.forEach((p) => {
          if (p && typeof p.destroy === 'function') p.destroy();
        });
        close();
        resolve(data);
      });
    });
  }

  /** Local calendar YYYY-MM-DD — never toISOString (UTC day shift). */
  function localYmd(d) {
    const x = d instanceof Date ? d : new Date();
    const y = x.getFullYear();
    const m = String(x.getMonth() + 1).padStart(2, '0');
    const day = String(x.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function utcYmd(d) {
    const y = d.getUTCFullYear();
    const m = String(d.getUTCMonth() + 1).padStart(2, '0');
    const day = String(d.getUTCDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function formatDisplayDate(iso) {
    const raw = String(iso || '').trim();
    if (!raw || raw === '—') return '—';
    const hasTime = /T|\s\d{1,2}:/.test(raw);
    const d = new Date(hasTime ? raw : (raw + 'T12:00:00'));
    if (Number.isNaN(d.getTime())) return raw;
    try {
      const locale = (document.documentElement.lang || navigator.language || undefined);
      return new Intl.DateTimeFormat(locale, hasTime
        ? { dateStyle: 'medium', timeStyle: 'short' }
        : { dateStyle: 'medium' }).format(d);
    } catch (e) {
      return raw;
    }
  }

  document.addEventListener('click', (ev) => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('[data-crm-trigger]') : null;
    if (!btn) return;
    const targetId = btn.getAttribute('data-crm-trigger') || '';
    const target = targetId ? document.getElementById(targetId) : null;
    if (target) target.click();
  });

  function companyUpdatedAt() {
    return root ? (root.getAttribute('data-updated-at') || '') : '';
  }

  const PHONE_RE = PersonDlg.PHONE_RE || /^[0-9+()\/.\s-]{4,64}$/;
  const EMAIL_RE = PersonDlg.EMAIL_RE || /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  let identitySaveBusy = false;
  let personContactsUid = '';
  let identityFormOpener = null;
  let contactsSuggestGen = 0;

  function fieldValue(id) {
    const el = document.getElementById(id);
    return el && typeof el.value === 'string' ? el.value.trim() : '';
  }

  function setFieldValue(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value == null ? '' : String(value);
  }

  function showIdentityError(boxId, err) {
    const errBox = document.getElementById(boxId);
    if (!errBox) return;
    const msg = (err && err.payload && err.payload.message) || (err && err.message) || t('Could not save.');
    errBox.hidden = false;
    errBox.textContent = '';
    errBox.appendChild(document.createTextNode(msg));
    if (err && Number(err.status) === 409) {
      const reload = document.createElement('button');
      reload.type = 'button';
      reload.className = 'crm-btn crm-btn--secondary crm-btn--lg';
      reload.textContent = t('Reload page');
      reload.addEventListener('click', () => location.reload());
      const hint = document.createElement('p');
      hint.className = 'form-help';
      hint.textContent = t('This page is out of date. Reload and try again.');
      errBox.appendChild(hint);
      errBox.appendChild(reload);
    }
    errBox.focus();
  }

  function clearIdentityError(boxId) {
    const errBox = document.getElementById(boxId);
    if (!errBox) return;
    errBox.hidden = true;
    errBox.textContent = '';
  }

  function rememberIdentityOpener(el) {
    identityFormOpener = el instanceof HTMLElement ? el : null;
  }

  function restoreIdentityOpenerFocus() {
    const el = identityFormOpener;
    identityFormOpener = null;
    if (el && document.contains(el) && typeof el.focus === 'function') {
      el.focus();
    }
  }

  function bumpContactsSuggest() {
    contactsSuggestGen += 1;
  }

  function setFormOpen(form, btn, open, focusId) {
    if (!form) return;
    form.hidden = !open;
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      const focusEl = focusId ? document.getElementById(focusId) : null;
      if (focusEl && typeof focusEl.focus === 'function') {
        focusEl.focus();
      }
      if (typeof form.scrollIntoView === 'function') {
        form.scrollIntoView({ block: 'nearest' });
      }
    }
  }

  function setPersonAddExpanded(open) {
    document.querySelectorAll('[data-crm-trigger="crm-add-person"], #crm-add-person').forEach((b) => {
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  function personDialogEl() {
    return document.getElementById(PersonDlg.DIALOG_ID || 'crm-person-dialog');
  }

  function isPersonDialogOpen() {
    const dlg = personDialogEl();
    if (typeof PersonDlg.isNativeDialogOpen === 'function') {
      return PersonDlg.isNativeDialogOpen(dlg);
    }
    return !!(dlg && (dlg.open === true || dlg.hasAttribute('open')));
  }

  function hostPersonDialog() {
    const dlg = personDialogEl();
    if (!dlg) return null;
    if (typeof PersonDlg.hostDialogOnBody === 'function') {
      PersonDlg.hostDialogOnBody(dlg, document.body);
    } else if (dlg.parentElement !== document.body) {
      document.body.appendChild(dlg);
    }
    if (typeof PersonDlg.markPersist === 'function') {
      PersonDlg.markPersist(dlg);
    } else {
      dlg.dataset.azcModalPersist = 'true';
      dlg.dataset.azcFocusTrapStandalone = '1';
    }
    if (dlg.dataset.crmPersonDialogWired !== '1') {
      dlg.dataset.crmPersonDialogWired = '1';
      dlg.addEventListener('cancel', (e) => {
        const block = typeof PersonDlg.shouldBlockDialogDismiss === 'function'
          ? PersonDlg.shouldBlockDialogDismiss(identitySaveBusy)
          : identitySaveBusy;
        if (block || dlg.dataset.azcModalDismiss === 'false') {
          e.preventDefault();
          return;
        }
        setTimeout(() => {
          if (C && typeof C.closeModal === 'function') {
            C.closeModal(dlg);
          }
        }, 0);
      });
      dlg.addEventListener('click', (e) => {
        if (e.target !== dlg) return;
        const block = typeof PersonDlg.shouldBlockDialogDismiss === 'function'
          ? PersonDlg.shouldBlockDialogDismiss(identitySaveBusy)
          : identitySaveBusy;
        if (block || dlg.dataset.azcModalDismiss === 'false') return;
        closePersonModal();
        restoreIdentityOpenerFocus();
      });
    }
    return dlg;
  }

  function closePersonModal() {
    setPersonAddExpanded(false);
    const dlg = personDialogEl();
    if (!dlg) return;
    if (C && typeof C.closeModal === 'function' && isPersonDialogOpen()) {
      C.closeModal(dlg);
    }
  }

  function openPersonModal(focusId) {
    const dlg = hostPersonDialog();
    if (!dlg || !C || typeof C.openModal !== 'function') return;
    setPersonAddExpanded(true);
    if (identityFormOpener) {
      dlg._azcReturnFocus = identityFormOpener;
    }
    C.openModal(dlg);
    const focusEl = focusId ? document.getElementById(focusId) : null;
    const wait = typeof PersonDlg.FOCUS_AFTER_OPEN_MS === 'number' ? PersonDlg.FOCUS_AFTER_OPEN_MS : 80;
    window.setTimeout(() => {
      if (!isPersonDialogOpen()) return;
      if (focusEl && typeof focusEl.focus === 'function') {
        focusEl.focus();
      }
    }, wait);
  }

  function closeIdentityForms() {
    bumpContactsSuggest();
    setFormOpen(document.getElementById('crm-company-name-form'), document.getElementById('crm-change-company'), false);
    setFormOpen(document.getElementById('crm-address-form'), document.getElementById('crm-edit-address'), false);
    closePersonModal();
  }

  function revealPeopleFocusTab() {
    const tab = document.querySelector('[data-crm-focus-tab="people"]');
    const panel = document.getElementById('crm-360-panel-people');
    if (tab instanceof HTMLElement && tab.getAttribute('aria-selected') !== 'true') {
      tab.click();
    }
    if (panel instanceof HTMLElement && panel.hasAttribute('hidden')) {
      panel.removeAttribute('hidden');
      panel.removeAttribute('aria-hidden');
    }
  }

  function openExclusiveForm(which) {
    if (identitySaveBusy) return;
    closeIdentityForms();
    if (which === 'name') {
      setFormOpen(document.getElementById('crm-company-name-form'), document.getElementById('crm-change-company'), true, 'crm-identity-name');
    } else if (which === 'address') {
      setFormOpen(document.getElementById('crm-address-form'), document.getElementById('crm-edit-address'), true, 'crm-address-street');
    } else if (which === 'person') {
      revealPeopleFocusTab();
      const addBtn = document.getElementById('crm-add-person');
      if (addBtn) addBtn.setAttribute('aria-expanded', 'true');
      openPersonModal('crm-person-name');
    }
  }

  async function withIdentitySave(form, errBoxId, work) {
    if (identitySaveBusy) return;
    identitySaveBusy = true;
    const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    const personDlg = form && typeof form.closest === 'function' ? form.closest('#crm-person-dialog') : null;
    const extraLock = [];
    if (personDlg) {
      if (typeof PersonDlg.setDismissible === 'function') {
        PersonDlg.setDismissible(personDlg, false);
      } else {
        personDlg.dataset.azcModalDismiss = 'false';
      }
      ['crm-person-dialog-close', 'crm-person-cancel'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) extraLock.push(el);
      });
      extraLock.forEach((el) => { el.disabled = true; });
    }
    clearIdentityError(errBoxId);
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.setAttribute('aria-busy', 'true');
    }
    try {
      await work();
    } catch (e) {
      showIdentityError(errBoxId, e);
    } finally {
      identitySaveBusy = false;
      extraLock.forEach((el) => { el.disabled = false; });
      if (personDlg) {
        if (typeof PersonDlg.setDismissible === 'function') {
          PersonDlg.setDismissible(personDlg, true);
        } else {
          delete personDlg.dataset.azcModalDismiss;
        }
      }
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.setAttribute('aria-busy', 'false');
      }
    }
  }

  function fillPersonForm(mode, row) {
    const form = document.getElementById('crm-person-form');
    const title = document.getElementById('crm-person-form-title');
    if (!form) return;
    form.setAttribute('data-mode', mode);
    personContactsUid = '';
    form.removeAttribute('data-person-id');
    form.removeAttribute('data-updated-at');
    if (title) title.textContent = mode === 'edit' ? t('Change person') : t('Add person');
    clearIdentityError('crm-person-form-error');
    setFieldValue('crm-person-name', row ? (row.getAttribute('data-person-name') || '') : '');
    setFieldValue('crm-person-email', row ? (row.getAttribute('data-person-email') || '') : '');
    setFieldValue('crm-person-phone', row ? (row.getAttribute('data-person-phone') || '') : '');
    setFieldValue('crm-person-role', row ? (row.getAttribute('data-person-role') || '') : '');
    const role = row ? (row.getAttribute('data-person-role') || '').trim() : '';
    const phone = row ? (row.getAttribute('data-person-phone') || '').trim() : '';
    const advanced = document.getElementById('crm-person-advanced');
    if (advanced) advanced.open = !!(role || phone);
    if (mode === 'edit' && row) {
      form.setAttribute('data-person-id', row.getAttribute('data-person-id') || '');
      form.setAttribute('data-updated-at', row.getAttribute('data-updated-at') || '');
    }
  }

  async function maybeApplyContactsMatch(name) {
    const q = String(name || '').trim();
    if (q.length < 2 || identitySaveBusy) return;
    const form = document.getElementById('crm-person-form');
    const mode = form ? form.getAttribute('data-mode') : '';
    const dialogOpen = isPersonDialogOpen();
    const allow = typeof PersonDlg.shouldSuggestContacts === 'function'
      ? PersonDlg.shouldSuggestContacts(mode, identitySaveBusy, dialogOpen)
      : (mode === 'add' && dialogOpen);
    if (!form || !allow) return;
    const gen = ++contactsSuggestGen;
    try {
      const resPayload = await Api.get('/contacts/suggest?q=' + encodeURIComponent(q) + '&limit=5');
      if (gen !== contactsSuggestGen || identitySaveBusy) return;
      const stillOpen = typeof PersonDlg.shouldSuggestContacts === 'function'
        ? PersonDlg.shouldSuggestContacts(form.getAttribute('data-mode'), identitySaveBusy, isPersonDialogOpen())
        : (form.getAttribute('data-mode') === 'add' && isPersonDialogOpen());
      if (!form || !stillOpen) return;
      const res = (resPayload && resPayload.data) ? resPayload.data : resPayload;
      if (!res || !res.available) return;
      const items = (res && Array.isArray(res.items) && res.items.length)
        ? res.items
        : (res && Array.isArray(res.suggestions) ? res.suggestions : []);
      const applyContact = (match) => {
        if (!match || !match.name) return;
        setFieldValue('crm-person-name', match.name);
        if (match.email && fieldValue('crm-person-email') === '') setFieldValue('crm-person-email', match.email);
        if (match.phone && fieldValue('crm-person-phone') === '') setFieldValue('crm-person-phone', match.phone);
        if (match.uid) personContactsUid = String(match.uid);
      };
      if (items.length === 1) {
        applyContact(items[0]);
      } else if (items.length > 1) {
        const first = items[0];
        const use = await C.confirmDialog({
          title: t('Use Contacts match?'),
          message: first.name + (first.email ? ' <' + first.email + '>' : ''),
          confirmLabel: t('Use this contact'),
          cancelLabel: t('Keep what I typed'),
        });
        if (gen !== contactsSuggestGen || identitySaveBusy) return;
        if (use) applyContact(first);
      }
    } catch (e) {
      // Contacts optional — continue with typed values.
    }
  }

  const addPerson = document.getElementById('crm-add-person');
  if (addPerson) {
    addPerson.addEventListener('click', () => {
      fillPersonForm('add', null);
      rememberIdentityOpener(document.activeElement instanceof HTMLElement ? document.activeElement : addPerson);
      openExclusiveForm('person');
    });
  }

  const nameForm = document.getElementById('crm-company-name-form');
  const changeCompanyBtn = document.getElementById('crm-change-company');
  if (changeCompanyBtn && nameForm) {
    changeCompanyBtn.addEventListener('click', () => {
      if (!nameForm.hidden) {
        closeIdentityForms();
        changeCompanyBtn.focus();
        identityFormOpener = null;
        return;
      }
      const title = document.getElementById('crm-identity-title');
      setFieldValue('crm-identity-name', title ? (title.textContent || '').trim() : '');
      rememberIdentityOpener(changeCompanyBtn);
      openExclusiveForm('name');
    });
    const nameCancel = document.getElementById('crm-company-name-cancel');
    if (nameCancel) {
      nameCancel.addEventListener('click', () => {
        closeIdentityForms();
        restoreIdentityOpenerFocus();
      });
    }
    nameForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const name = fieldValue('crm-identity-name');
      if (name === '') {
        showIdentityError('crm-company-name-form-error', { message: t('Enter the company name.') });
        return;
      }
      await withIdentitySave(nameForm, 'crm-company-name-form-error', async () => {
        await Api.put('/companies/' + companyId, {
          name: name,
          updatedAt: companyUpdatedAt() || null,
        });
        if (liveRegion()) liveRegion().textContent = t('Company saved.');
        location.reload();
      });
    });
  }

  const editAddressBtn = document.getElementById('crm-edit-address');
  const addressForm = document.getElementById('crm-address-form');
  const addressCancel = document.getElementById('crm-address-cancel');
  if (editAddressBtn && addressForm) {
    editAddressBtn.addEventListener('click', () => {
      if (!addressForm.hidden) {
        closeIdentityForms();
        editAddressBtn.focus();
        identityFormOpener = null;
        return;
      }
      rememberIdentityOpener(editAddressBtn);
      openExclusiveForm('address');
    });
    if (addressCancel) {
      addressCancel.addEventListener('click', () => {
        closeIdentityForms();
        restoreIdentityOpenerFocus();
      });
    }
    addressForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      await withIdentitySave(addressForm, 'crm-address-form-error', async () => {
        await Api.put('/companies/' + companyId, {
          addrStreet: fieldValue('crm-address-street'),
          addrZip: fieldValue('crm-address-zip'),
          addrCity: fieldValue('crm-address-city'),
          country: fieldValue('crm-address-country') || 'DE',
          updatedAt: companyUpdatedAt() || null,
        });
        if (liveRegion()) liveRegion().textContent = t('Address saved.');
        location.reload();
      });
    });
  }

  const personForm = document.getElementById('crm-person-form');
  const personCancel = document.getElementById('crm-person-cancel');
  const personNameInput = document.getElementById('crm-person-name');
  hostPersonDialog();
  window.addEventListener('modal-close', (ev) => {
    const id = ev && ev.detail ? ev.detail.modalId : '';
    if (id !== (PersonDlg.DIALOG_ID || 'crm-person-dialog')) return;
    bumpContactsSuggest();
    setPersonAddExpanded(false);
  });
  if (personForm) {
    if (personCancel) {
      personCancel.addEventListener('click', () => {
        closeIdentityForms();
        restoreIdentityOpenerFocus();
      });
    }
    if (personNameInput) {
      personNameInput.addEventListener('blur', () => {
        const mode = personForm.getAttribute('data-mode');
        const allow = typeof PersonDlg.shouldSuggestContacts === 'function'
          ? PersonDlg.shouldSuggestContacts(mode, identitySaveBusy, isPersonDialogOpen())
          : (mode === 'add' && isPersonDialogOpen());
        if (!allow) return;
        maybeApplyContactsMatch(personNameInput.value);
      });
    }
    personForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const name = fieldValue('crm-person-name');
      const email = fieldValue('crm-person-email');
      const phone = fieldValue('crm-person-phone');
      const role = fieldValue('crm-person-role');
      const checked = typeof PersonDlg.validatePersonFields === 'function'
        ? PersonDlg.validatePersonFields({ name: name, email: email, phone: phone })
        : null;
      if (checked && !checked.ok) {
        if (checked.code === 'name') {
          showIdentityError('crm-person-form-error', { message: t('Enter the contact name.') });
        } else if (checked.code === 'email') {
          showIdentityError('crm-person-form-error', { message: t('Enter a valid email address, for example name@example.com.') });
        } else {
          showIdentityError('crm-person-form-error', { message: t('Enter a phone number using digits, spaces and + ( ) / - only.') });
        }
        return;
      }
      if (!checked) {
        if (name === '') {
          showIdentityError('crm-person-form-error', { message: t('Enter the contact name.') });
          return;
        }
        if (email !== '' && !EMAIL_RE.test(email)) {
          showIdentityError('crm-person-form-error', { message: t('Enter a valid email address, for example name@example.com.') });
          return;
        }
        if (phone !== '' && !PHONE_RE.test(phone)) {
          showIdentityError('crm-person-form-error', { message: t('Enter a phone number using digits, spaces and + ( ) / - only.') });
          return;
        }
      }
      const mode = personForm.getAttribute('data-mode') || 'add';
      const personId = parseInt(personForm.getAttribute('data-person-id') || '0', 10);
      bumpContactsSuggest();
      await withIdentitySave(personForm, 'crm-person-form-error', async () => {
        if (mode === 'edit' && personId > 0) {
          const putBody = typeof PersonDlg.buildUpdatePayload === 'function'
            ? PersonDlg.buildUpdatePayload({ name: name, email: email, phone: phone, role: role }, personForm.getAttribute('data-updated-at') || null)
            : {
              name: name,
              email: email,
              phone: phone,
              role: role,
              updatedAt: personForm.getAttribute('data-updated-at') || null,
            };
          await Api.put('/persons/' + personId, putBody);
          if (liveRegion()) liveRegion().textContent = t('Person updated.');
        } else {
          const payload = typeof PersonDlg.buildCreatePayload === 'function'
            ? PersonDlg.buildCreatePayload({ name: name, email: email, phone: phone, role: role }, personContactsUid)
            : { name: name, email: email, phone: phone, role: role, isPrimary: false };
          if (!payload.contactsUid && personContactsUid) payload.contactsUid = personContactsUid;
          await Api.post('/companies/' + companyId + '/persons', payload);
          if (liveRegion()) liveRegion().textContent = t('Person added.');
        }
        location.reload();
      });
    });
  }

  document.querySelectorAll('[data-crm-edit-person]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const row = btn.closest('[data-person-id]');
      rememberIdentityOpener(btn);
      fillPersonForm('edit', row);
      openExclusiveForm('person');
    });
  });

  document.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape' || identitySaveBusy) return;
    if (isPersonDialogOpen()) return;
    const openForm = ['crm-company-name-form', 'crm-address-form']
      .map((id) => document.getElementById(id))
      .find((f) => f && !f.hidden);
    if (!openForm) return;
    ev.preventDefault();
    closeIdentityForms();
    restoreIdentityOpenerFocus();
  });

  if (location.hash === '#crm-address-form' || location.hash === '#crm-company-address') {
    openExclusiveForm('address');
  } else if (location.hash === '#crm-person-form') {
    fillPersonForm('add', null);
    openExclusiveForm('person');
  } else if (location.hash === '#crm-company-name-form') {
    openExclusiveForm('name');
  }

  async function openLogEmailDialog() {
    let persons = [];
    try {
      persons = JSON.parse(root.getAttribute('data-crm-persons') || '[]');
    } catch (e) {
      persons = [];
    }
    if (!Array.isArray(persons)) {
      persons = [];
    }
    const withEmail = persons.filter((p) => String((p && p.email) || '').trim() !== '');
    if (persons.length === 0) {
      // Bachus: never dead-end — offer Add person in one tap, then return here.
      const goAdd = await C.confirmDialog({
        title: t('Add a person first'),
        message: t('Add someone under People with an email — then you can log what you wrote with them in one step.'),
        confirmLabel: t('Add person'),
        cancelLabel: t('Not now'),
      });
      if (goAdd) {
        const addBtn = document.getElementById('crm-add-person');
        if (addBtn) {
          addBtn.click();
        } else {
          const peopleJump = document.querySelector('.crm-jump-nav__link[href="#crm-persons"]');
          if (peopleJump) peopleJump.click();
        }
      }
      return;
    }
    if (withEmail.length === 0) {
      const goCall = await C.confirmDialog({
        title: t('Add an email first'),
        message: t('Edit a person and add their email — or tap Call tomorrow if you spoke by phone.'),
        confirmLabel: t('Call tomorrow'),
        cancelLabel: t('Not now'),
      });
      if (goCall) {
        const callBtn = document.getElementById('crm-schedule-callback');
        if (callBtn) callBtn.click();
      }
      return;
    }
    const personOptions = withEmail.map((p) => ({
      value: String(p.id),
      label: (p.name || p.email) + (p.email ? ' <' + p.email + '>' : ''),
    }));
    const fields = [];
    if (personOptions.length > 1) {
      fields.push({
        name: 'personId',
        label: t('With whom'),
        type: 'select',
        options: personOptions,
        value: personOptions[0].value,
        required: true,
      });
    }
    fields.push(
      { name: 'subject', label: t('Subject'), required: true, hint: t('What was the email about?') },
      {
        name: 'direction',
        label: t('Direction'),
        type: 'select',
        value: 'outbound',
        options: [
          { value: 'outbound', label: t('You wrote them') },
          { value: 'inbound', label: t('They wrote you') },
        ],
        advanced: true,
      },
      { name: 'snippet', label: t('Short note (optional)'), type: 'textarea', advanced: true },
    );
    const data = await promptFields(t('Log talk'), fields);
    if (!data) return;
    const personId = personOptions.length === 1
      ? parseInt(personOptions[0].value, 10)
      : parseInt(data.personId, 10);
    try {
      await Api.post('/companies/' + companyId + '/emails', {
        personId: personId,
        direction: data.direction || 'outbound',
        subject: data.subject,
        snippet: data.snippet || '',
      });
      location.reload();
    } catch (e) {
      await C.alertDialog({ title: t('Could not log email'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    }
  }

  document.querySelectorAll('[data-crm-log-email], #crm-log-email, #crm-log-email-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      openLogEmailDialog();
    });
  });

  document.querySelectorAll('[data-crm-unlink-contacts]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const personId = parseInt(btn.getAttribute('data-crm-unlink-contacts') || '0', 10);
      if (!personId) return;
      const ok = await C.confirmDialog({
        title: t('Unlink from Contacts?'),
        message: t('Removes the address-book link only. The contact stays in Contacts.'),
        confirmLabel: t('Unlink'),
        cancelLabel: t('Cancel'),
      });
      if (!ok) return;
      try {
        const row = btn.closest('[data-person-id]');
        const updatedAt = row ? (row.getAttribute('data-updated-at') || '') : '';
        await Api.put('/persons/' + personId, { contactsUid: null, updatedAt });
        const live = document.getElementById('crm-live-region');
        if (live) {
          live.textContent = t('Contacts link removed.');
        }
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not unlink'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });



  document.querySelectorAll('[data-crm-remove-person]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const personId = parseInt(btn.getAttribute('data-crm-remove-person') || '0', 10);
      if (!personId) return;
      const row = btn.closest('[data-person-id]');
      const personName = (row && row.getAttribute('data-person-name')) || '';
      const ok = await C.confirmDialog({
        title: t('Remove this person?'),
        message: personName
          ? t('Removes “%s” from this company in CustomerCheck. The Contacts app entry stays.', [personName])
          : t('Removes them from this company in CustomerCheck. The Contacts app entry stays.'),
        confirmLabel: t('Remove'),
        cancelLabel: t('Cancel'),
        variant: 'danger',
        optionalCheckbox: true,
        optionalCheckboxChecked: true,
        optionalCheckboxLabel: t('Put their shared mail back in Assign'),
      });
      if (!ok || ok.confirmed === false) return;
      const returnMailToAssign = !!(ok && ok.optionalChecked);
      try {
        const updatedAt = row ? (row.getAttribute('data-updated-at') || '') : '';
        const res = await Api.del('/persons/' + personId, { updatedAt, returnMailToAssign });
        const data = (res && res.data) ? res.data : res;
        const mailN = data && data.mailReturnedToAssign ? parseInt(data.mailReturnedToAssign, 10) : 0;
        const live = document.getElementById('crm-live-region');
        if (live) {
          if (mailN > 0) {
            live.textContent = personName
              ? t('Removed “%s”. %s mail item(s) returned to Assign.', [personName, String(mailN)])
              : t('Person removed. %s mail item(s) returned to Assign.', [String(mailN)]);
          } else {
            live.textContent = personName
              ? t('Removed “%s”.', [personName])
              : t('Person removed.');
          }
        }
        location.reload();
      } catch (e) {
        await C.alertDialog({
          title: t('Could not remove person'),
          message: (e.payload && e.payload.message) || e.message,
          variant: 'danger',
        });
      }
    });
  });

  document.querySelectorAll('[data-crm-open-contacts]').forEach((link) => {
    link.addEventListener('click', () => {
      const name = link.getAttribute('data-person-name') || '';
      if (liveRegion() && name) {
        liveRegion().textContent = t('Find: %s', [name]);
      }
    });
  });

  document.querySelectorAll('[data-crm-person-last-touch]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const feedJump = document.querySelector('.crm-jump-nav__link[href="#crm-communication"]');
      if (feedJump) feedJump.click();
      else {
        const el = document.getElementById('crm-communication');
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  function liveRegion() {
    return document.getElementById('crm-live-region');
  }

  function workspaceName() {
    return root ? (root.getAttribute('data-workspace-name') || '') : '';
  }

  /** Plain-language feed kind — never raw tokens like shared_mail (§19 granny test). */
  function feedKindLabel(kind) {
    const k = String(kind || '');
    if (k === 'shared_mail') return t('Shared mail');
    if (k === 'mail_related') return t('Private mail');
    if (k === 'email_touch') return t('Logged email');
    if (k === 'activity') return t('Activity');
    if (k === 'note') return t('Note');
    return k || t('Update');
  }

  function buyingRoleOptionsFromDom() {
    const sel = document.querySelector('[data-crm-buying-role]');
    if (!sel) {
      return [
        { value: '', label: t('None') },
        { value: 'economic_buyer', label: t('Budget decision-maker') },
        { value: 'champion', label: t('Champion') },
        { value: 'day_to_day', label: t('Day-to-day contact') },
        { value: 'technical', label: t('Technical contact') },
        { value: 'blocker', label: t('Blocker') },
        { value: 'other', label: t('Other') },
      ];
    }
    return Array.from(sel.options).map((o) => ({ value: o.value, label: o.textContent || o.value }));
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[<>&"]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[ch]));
  }

  document.querySelectorAll('[data-crm-buying-role]').forEach((sel) => {
    sel.addEventListener('change', async () => {
      const personId = parseInt(sel.getAttribute('data-crm-buying-role') || '0', 10);
      if (!personId) return;
      const row = sel.closest('[data-person-id]');
      const updatedAt = sel.getAttribute('data-updated-at') || (row ? row.getAttribute('data-updated-at') : '') || '';
      sel.disabled = true;
      try {
        const res = await Api.put('/persons/' + personId, {
          buyingRole: sel.value || null,
          updatedAt: updatedAt || null,
        });
        const data = res.data || res;
        if (data && data.updatedAt) {
          sel.setAttribute('data-updated-at', data.updatedAt);
          if (row) row.setAttribute('data-updated-at', data.updatedAt);
        }
        if (liveRegion()) liveRegion().textContent = t('Role in buying updated.');
      } catch (e) {
        await C.alertDialog({ title: t('Could not update role in buying'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        location.reload();
      } finally {
        sel.disabled = false;
      }
    });
  });

  const GlanceSummary = window.CustomerCheckGlanceSummary || null;
  const Company360Present = window.CustomerCheckCompany360Present || null;

  function billingStateLabel(state) {
    if (GlanceSummary && typeof GlanceSummary.billingStateLabel === 'function') {
      return GlanceSummary.billingStateLabel(state, t);
    }
    switch (state) {
      case 'ok': return t('Paid up');
      case 'attention': return t('Needs attention');
      case 'critical': return t('Overdue');
      default: return t('Unknown');
    }
  }

  function billingTone(state) {
    if (GlanceSummary && typeof GlanceSummary.billingTone === 'function') {
      return GlanceSummary.billingTone(state);
    }
    switch (state) {
      case 'ok': return 'success';
      case 'attention': return 'warning';
      case 'critical': return 'danger';
      default: return 'neutral';
    }
  }

  function safeInternalHref(href, fallback) {
    if (Company360Present && typeof Company360Present.safeInternalHref === 'function') {
      return Company360Present.safeInternalHref(href, fallback);
    }
    const fb = fallback == null ? '/' : String(fallback);
    const s = String(href == null ? '' : href).trim();
    if (!s) return fb;
    const lower = s.toLowerCase();
    if (lower.indexOf('javascript:') === 0 || lower.indexOf('data:') === 0 || lower.indexOf('vbscript:') === 0) return fb;
    if (s.charAt(0) !== '/' || s.indexOf('//') === 0 || s.indexOf('\\') !== -1) return fb;
    return s;
  }

  function paintBillingSummary(body, billing) {
    if (!body || !billing) return;
    if (Company360Present && typeof Company360Present.buildBillingSummaryTiles === 'function'
      && typeof Company360Present.renderBillingSummaryTiles === 'function') {
      const tiles = Company360Present.buildBillingSummaryTiles(billing, {
        t: t,
        billingStateLabel: billingStateLabel,
        billingTone: billingTone,
      });
      Company360Present.renderBillingSummaryTiles(body, tiles, t, billing);
      return;
    }
    const b = billing;
    const badge = b.state === 'critical' ? 'danger' : (b.state === 'attention' ? 'warning' : (b.state === 'ok' ? 'success' : 'neutral'));
    body.textContent = '';
    const p = document.createElement('p');
    const span = document.createElement('span');
    span.className = 'crm-badge crm-badge--' + badge;
    span.textContent = billingStateLabel(b.state || 'unknown');
    p.appendChild(span);
    p.appendChild(document.createTextNode(' · ' + t('Overdue') + ': ' + (b.overdueCount || 0) + ' · ' + t('Open') + ': ' + (b.openCount || 0)));
    body.appendChild(p);
  }

  function coverageMissingLabel(slot) {
    if (GlanceSummary && typeof GlanceSummary.coverageMissingLabel === 'function') {
      return GlanceSummary.coverageMissingLabel(slot, t);
    }
    switch (slot) {
      case 'economic_buyer': return t('Budget decision-maker');
      case 'champion': return t('Champion');
      case 'day_to_day_or_technical': return t('Day-to-day or technical contact');
      default: return slot ? String(slot) : '';
    }
  }

  /**
   * Mount command-center glance as design-system KPI tiles (crm-stat-strip).
   * Falls back to a plain dl if the shared helper failed to load.
   */
  function paintGlanceSummary(body, dto) {
    if (!body || !dto) {
      return;
    }
    if (GlanceSummary && typeof GlanceSummary.buildGlanceSummaryTiles === 'function'
      && typeof GlanceSummary.renderGlanceSummaryTiles === 'function') {
      const tiles = GlanceSummary.buildGlanceSummaryTiles(dto, {
        t: t,
        formatDisplayDate: formatDisplayDate,
      });
      GlanceSummary.renderGlanceSummaryTiles(body, tiles, t);
      return;
    }
    // Fallback — keep labels/values readable without the shared module.
    const next = dto.nextAction || {};
    const last = dto.lastDiscussion || {};
    const runway = dto.runway || {};
    const billing = dto.billing || {};
    const primary = dto.primaryPerson;
    const cov = dto.coverage || {};
    const nextTouch = dto.nextTouchOn || '';
    const strip = document.createElement('div');
    strip.className = 'crm-glance-dl crm-stat-strip';
    strip.setAttribute('role', 'list');
    strip.setAttribute('aria-label', t('At a glance'));
    const coverageLabel = cov.gap ? t('Needs links') : (cov.covered ? t('Covered') : t('Coverage'));
    const missingText = (cov.missingLabels && cov.missingLabels.length)
      ? cov.missingLabels.join(', ')
      : ((cov.missing || []).map(coverageMissingLabel).filter(Boolean).join(', '));
    const runwayText = runway.validUntil
      ? (formatDisplayDate(runway.validUntil) + (runway.daysLeft != null ? ' · ' + t('%s days left', [String(runway.daysLeft)]) : ''))
      : '—';
    const nextMeta = last.summary
      ? (t('Last talk') + ': ' + last.summary + (last.at ? ' · ' + formatDisplayDate(last.at) : ''))
      : (nextTouch ? (t('Next touch') + ': ' + formatDisplayDate(nextTouch)) : '');
    const moneyMeta = (billing.overdueCount ? (String(billing.overdueCount) + ' ' + t('overdue')) : '')
      || (cov.gap ? (missingText || coverageLabel) : '');
    const rows = [
      [t('Primary contact'), primary ? ((primary.name || '') + (primary.role ? ' · ' + primary.role : '')) : t('No primary person'), ''],
      [t('Next action'), next.label || t('No urgent action'), nextMeta],
      [t('Money'), billingStateLabel(billing.state || 'unknown'), moneyMeta],
      [t('Renewal'), runwayText, ''],
    ];
    rows.forEach(function (row) {
      const wrap = document.createElement('div');
      wrap.className = 'crm-stat-tile crm-stat-tile--neutral';
      wrap.setAttribute('role', 'listitem');
      const label = document.createElement('span');
      label.className = 'crm-stat-tile__label';
      label.textContent = row[0];
      const value = document.createElement('span');
      value.className = 'crm-stat-tile__value';
      value.textContent = row[1];
      wrap.appendChild(label);
      wrap.appendChild(value);
      if (row[2]) {
        const meta = document.createElement('span');
        meta.className = 'crm-stat-tile__meta';
        meta.textContent = row[2];
        wrap.appendChild(meta);
      }
      strip.appendChild(wrap);
    });
    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
    body.appendChild(strip);
  }

  const addActivity = document.getElementById('crm-add-activity');
  if (addActivity) {
    addActivity.addEventListener('click', async () => {
      const today = localYmd();
      const data = await promptFields(t('Add to-do'), [
        {
          name: 'title',
          label: t('What should you remember?'),
          type: 'textarea',
          rows: 2,
          required: true,
          maxlength: 255,
          hint: t('Example: Call Anna about renewal.'),
        },
        { name: 'dueDate', label: t('Due date'), type: 'date', required: true, value: today },
        {
          name: 'type',
          label: t('Type'),
          type: 'select',
          value: 'todo',
          advanced: true,
          options: [
            { value: 'call', label: t('Call') },
            { value: 'email', label: t('Email follow-up') },
            { value: 'meeting', label: t('Meeting') },
            { value: 'todo', label: t('To-do') },
          ],
        },
        { name: 'alsoOnPhone', label: t('Also send to Tasks'), type: 'checkbox', value: false, advanced: true },
        { name: 'toldCustomer', label: t('Told the customer'), type: 'checkbox', value: false, advanced: true },
      ]);
      if (!data) return;
      if (!data.dueDate) data.dueDate = today;
      if (!data.type) data.type = 'todo';
      try {
        if (data.toldCustomer) {
          await Api.post('/companies/' + companyId + '/promises', {
            title: data.title,
            dueOn: data.dueDate || null,
          });
          if (liveRegion()) liveRegion().textContent = t('Promise added.');
          const promisesPanel = document.getElementById('crm-promises');
          if (promisesPanel) promisesPanel.open = true;
          await loadPromises();
          return;
        }
        if (data.alsoOnPhone) {
          await Api.post('/companies/' + companyId + '/tasks', {
            title: data.title,
            type: data.type || 'todo',
            dueDate: data.dueDate || today,
            pushToTasks: true,
          });
          if (liveRegion()) liveRegion().textContent = t('Sent to Tasks.');
          const phonePanel = document.getElementById('crm-open-tasks');
          if (phonePanel) phonePanel.open = true;
          await loadCommandCenter();
          location.reload();
          return;
        }
        await Api.post('/activities', {
          companyId: companyId,
          title: data.title,
          dueDate: data.dueDate,
          type: data.type || 'todo',
        });
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not add reminder'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  function parseDealFormCompanyMeta() {
    try {
      const el = document.getElementById('crm-deal-form-company-meta');
      if (!el) {
        return { companyId: companyId, companyName: '' };
      }
      return JSON.parse(el.textContent || '{}');
    } catch (e) {
      return { companyId: companyId, companyName: '' };
    }
  }

  function openAddDealDialog() {
    const NewDeal = window.CustomerCheckNewDealForm;
    if (!NewDeal || typeof NewDeal.open !== 'function' || !C) {
      return;
    }
    const meta = parseDealFormCompanyMeta();
    NewDeal.open({
      mode: 'company360',
      fixedCompanyId: parseInt(String(meta.companyId || companyId || '0'), 10) || companyId,
      fixedCompanyName: String(meta.companyName || ''),
      components: C,
      api: Api,
      t: t,
      escapeHtml: escapeHtml,
      activeWorkspaceId: function () {
        const stack = document.querySelector('.crm-page-stack[data-company-id]');
        const wsId = stack ? parseInt(stack.getAttribute('data-workspace-id') || '0', 10) : 0;
        return wsId > 0 ? wsId : 0;
      },
      onReload: function () { window.location.reload(); },
    });
  }

  const addDeal = document.getElementById('crm-add-deal');
  if (addDeal) {
    addDeal.addEventListener('click', openAddDealDialog);
  }
  document.querySelectorAll('[data-crm-trigger="crm-add-deal"]').forEach((btn) => {
    btn.addEventListener('click', openAddDealDialog);
  });

  document.querySelectorAll('[data-crm-edit-chase]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const row = btn.closest('[data-deal-id]');
      if (!row) return;
      const dealId = row.getAttribute('data-deal-id');
      const cents = parseInt(String(row.getAttribute('data-value-cents') || '0'), 10) || 0;
      const valueEur = cents > 0 ? String((cents / 100).toFixed(2)) : '';
      // Edit keeps title + notes primary (tweak what you wrote); expert fields under More.
      const data = await promptFields(t('Edit deal'), [
        {
          name: 'title',
          label: t('What is this deal?'),
          required: true,
          value: row.getAttribute('data-title') || '',
          maxlength: 255,
        },
        {
          name: 'notes',
          label: t('Notes'),
          type: 'textarea',
          value: row.getAttribute('data-notes') || '',
          maxlength: 4000,
          hint: t('Internal notes for your team — the customer does not see these.'),
        },
        {
          name: 'valueEur',
          label: t('Value (EUR, optional)'),
          type: 'number',
          min: 0,
          step: '0.01',
          value: valueEur,
          advanced: true,
        },
        {
          name: 'products',
          label: t('Products (optional)'),
          value: row.getAttribute('data-products') || '',
          advanced: true,
          maxlength: 255,
        },
        {
          name: 'expectedClose',
          label: t('Expected close'),
          type: 'date',
          value: row.getAttribute('data-expected-close') || '',
          advanced: true,
        },
      ]);
      if (!data) return;
      const title = String(data.title || '').trim();
      if (!title) {
        await C.alertDialog({
          title: t('Almost there'),
          message: t('Enter a short title for this deal.'),
          variant: 'info',
        });
        return;
      }
      const valueCents = Math.round(parseFloat(data.valueEur || '0') * 100) || 0;
      try {
        await Api.put('/deals/' + dealId, {
          title,
          notes: String(data.notes || '').trim(),
          valueCents,
          products: data.products || '',
          expectedClose: String(data.expectedClose || '').trim() || null,
          updatedAt: row.getAttribute('data-updated-at') || null,
        });
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Deal saved.');
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not save deal'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  async function loadWorkspaceMemberOptions() {
    const stack = document.querySelector('.crm-page-stack[data-company-id]');
    const wsId = stack ? parseInt(stack.getAttribute('data-workspace-id') || '0', 10) : 0;
    const opts = [{ value: '', label: t('Nobody yet') }];
    if (!(wsId > 0)) {
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
      /* assignee optional — empty list still allows unassigned tasks */
    }
    return opts;
  }

  async function openDealChecklist(dealId, dealTitle) {
    const dlgId = 'crm-deal-checklist-' + dealId;
    const Ux = window.CustomerCheckDealChecklistUx;
    if (!Ux) {
      await C.alertDialog({ title: t('Could not open checklist'), message: t('Something went wrong. Reload the page and try again.'), variant: 'danger' });
      return;
    }
    if (!Ux) {
      await C.alertDialog({
        title: t('Could not open checklist'),
        message: t('Reload the page and try again.'),
        variant: 'danger',
      });
      return;
    }
    const uxHelpers = { t: t, escapeHtml: escapeHtml };
    const memberOpts = await loadWorkspaceMemberOptions();
    const doneNote = Ux.DEFAULT_CHECKLIST_DONE_NOTE || 'Done';

    const loadTemplates = async () => {
      try {
        const res = await Api.get('/checklist-templates');
        return Array.isArray(res.data) ? res.data : [];
      } catch (e) {
        return [];
      }
    };

    const applyTemplate = async (templateId, mode, refresh) => {
      const useMode = mode === 'replace' ? 'replace' : 'append';
      if (useMode === 'replace') {
        const ok = await C.confirmDialog({
          title: t('Replace open tasks?'),
          message: t('Open checklist tasks on this deal will be removed (including their attachments) and replaced by the template. Finished tasks stay in history.'),
          confirmLabel: t('Replace open'),
          cancelLabel: t('Cancel'),
          requireTypedConfirm: Ux.requireTypedReplaceConfirm(),
        });
        if (!ok) return;
      }
      try {
        await Api.post('/deals/' + dealId + '/checklist/from-template', {
          templateId: parseInt(String(templateId), 10),
          mode: useMode,
        });
        await refresh();
      } catch (e) {
        await C.alertDialog({
          title: t('Could not apply template'),
          message: (e.payload && e.payload.message) || e.message,
          variant: 'danger',
        });
      }
    };

    const completeItem = async (itemId, evidenceNote, updatedAt, refresh) => {
      try {
        await Api.post('/deal-checklist-items/' + itemId + '/complete', {
          evidenceNote: String(evidenceNote || doneNote).trim() || doneNote,
          updatedAt: updatedAt,
        });
        await refresh();
      } catch (e) {
        await C.alertDialog({
          title: t('Could not mark done'),
          message: (e.payload && e.payload.message) || e.message,
          variant: 'danger',
        });
      }
    };

    const renderBody = async () => {
      let payload;
      try {
        const res = await Api.get('/deals/' + dealId + '/checklist');
        payload = res.data || { items: [] };
      } catch (e) {
        await C.alertDialog({
          title: t('Could not open checklist'),
          message: (e.payload && e.payload.message) || e.message,
          variant: 'danger',
        });
        return null;
      }
      const items = Array.isArray(payload.items) ? payload.items : [];
      const empty = items.length === 0;
      let itemsHtml = '';
      if (!empty) {
        itemsHtml = '<ul class="crm-deal-checklist" role="list">';
        items.forEach((it) => {
          itemsHtml += Ux.buildChecklistItemRowHtml(it, uxHelpers);
        });
        itemsHtml += '</ul>';
      }
      let startersHtml = '';
      if (empty) {
        const templates = await loadTemplates();
        startersHtml = Ux.buildTemplateStarterButtonsHtml(templates, uxHelpers);
      }
      return Ux.buildChecklistPanelHtml(
        { dealId: dealId, itemsHtml: itemsHtml, startersHtml: startersHtml, empty: empty },
        uxHelpers,
      );
    };

    const content = await renderBody();
    if (content === null) return;
    await C.openDialog({
      id: dlgId,
      title: t('Checklist') + (dealTitle ? ' — ' + dealTitle : ''),
      content: content,
      size: 'lg',
    });

    const bind = () => {
      const panel = document.querySelector('#' + dlgId + ' .crm-deal-checklist-panel');
      if (!panel) return;
      const refresh = async () => {
        const html = await renderBody();
        if (html === null) return;
        panel.outerHTML = html;
        bind();
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Checklist updated.');
      };
      const closeBtn = document.querySelector('#' + dlgId + ' [data-cl-close]');
      if (closeBtn) {
        closeBtn.addEventListener('click', () => C.closeModal(document.getElementById(dlgId)));
      }
      const quick = document.querySelector('#' + dlgId + ' [data-cl-quickadd]');
      if (quick) {
        quick.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const input = quick.querySelector('input[name="title"]');
          const title = input ? String(input.value || '').trim() : '';
          if (!title) {
            if (input) input.focus();
            return;
          }
          const submitBtn = quick.querySelector('button[type="submit"]');
          if (submitBtn) submitBtn.disabled = true;
          try {
            await Api.post('/deals/' + dealId + '/checklist', { title: title });
            await refresh();
          } catch (e) {
            if (submitBtn) submitBtn.disabled = false;
            await C.alertDialog({
              title: t('Could not add task'),
              message: (e.payload && e.payload.message) || e.message,
              variant: 'danger',
            });
          }
        });
      }
      document.querySelectorAll('#' + dlgId + ' [data-cl-apply-tmpl]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          btn.disabled = true;
          await applyTemplate(btn.getAttribute('data-cl-apply-tmpl'), 'append', refresh);
          btn.disabled = false;
        });
      });
      const tmplBtn = document.querySelector('#' + dlgId + ' [data-cl-template]');
      if (tmplBtn) {
        tmplBtn.addEventListener('click', async () => {
          const templates = await loadTemplates();
          if (templates.length === 0) {
            await C.alertDialog({
              title: t('No templates yet'),
              message: t('Ask a workspace manager to add checklist templates, or add tasks by hand.'),
              variant: 'info',
            });
            return;
          }
          const data = await promptFields(t('Start from template'), [
            {
              name: 'templateId',
              label: t('Template'),
              type: 'select',
              required: true,
              options: templates.map((tm) => ({
                value: String(tm.id),
                label: String(tm.name || tm.id),
              })),
            },
            {
              name: 'mode',
              label: t('If this deal already has tasks'),
              type: 'select',
              value: 'append',
              advanced: true,
              options: [
                { value: 'append', label: t('Keep existing and add these') },
                { value: 'replace', label: t('Replace open tasks with this template') },
              ],
            },
          ]);
          if (!data) return;
          await applyTemplate(data.templateId, data.mode === 'replace' ? 'replace' : 'append', refresh);
        });
      }
      document.querySelectorAll('#' + dlgId + ' [data-cl-done]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const itemId = btn.getAttribute('data-cl-done');
          const row = btn.closest('[data-item-id]');
          const updatedAt = row ? row.getAttribute('data-updated-at') : null;
          btn.disabled = true;
          await completeItem(itemId, doneNote, updatedAt, refresh);
          btn.disabled = false;
        });
      });
      document.querySelectorAll('#' + dlgId + ' [data-cl-done-note]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const itemId = btn.getAttribute('data-cl-done-note');
          const row = btn.closest('[data-item-id]');
          const updatedAt = row ? row.getAttribute('data-updated-at') : null;
          const data = await promptFields(t('Done with note'), [
            {
              name: 'evidenceNote',
              label: t('What did you do?'),
              type: 'textarea',
              required: true,
              maxlength: 2000,
              value: doneNote,
              hint: t('A short note is enough. Or attach a file, then tap Done.'),
            },
          ]);
          if (!data) return;
          await completeItem(itemId, data.evidenceNote, updatedAt, refresh);
        });
      });
      document.querySelectorAll('#' + dlgId + ' [data-cl-assign]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const itemId = btn.getAttribute('data-cl-assign');
          const row = btn.closest('[data-item-id]');
          const updatedAt = row ? row.getAttribute('data-updated-at') : null;
          const data = await promptFields(t('Assign task'), [
            { name: 'assigneeUserId', label: t('Assign to'), type: 'select', options: memberOpts },
            { name: 'dueOn', label: t('Due date'), type: 'date', advanced: true },
          ]);
          if (!data) return;
          try {
            await Api.put('/deal-checklist-items/' + itemId, {
              assigneeUserId: data.assigneeUserId || null,
              dueOn: data.dueOn || null,
              updatedAt: updatedAt,
            });
            await refresh();
          } catch (e) {
            await C.alertDialog({
              title: t('Could not assign'),
              message: (e.payload && e.payload.message) || e.message,
              variant: 'danger',
            });
          }
        });
      });
      document.querySelectorAll('#' + dlgId + ' [data-cl-file]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const itemId = btn.getAttribute('data-cl-file');
          const Picker = window.CustomerCheckFolderPicker;
          let fileId = 0;
          if (Picker && typeof Picker.pickFile === 'function') {
            const picked = await Picker.pickFile();
            if (picked && picked.fileId) {
              fileId = parseInt(String(picked.fileId), 10) || 0;
            }
          }
          if (!(fileId > 0)) {
            await C.alertDialog({
              title: t('Pick a file'),
              message: t('Use the file picker to attach a Nextcloud file.'),
              variant: 'info',
            });
            return;
          }
          try {
            await Api.post('/deal-checklist-items/' + itemId + '/files', { fileId: fileId });
            await refresh();
          } catch (e) {
            await C.alertDialog({
              title: t('Could not attach file'),
              message: (e.payload && e.payload.message) || e.message,
              variant: 'danger',
            });
          }
        });
      });
    };
    bind();
  }

  document.querySelectorAll('[data-crm-deal-checklist]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const row = btn.closest('[data-deal-id]');
      const dealId = btn.getAttribute('data-crm-deal-checklist') || (row && row.getAttribute('data-deal-id'));
      const title = row ? row.getAttribute('data-title') || '' : '';
      if (!dealId) return;
      await openDealChecklist(dealId, title);
    });
  });

  function durationMinuteOptions() {
    const DP = window.CustomerCheckDatepicker;
    const mins = (DP && DP.DURATION_MINUTES) ? DP.DURATION_MINUTES : [15, 30, 45, 60, 90, 120, 180, 240, 360, 480];
    return mins.map((m) => ({
      value: String(m),
      label: (DP && typeof DP.formatDurationLabel === 'function') ? DP.formatDurationLabel(m) : String(m),
    }));
  }

  async function openLogHours(agreementId) {
    const today = localYmd();
    const data = await promptFields(t('Log hours'), [
      { name: 'entryDate', label: t('Date'), type: 'date', required: true, value: today },
      {
        name: 'minutes',
        label: t('Minutes'),
        type: 'select',
        required: true,
        value: '60',
        options: durationMinuteOptions(),
        hint: t('Steps of 15 minutes. Default is one hour.'),
      },
      {
        name: 'topic',
        label: t('Topic'),
        type: 'textarea',
        rows: 2,
        required: true,
        maxlength: 255,
        hint: t('What did you work on? A short sentence is fine.'),
      },
    ]);
    if (!data) return;
    if (!data.entryDate) data.entryDate = today;
    let minutes = parseInt(data.minutes, 10);
    const DP = window.CustomerCheckDatepicker;
    if (DP && typeof DP.snapDurationMinutes === 'function') {
      minutes = DP.snapDurationMinutes(minutes);
    } else {
      if (!Number.isFinite(minutes) || minutes < 15) minutes = 15;
      minutes = Math.round(minutes / 15) * 15;
    }
    try {
      await Api.post('/agreements/' + agreementId + '/hours', {
        entryDate: data.entryDate,
        minutes: minutes,
        topic: data.topic,
      });
      if (liveRegion()) liveRegion().textContent = t('Hours logged');
      if (typeof loadCommandCenter === 'function') {
        await loadCommandCenter({ silent: true });
      }
    } catch (e) {
      await C.alertDialog({ title: t('Could not log hours'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    }
  }

  document.querySelectorAll('[data-crm-log-hours]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      await openLogHours(btn.getAttribute('data-crm-log-hours'));
    });
  });

  document.querySelectorAll('[data-crm-delivery-pack]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const agreementId = btn.getAttribute('data-crm-delivery-pack');
      try {
        const res = await Api.post('/agreements/' + agreementId + '/delivery-pack', {});
        const pack = res.data || {};
        const extraActions =
          '<button type="button" class="crm-btn crm-btn--secondary" id="crm-send-delivery">' + escapeHtml(t('Send from shared mailbox')) + '</button> ' +
          '<button type="button" class="crm-btn crm-btn--secondary" id="crm-copy-delivery">' + escapeHtml(t('Copy')) + '</button>';
        const content = (C && typeof C.buildMailPackDialogHtml === 'function')
          ? C.buildMailPackDialogHtml(pack, { t: t, extraActionsHtml: extraActions })
          : ('<p><strong>' + escapeHtml(pack.subject || '') + '</strong></p><pre class="crm-pre">' +
            escapeHtml(pack.bodyText || '') + '</pre><div class="crm-form-actions">' +
            '<a class="crm-btn crm-btn--primary" href="' + escapeHtml((C && C.safeExternalHref)
              ? C.safeExternalHref(pack.mailAppUrl || pack.mailtoUrl || '#')
              : '#') + '" rel="noopener noreferrer">' + escapeHtml(t('Open mail')) + '</a> ' + extraActions + '</div>');
        await C.openDialog({
          id: 'crm-delivery-pack',
          title: t('Delivery pack'),
          content: content,
          size: 'md',
        });
        const sendBtn = document.getElementById('crm-send-delivery');
        if (sendBtn && SharedMail) {
          sendBtn.addEventListener('click', async () => {
            sendBtn.disabled = true;
            const sent = await SharedMail.sendViaSharedMailbox(pack, { companyId: companyId });
            if (!sent) sendBtn.disabled = false;
            else sendBtn.textContent = t('Sent');
          });
        }
        const copyBtn = document.getElementById('crm-copy-delivery');
        if (copyBtn) {
          copyBtn.addEventListener('click', async () => {
            const text = (pack.subject || '') + '\n\n' + (pack.bodyText || '');
            try {
              await navigator.clipboard.writeText(text);
              const live = document.getElementById('crm-live-region');
              if (live) { live.textContent = t('Copied to clipboard.'); }
            } catch (e) {
              // §8.12 E1 — clipboard denied: show select-all text instead of failing silently
              await C.openDialog({
                id: 'crm-delivery-copy-fallback',
                title: t('Copy manually'),
                content: '<p>' + escapeHtml(t('Clipboard access was denied. Select all text below and copy it.')) + '</p>' +
                  '<textarea class="crm-input form-textarea" id="crm-delivery-fallback-text" rows="12" readonly>' +
                  escapeHtml(text) +
                  '</textarea>',
                size: 'md',
              });
              const ta = document.getElementById('crm-delivery-fallback-text');
              if (ta) { ta.focus(); ta.select(); }
            }
          });
        }
      } catch (e) {
        await C.alertDialog({ title: t('Delivery pack failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  document.querySelectorAll('[data-crm-partner-session], [data-crm-partner-mail]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const agreementId = btn.getAttribute('data-crm-partner-session') || btn.getAttribute('data-crm-partner-mail');
      const skipHoursConfirm = btn.hasAttribute('data-crm-partner-session');
      try {
        const res = await Api.post('/agreements/' + agreementId + '/partner-mail', { promptHours: true });
        const pack = res.data || {};
        if (SharedMail) {
          await SharedMail.offerPreparedMail(pack, { companyId: companyId, title: t('Partner mail') });
        } else {
          const composeUrl = pack.mailAppUrl || pack.mailtoUrl || '';
          if (composeUrl) {
            window.open(composeUrl, '_blank', 'noopener,noreferrer');
          }
        }
        if (pack.promptHours) {
          if (skipHoursConfirm) {
            await openLogHours(agreementId);
            return;
          }
          const log = await C.confirmDialog({
            title: t('Log hours?'),
            message: t('Log hours for this Partner session?'),
            confirmLabel: t('Log hours'),
            cancelLabel: t('Not now'),
            variant: 'info',
          });
          if (log && log.confirmed) {
            await openLogHours(agreementId);
          }
        }
      } catch (e) {
        await C.alertDialog({ title: t('Partner mail failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  document.querySelectorAll('[data-crm-dismiss-followups]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const dealId = btn.getAttribute('data-crm-dismiss-followups');
      const ok = await C.confirmDialog({
        title: t('Stop reminders?'),
        message: t('Mark remaining auto follow-up activities for this deal as done?'),
        confirmLabel: t('Dismiss'),
        cancelLabel: t('Cancel'),
        variant: 'warning',
      });
      if (!ok || !ok.confirmed) return;
      try {
        await Api.post('/deals/' + dealId + '/dismiss-followups', {});
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not dismiss'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  document.querySelectorAll('[data-crm-complete-activity]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (btn.disabled || btn.getAttribute('aria-busy') === 'true') {
        return;
      }
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      try {
        await Api.post('/activities/' + btn.getAttribute('data-crm-complete-activity') + '/done', {
          done: true,
          updatedAt: btn.getAttribute('data-updated-at') || null,
        });
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Activity marked done.');
        const item = btn.closest('.crm-todo-list__item');
        if (item) {
          item.classList.add('crm-todo-list__item--closed');
          const actions = item.querySelector('.crm-todo-list__actions');
          if (actions) {
            while (actions.firstChild) {
              actions.removeChild(actions.firstChild);
            }
            const badge = document.createElement('span');
            badge.className = 'crm-badge crm-badge--success';
            badge.textContent = t('Done');
            actions.appendChild(badge);
          }
        }
        if (typeof loadCommandCenter === 'function') {
          await loadCommandCenter({ silent: true });
        }
      } catch (e) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        await C.alertDialog({ title: t('Update failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  document.querySelectorAll('[data-crm-push-todo]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await Api.post('/activities/' + btn.getAttribute('data-crm-push-todo') + '/push-todo', {});
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Sent to Tasks.');
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Send to Tasks failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  document.querySelectorAll('[data-crm-renewal-compose]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const res = await Api.post('/companies/' + companyId + '/compose-template', {
          template: btn.getAttribute('data-crm-renewal-compose'),
          agreementId: parseInt(btn.getAttribute('data-agreement-id') || '0', 10) || null,
        });
        const pack = res.data || {};
        if (SharedMail) {
          await SharedMail.offerPreparedMail(pack, { companyId: companyId, title: t('Prepared message') });
        } else if (pack.mailAppUrl) {
          window.open(pack.mailAppUrl, '_blank');
        } else if (pack.mailtoUrl) {
          window.location.href = pack.mailtoUrl;
        }
      } catch (e) {
        await C.alertDialog({ title: t('Compose failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  function addYears(isoDate, years) {
    if (!isoDate) {
      const d = new Date();
      d.setFullYear(d.getFullYear() + years);
      return localYmd(d);
    }
    const parts = String(isoDate).split('-').map((n) => parseInt(n, 10));
    if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) {
      const d = new Date();
      d.setFullYear(d.getFullYear() + years);
      return localYmd(d);
    }
    const d = new Date(Date.UTC(parts[0] + years, parts[1] - 1, parts[2]));
    return utcYmd(d);
  }

  function paintHydrateFailure(targetId, loadingLabel) {
    const el = document.getElementById(targetId);
    if (!el) return;
    el.innerHTML = '';
    const callout = document.createElement('div');
    callout.className = 'crm-callout crm-callout--danger';
    callout.setAttribute('role', 'alert');
    const msg = document.createElement('p');
    msg.textContent = t('Some overview data could not be loaded.');
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'crm-btn crm-btn--primary';
    retry.textContent = t('Try again');
    retry.addEventListener('click', () => {
      el.innerHTML = '<p class="crm-muted">' + escapeHtml(loadingLabel) + '</p>';
      loadCommandCenter();
    });
    callout.appendChild(msg);
    callout.appendChild(retry);
    el.appendChild(callout);
  }

  async function renewAgreementFromRow(row) {
    const id = row.getAttribute('data-agreement-id') || row.getAttribute('data-id');
    const updatedAt = row.getAttribute('data-updated-at') || '';
    const prevUntil = row.getAttribute('data-valid-until') || '';
    const amountCents = parseInt(row.getAttribute('data-amount-cents') || '0', 10) || 0;
    const label = row.getAttribute('data-label') || '';
    const suggestedUntil = addYears(prevUntil, 1);
    const amountLabel = (amountCents / 100).toFixed(2) + ' €';
    const ok = await C.confirmDialog({
      title: t('Renew for one year?'),
      message: t('New end date: %1$s. Amount stays %2$s. Creates a new agreement and stops reminders on this one.', [suggestedUntil, amountLabel]),
      confirmLabel: t('Renew for one year'),
      cancelLabel: t('Cancel'),
    });
    if (!ok || ok.confirmed === false) return;
    try {
      await Api.post('/agreements/' + id + '/supersede', {
        validUntil: suggestedUntil,
        amountCents: amountCents,
        label: (label || '').trim() || undefined,
        updatedAt: updatedAt || null,
      });
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Agreement renewed.');
      location.reload();
    } catch (e) {
      await C.alertDialog({ title: t('Renew failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    }
  }

  async function cancelAgreementFromRow(row) {
    const id = row.getAttribute('data-agreement-id') || row.getAttribute('data-id');
    const updatedAt = row.getAttribute('data-updated-at') || '';
    const ok = await C.confirmDialog({
      title: t('Cancel this agreement?'),
      message: t('The agreement becomes cancelled and stops receiving renewal reminders.'),
      confirmLabel: t('Cancel agreement'),
      cancelLabel: t('Keep agreement'),
      variant: 'danger',
      requireReason: true,
      reasonLabel: t('Reason'),
    });
    if (!ok || ok.confirmed === false) return;
    const reason = String((ok && ok.reason) || '').trim();
    if (!reason) {
      await C.alertDialog({ title: t('Almost there'), message: t('Enter a short reason.'), variant: 'info' });
      return;
    }
    try {
      await Api.post('/agreements/' + id + '/cancel', {
        reason: reason,
        updatedAt: updatedAt || null,
      });
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Agreement cancelled.');
      location.reload();
    } catch (e) {
      await C.alertDialog({ title: t('Cancel failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    }
  }

  document.querySelectorAll('[data-crm-renew-agreement]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const row = btn.closest('[data-agreement-id]') || btn;
      await renewAgreementFromRow(row);
    });
  });

  document.querySelectorAll('[data-crm-cancel-agreement]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const row = btn.closest('[data-agreement-id]') || btn;
      await cancelAgreementFromRow(row);
    });
  });

  /* ── Command Center M5 ───────────────────────────────────────── */
  // Generation counters must be initialized before any hydrator runs.
  // Calling loadAccountPlan/loadPromises while these lets are still in the TDZ
  // throws outside try/catch and leaves the section stuck on “Loading…”.
  let commandCenterGeneration = 0;
  let accountPlanGeneration = 0;
  /** Visible “saved” flash so granny is not left staring at an unchanged form. */
  let accountPlanSavedUntil = 0;
  let promisesGeneration = 0;
  /** Drops stale Talk mail-body paints when ArrowUp/Down races preview POSTs. */
  let talkMailPreviewGeneration = 0;
  /** Aborts in-flight Talk preview fetch when the operator moves to another row. */
  let talkMailPreviewAbort = null;
  async function loadCommandCenter(opts) {
    if (!companyId || !Api) return;
    const silent = !!(opts && opts.silent);
    const live = document.getElementById('crm-live-region');
    const generation = ++commandCenterGeneration;
    try {
      const [cmd, feed, tasks, billingLinks, platform] = await Promise.all([
        Api.get('/companies/' + companyId + '/command'),
        Api.get('/companies/' + companyId + '/communication?limit=25'),
        Api.get('/companies/' + companyId + '/tasks'),
        Api.get('/companies/' + companyId + '/files-folder/children').catch(() => ({ data: { items: [], broken: false } })),
        Api.get('/companies/' + companyId + '/platform-links').catch(() => ({ data: {} })),
      ]);
      if (generation !== commandCenterGeneration) {
        return;
      }
      const dto = cmd.data || cmd;
      const glance = document.getElementById('crm-glance');
      if (glance && dto) {
        let body = document.getElementById('crm-glance-summary') || glance.querySelector('.crm-glance-pin .crm-card__body') || glance.querySelector('.crm-card__body');
        if (!body) {
          body = document.createElement('div');
          body.className = 'crm-card__body';
          body.id = 'crm-glance-summary';
          const pin = glance.querySelector('.crm-glance-pin') || glance;
          pin.appendChild(body);
        }
        paintGlanceSummary(body, dto);
      }

      const feedEl = document.getElementById('crm-communication-feed');
      const disclosureEl = document.getElementById('crm-shared-mail-disclosure');
      if (feedEl) {
        const items = (feed.data && feed.data.items) || feed.items || [];
        const feedDisclosure = (feed.data && feed.data.disclosure) || feed.disclosure || '';
        if (disclosureEl) {
          if (feedDisclosure) {
            disclosureEl.hidden = false;
            disclosureEl.textContent = feedDisclosure;
          } else {
            const sharedItems = items.filter((item) => item.shared || item.kind === 'shared_mail');
            if (sharedItems.length) {
              const label = sharedItems[0].mailboxLabel || t('Shared');
              const ws = workspaceName() || t('this workspace');
              disclosureEl.hidden = false;
              disclosureEl.textContent = t(
                'These messages come from the shared mailbox %1$s. Everyone in workspace %2$s who can read mail can see them.',
                [label, ws]
              );
            } else {
              disclosureEl.hidden = true;
              disclosureEl.textContent = '';
            }
          }
        }
        if (!items.length) {
          const canWrite = shellCan('write');
          feedEl.innerHTML = '<div class="crm-empty-state" role="status">' +
            '<h3 class="crm-empty-state__title">' + escapeHtml(t('Nothing logged yet')) + '</h3>' +
            '<p class="crm-empty-state__text">' + escapeHtml(t('Nothing logged yet. Log an email or add an activity.')) + '</p>' +
            (canWrite
              ? '<div class="crm-empty-state__actions">' +
                '<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-log-email">' + escapeHtml(t('Log talk')) + '</button> ' +
                '<button type="button" class="crm-btn crm-btn--secondary" data-crm-trigger="crm-add-activity">' + escapeHtml(t('Add to-do')) + '</button>' +
                '</div>'
              : '') +
            '</div>';
        } else {
          const board = document.createElement('div');
          board.className = 'crm-review-board crm-communication-board';
          board.setAttribute('role', 'region');
          board.setAttribute('aria-label', t('Message list'));
          board.setAttribute('data-crm-talk-sort', 'sent');
          board.setAttribute('data-crm-talk-sort-dir', 'desc');

          const toolbar = document.createElement('div');
          toolbar.className = 'crm-review-board__toolbar';
          toolbar.innerHTML = '<p class="crm-muted crm-review-board__hint" role="note">' +
            escapeHtml(t('Open a row to read the message.')) + '</p>';
          const syncSlot = document.createElement('div');
          syncSlot.setAttribute('data-crm-company-mail-sync', '');
          syncSlot.hidden = true;
          toolbar.appendChild(syncSlot);

          function mountCompanyMailSync() {
            if (!window.CustomerCheckMailSync || !Api) {
              return;
            }
            const wsId = root ? parseInt(root.getAttribute('data-workspace-id') || '0', 10) : 0;
            if (!wsId) {
              return;
            }
            Api.get('/workspaces/' + wsId + '/mailboxes').then(function (res) {
              if (generation !== commandCenterGeneration) {
                return;
              }
              const boxes = (res.data && res.data.items) || res.items || [];
              const summary = {
                mailboxCount: boxes.length,
                lastSyncAt: null,
                backgroundIntervalMinutes: window.CustomerCheckMailSync.BACKGROUND_MINUTES || 15,
              };
              boxes.forEach(function (box) {
                const at = box && box.lastSyncAt ? String(box.lastSyncAt) : '';
                if (at && (!summary.lastSyncAt || at > summary.lastSyncAt)) {
                  summary.lastSyncAt = at;
                }
              });
              window.CustomerCheckMailSync.mountBar(syncSlot, {
                t: t,
                summary: summary,
                canSync: shellCan('write'),
                workspaceId: wsId,
                apiPost: function (path, body) {
                  return Api.post(path, body).then(function (r) {
                    return (r && r.data) || r;
                  });
                },
                announce: function (msg) {
                  if (live) live.textContent = msg;
                },
                onSynced: function () {
                  return loadCommandCenter();
                },
              });
            }).catch(function () {
              syncSlot.hidden = true;
            });
          }
          mountCompanyMailSync();

          function sortBtnHtml(col, label, sortKey, sortDir) {
            const active = sortKey === col;
            const arrow = !active ? '' : (sortDir === 'asc' ? ' ↑' : ' ↓');
            const sortHint = active
              ? (sortDir === 'asc' ? t('Sorted ascending') : t('Sorted descending'))
              : t('Not sorted');
            return '<button type="button" class="crm-btn crm-btn--ghost crm-review-sort' + (active ? ' is-active' : '') + '" ' +
              'data-crm-talk-sort="' + escapeHtml(col) + '" aria-pressed="' + (active ? 'true' : 'false') + '" ' +
              'aria-label="' + escapeHtml(label + ' — ' + sortHint) + '">' + escapeHtml(label) + escapeHtml(arrow) + '</button>';
          }

          const pane = document.createElement('div');
          pane.className = 'crm-mail-3pane crm-review-mail crm-review-table crm-communication-feed';
          const thread = document.createElement('div');
          thread.className = 'crm-mail-3pane__thread';
          const list = document.createElement('ul');
          list.className = 'crm-mail-thread crm-review-list';
          list.setAttribute('role', 'list');
          list.setAttribute('aria-label', t('Message list'));
          list.tabIndex = 0;

          const detailHost = document.createElement('li');
          detailHost.className = 'crm-mail-3pane__reader crm-review-detail-row';
          detailHost.setAttribute('data-crm-talk-detail-row', '');
          detailHost.setAttribute('role', 'listitem');
          detailHost.setAttribute('aria-label', t('Details'));
          detailHost.hidden = true;
          const reader = document.createElement('section');
          reader.className = 'crm-review-detail crm-review-reader';
          reader.setAttribute('data-crm-talk-reader', '');
          reader.setAttribute('role', 'region');
          reader.setAttribute('aria-label', t('Details'));
          reader.setAttribute('aria-live', 'polite');
          reader.tabIndex = -1;
          reader.innerHTML = '<div class="crm-mail-reader__empty crm-empty-state" role="status"><p class="crm-empty-state__text">' +
            escapeHtml(t('Select a message to read.')) + '</p></div>';
          detailHost.appendChild(reader);

          const talkSection = document.getElementById('crm-communication');
          const persistedTalkQ = talkSection
            ? String(talkSection.getAttribute('data-crm-talk-q') || '')
            : '';

          const models = items.map(function (item, idx) {
            const whenRaw = String(item.occurredAt || item.sentAt || '').trim();
            const whenTs = whenRaw
              ? (function () {
                const normalized = /T/.test(whenRaw)
                  ? whenRaw
                  : (/^\d{4}-\d{2}-\d{2}\s+\d{1,2}:/.test(whenRaw)
                    ? whenRaw.replace(' ', 'T')
                    : (whenRaw + 'T12:00:00'));
                return new Date(normalized).getTime() || 0;
              }())
              : 0;
            const from = String(item.from || item.personName || feedKindLabel(item.kind) || '');
            const subject = String(item.subject || '');
            const status = feedKindLabel(item.kind);
            const snippet = String(item.snippet || '');
            const whenLabel = whenRaw ? formatDisplayDate(whenRaw) : '';
            const haystack = [from, subject, snippet, status, whenRaw, whenLabel, String(item.title || '')]
              .join('\n')
              .toLowerCase();
            return {
              item: item,
              idx: idx,
              id: String(item.id || ('feed-' + idx)),
              from: from,
              subject: subject,
              status: status,
              whenRaw: whenRaw,
              whenTs: whenTs,
              haystack: haystack,
            };
          });

          function currentTalkQuery() {
            const el = board.querySelector('#crm-talk-q');
            return el ? String(el.value || '').trim() : '';
          }

          function persistTalkQuery(q) {
            const next = String(q || '').trim();
            if (!talkSection) return;
            if (next) talkSection.setAttribute('data-crm-talk-q', next);
            else talkSection.removeAttribute('data-crm-talk-q');
          }

          function talkMatchesQuery(model, q) {
            const needle = String(q || '').trim().toLowerCase();
            if (!needle) return true;
            // Every whitespace-separated token must match (from/subject/date/snippet).
            const tokens = needle.split(/\s+/).filter(Boolean);
            if (!tokens.length) return true;
            return tokens.every(function (tok) {
              return model.haystack.indexOf(tok) !== -1;
            });
          }

          function sortedModels() {
            const key = board.getAttribute('data-crm-talk-sort') || 'sent';
            const dir = board.getAttribute('data-crm-talk-sort-dir') === 'asc' ? 'asc' : 'desc';
            const mul = dir === 'desc' ? -1 : 1;
            return models.slice().sort(function (a, b) {
              let av = '';
              let bv = '';
              if (key === 'from') {
                av = a.from.toLowerCase();
                bv = b.from.toLowerCase();
              } else if (key === 'status') {
                av = a.status.toLowerCase();
                bv = b.status.toLowerCase();
              } else if (key === 'sent') {
                if (a.whenTs < b.whenTs) return -1 * mul;
                if (a.whenTs > b.whenTs) return 1 * mul;
                return 0;
              } else {
                av = a.subject.toLowerCase();
                bv = b.subject.toLowerCase();
              }
              if (av < bv) return -1 * mul;
              if (av > bv) return 1 * mul;
              return 0;
            });
          }

          function visibleTalkModels() {
            const q = currentTalkQuery();
            return sortedModels().filter(function (m) {
              return talkMatchesQuery(m, q);
            });
          }

          function closeTalkReader(focusRow) {
            if (talkMailPreviewAbort) {
              try { talkMailPreviewAbort.abort(); } catch (e) { /* ignore */ }
              talkMailPreviewAbort = null;
            }
            talkMailPreviewGeneration += 1;
            list.querySelectorAll('.crm-mail-thread-row').forEach(function (el) {
              el.setAttribute('aria-current', 'false');
              el.classList.remove('is-selected');
              el.tabIndex = -1;
            });
            detailHost.hidden = true;
            reader.innerHTML = '<div class="crm-mail-reader__empty crm-empty-state" role="status"><p class="crm-empty-state__text">' +
              escapeHtml(t('Select a message to read.')) + '</p></div>';
            const focus = focusRow && focusRow.isConnected
              ? focusRow
              : list.querySelector('.crm-mail-thread-row');
            if (focus) {
              focus.tabIndex = 0;
            }
          }

          function paintTalkReader(model) {
            if (talkMailPreviewAbort) {
              try { talkMailPreviewAbort.abort(); } catch (e) { /* ignore */ }
            }
            talkMailPreviewAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            const previewGen = ++talkMailPreviewGeneration;
            const previewSignal = talkMailPreviewAbort ? talkMailPreviewAbort.signal : null;
            const item = model.item;
            const crmMailId = parseInt(String(item.crmMailId || '0'), 10) || 0;
            const fromLabel = model.from || feedKindLabel(item.kind);
            const badges = [];
            badges.push(feedKindLabel(item.kind));
            if (item.shared || item.kind === 'shared_mail') badges.push(t('Shared'));
            if (item.private || item.kind === 'mail_related') badges.push(item.privacyBadge || t('Private to you'));

            list.querySelectorAll('.crm-mail-thread-row').forEach(function (el) {
              const on = el.getAttribute('data-crm-feed-id') === model.id;
              el.setAttribute('aria-current', on ? 'true' : 'false');
              el.classList.toggle('is-selected', on);
              el.tabIndex = on ? 0 : -1;
            });
            const opt = list.querySelector('[data-crm-feed-id="' + model.id.replace(/"/g, '') + '"]');
            if (opt) {
              detailHost.hidden = false;
              if (detailHost.parentNode !== list || opt.nextElementSibling !== detailHost) {
                opt.after(detailHost);
              }
              try {
                opt.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
              } catch (e) {
                opt.scrollIntoView(true);
              }
            }

            let actionsHtml = '';
            if ((item.shared || item.kind === 'shared_mail') && crmMailId > 0) {
              const bodyId = 'crm-mail-preview-body-c' + model.id.replace(/[^a-zA-Z0-9_-]/g, '') + '-m' + crmMailId;
              actionsHtml =
                '<div class="crm-mail-preview" data-crm-mail-preview="' + escapeHtml(String(crmMailId)) + '">' +
                '<div class="crm-mail-preview__actions crm-inline-actions crm-review-mail-tools">' +
                '<button type="button" class="crm-btn crm-btn--secondary" data-crm-mail-preview-toggle ' +
                'aria-expanded="false" aria-controls="' + escapeHtml(bodyId) + '" ' +
                'title="' + escapeHtml(t('Shows the readable message. Formatting is sanitized on the server.')) + '">' +
                escapeHtml(t('Show message')) + '</button>' +
                '<button type="button" class="crm-btn crm-btn--secondary" data-crm-mail-copy ' +
                'aria-label="' + escapeHtml(t('Copy message')) + '" ' +
                'title="' + escapeHtml(t('Copy the readable message text to the clipboard.')) + '">' +
                escapeHtml(t('Copy message')) + '</button>' +
                '<button type="button" class="crm-btn crm-btn--primary" data-crm-mail-reply ' +
                'aria-label="' + escapeHtml(t('Answer: %s', [String(item.subject || item.title || t('Shared mail'))])) + '" ' +
                'title="' + escapeHtml(t('Reply from your connected personal or team email, or open your mail program.')) + '">' +
                escapeHtml(t('Answer')) + '</button></div>' +
                '<div class="crm-mail-preview__body" id="' + escapeHtml(bodyId) + '" role="article" aria-label="' +
                escapeHtml(t('Message text')) + '" tabindex="0" hidden></div></div>';
            } else if (item.snippet) {
              actionsHtml = '<p class="crm-muted">' + escapeHtml(String(item.snippet)) + '</p>';
            }

            const whenLabel = model.whenRaw ? formatDisplayDate(model.whenRaw) : '';
            reader.innerHTML =
              '<h3 class="crm-review-detail__title visually-hidden">' + escapeHtml(t('Details')) + '</h3>' +
              '<div class="crm-mail-reader__panel"><header class="crm-mail-reader__header">' +
              '<p class="crm-mail-reader__from"><span class="crm-mail-reader__label">' + escapeHtml(t('From')) +
              '</span> ' + escapeHtml(String(fromLabel || t('Unknown sender'))) + '</p>' +
              '<h3 class="crm-mail-reader__subject">' + escapeHtml(item.subject || '') + '</h3>' +
              (whenLabel
                ? ('<p class="crm-mail-reader__when crm-muted"><span class="crm-mail-reader__label">' +
                  escapeHtml(t('Sent')) + '</span> ' + escapeHtml(whenLabel) + '</p>')
                : '') +
              '<p class="crm-mail-reader__meta">' + badges.map(function (b) {
                return '<span class="crm-badge">' + escapeHtml(b) + '</span>';
              }).join('') + '</p></header>' +
              '<div class="crm-mail-reader__body">' + actionsHtml + '</div></div>';

            const previewWrap = reader.querySelector('[data-crm-mail-preview]');
            if (!previewWrap) return;
            const toggle = previewWrap.querySelector('[data-crm-mail-preview-toggle]');
            const replyBtn = previewWrap.querySelector('[data-crm-mail-reply]');
            const copyBtn = previewWrap.querySelector('[data-crm-mail-copy]');
            const bodyEl = previewWrap.querySelector('.crm-mail-preview__body');
            function isStaleTalkPreview() {
              return previewGen !== talkMailPreviewGeneration || !previewWrap.isConnected;
            }
            function isAbortError(err) {
              return !!(err && (err.name === 'AbortError' || err.code === 20));
            }
            async function loadTalkPreviewBody() {
              if (!bodyEl || !toggle) return false;
              if (bodyEl.getAttribute('data-loaded') === '1') {
                bodyEl.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
                toggle.textContent = t('Hide message');
                return true;
              }
              if (toggle.getAttribute('aria-busy') === 'true') return false;
              toggle.disabled = true;
              toggle.setAttribute('aria-busy', 'true');
              try {
                const stack = document.querySelector('.crm-page-stack[data-company-id]');
                const ws = stack ? parseInt(stack.getAttribute('data-workspace-id') || '0', 10) : 0;
                if (!(ws > 0)) throw new Error(t('Workspace missing on this page.'));
                const res = await Api.post(
                  '/workspaces/' + ws + '/mail/' + crmMailId + '/preview',
                  {},
                  previewSignal ? { signal: previewSignal } : undefined,
                );
                if (isStaleTalkPreview()) return false;
                const payload = (res && res.data) || res || {};
                const bodyHtml = payload.bodyHtml ? String(payload.bodyHtml) : '';
                const textBody = payload.preview || '';
                // FAC-16: only render server-sanitized HTML; never invent markup client-side.
                if (bodyHtml !== '') {
                  bodyEl.innerHTML = bodyHtml;
                  bodyEl.classList.add('crm-mail-preview__body--html');
                  bodyEl.classList.remove('crm-mail-preview__body--plain');
                } else {
                  bodyEl.classList.remove('crm-mail-preview__body--html');
                  bodyEl.classList.add('crm-mail-preview__body--plain');
                  bodyEl.textContent = textBody !== '' ? textBody : t('No message text could be loaded.');
                }
                if (payload.truncated) {
                  const note = document.createElement('p');
                  note.className = 'crm-muted';
                  note.textContent = t('(Preview truncated)');
                  bodyEl.appendChild(note);
                }
                bodyEl.setAttribute('data-loaded', '1');
                bodyEl.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
                toggle.textContent = t('Hide message');
                return true;
              } catch (e) {
                if (isAbortError(e) || isStaleTalkPreview()) return false;
                const errMsg = (e.payload && e.payload.message) || e.message || String(e);
                bodyEl.textContent = errMsg;
                bodyEl.hidden = false;
                bodyEl.removeAttribute('data-loaded');
                bodyEl.setAttribute('role', 'alert');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.textContent = t('Show message');
                await C.alertDialog({
                  title: t('Could not load message'),
                  message: errMsg,
                  variant: 'danger',
                });
                return false;
              } finally {
                if (toggle && previewWrap.isConnected) {
                  toggle.disabled = false;
                  toggle.removeAttribute('aria-busy');
                }
              }
            }
            async function openReplyCompose() {
              if (!replyBtn || replyBtn.getAttribute('aria-busy') === 'true') return;
              replyBtn.disabled = true;
              replyBtn.setAttribute('aria-busy', 'true');
              try {
                const stack = document.querySelector('.crm-page-stack[data-company-id]');
                const ws = stack ? parseInt(stack.getAttribute('data-workspace-id') || '0', 10) : 0;
                if (!(ws > 0)) throw new Error(t('Workspace missing on this page.'));
                let res = await Api.get('/workspaces/' + ws + '/mail/' + crmMailId + '/reply');
                let pack = (res && res.data) || res || {};
                const thin = !pack.available || String(pack.bodyText || '').length < 40;
                if (thin) {
                  try {
                    // Hydrate once when Answer is first — richer quote for in-app send.
                    await Api.post('/workspaces/' + ws + '/mail/' + crmMailId + '/preview', {}, previewSignal ? { signal: previewSignal } : undefined);
                    res = await Api.get('/workspaces/' + ws + '/mail/' + crmMailId + '/reply');
                    pack = (res && res.data) || res || {};
                  } catch (hydrateErr) { /* keep first pack */ }
                }
                if (isStaleTalkPreview()) return;
                if (!pack.available) {
                  await C.alertDialog({ title: t('Cannot answer'), message: t('This message has no usable reply address.'), variant: 'warning' });
                  return;
                }
                const SharedMail = window.CustomerCheckSharedMail;
                if (SharedMail && typeof SharedMail.offerPreparedMail === 'function') {
                  await SharedMail.offerPreparedMail(pack, {
                    companyId: companyId || parseInt(String(pack.companyId || 0), 10) || 0,
                    title: t('Answer'),
                    replyMode: true,
                    mailboxId: parseInt(String(pack.mailboxId || item.mailboxId || 0), 10) || 0,
                  });
                  return;
                }
                const rawUrl = pack.mailAppUrl || pack.mailtoUrl || '';
                const url = (typeof C.safeExternalHref === 'function') ? C.safeExternalHref(rawUrl, '#') : rawUrl;
                if (!url || url === '#') {
                  await C.alertDialog({ title: t('Cannot answer'), message: t('Could not open a mail program for this address.'), variant: 'danger' });
                  return;
                }
                if (/^mailto:/i.test(url)) window.location.href = url;
                else window.open(url, '_blank', 'noopener,noreferrer');
              } catch (e) {
                if (isAbortError(e) || isStaleTalkPreview()) return;
                await C.alertDialog({ title: t('Cannot answer'), message: (e.payload && e.payload.message) || e.message || String(e), variant: 'danger' });
              } finally {
                if (replyBtn && previewWrap.isConnected) {
                  replyBtn.disabled = false;
                  replyBtn.removeAttribute('aria-busy');
                }
              }
            }
            if (replyBtn) replyBtn.addEventListener('click', function () { void openReplyCompose(); });
            if (copyBtn && bodyEl) {
              copyBtn.addEventListener('click', async function () {
                const ok = await loadTalkPreviewBody();
                if (!ok) return;
                const text = String(bodyEl.textContent || '').trim();
                if (!text) {
                  await C.alertDialog({
                    title: t('Copy message'),
                    message: t('No message text could be loaded.'),
                    variant: 'warning',
                  });
                  return;
                }
                const live = document.getElementById('crm-live-region');
                try {
                  await navigator.clipboard.writeText(text);
                  if (live) live.textContent = t('Copied to clipboard.');
                  copyBtn.textContent = t('Copied');
                  setTimeout(function () {
                    if (copyBtn.isConnected) copyBtn.textContent = t('Copy message');
                  }, 1600);
                } catch (e) {
                  try {
                    const range = document.createRange();
                    range.selectNodeContents(bodyEl);
                    const sel = window.getSelection();
                    if (sel) {
                      sel.removeAllRanges();
                      sel.addRange(range);
                    }
                    bodyEl.focus({ preventScroll: true });
                  } catch (e2) { /* ignore */ }
                  await C.alertDialog({
                    title: t('Copy message'),
                    message: t('Clipboard access was denied. Select the message text and copy it.'),
                    variant: 'warning',
                  });
                }
              });
            }
            if (toggle && bodyEl) {
              toggle.addEventListener('click', async function () {
                if (toggle.getAttribute('aria-busy') === 'true') return;
                if (!bodyEl.hidden && bodyEl.getAttribute('data-loaded') === '1') {
                  bodyEl.hidden = true;
                  toggle.setAttribute('aria-expanded', 'false');
                  toggle.textContent = t('Show message');
                  return;
                }
                if (bodyEl.getAttribute('data-loaded') === '1') {
                  bodyEl.hidden = false;
                  toggle.setAttribute('aria-expanded', 'true');
                  toggle.textContent = t('Hide message');
                  try { bodyEl.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
                  return;
                }
                const ok = await loadTalkPreviewBody();
                if (ok) {
                  try { bodyEl.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
                }
              });
              // Opening a row always loads the message — never require a second click.
              void loadTalkPreviewBody();
            }
          }

          function renderTalkRows() {
            const sortKey = board.getAttribute('data-crm-talk-sort') || 'sent';
            const sortDir = board.getAttribute('data-crm-talk-sort-dir') === 'asc' ? 'asc' : 'desc';
            const sortbar = board.querySelector('.crm-review-sortbar');
            if (sortbar) {
              sortbar.innerHTML =
                sortBtnHtml('sent', t('Date'), sortKey, sortDir) +
                sortBtnHtml('from', t('From'), sortKey, sortDir) +
                sortBtnHtml('subject', t('Subject'), sortKey, sortDir);
              sortbar.querySelectorAll('[data-crm-talk-sort]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  const col = btn.getAttribute('data-crm-talk-sort') || 'sent';
                  const cur = board.getAttribute('data-crm-talk-sort') || 'sent';
                  const curDir = board.getAttribute('data-crm-talk-sort-dir') === 'asc' ? 'asc' : 'desc';
                  let nextDir;
                  if (cur === col) nextDir = curDir === 'asc' ? 'desc' : 'asc';
                  else nextDir = col === 'sent' ? 'desc' : 'asc';
                  board.setAttribute('data-crm-talk-sort', col);
                  board.setAttribute('data-crm-talk-sort-dir', nextDir);
                  renderTalkRows();
                });
              });
            }
            const selectedEl = list.querySelector('.crm-mail-thread-row.is-selected');
            const selectedId = selectedEl ? (selectedEl.getAttribute('data-crm-feed-id') || '') : '';
            list.querySelectorAll('.crm-mail-thread-row').forEach(function (el) { el.remove(); });
            const emptyMatch = list.querySelector('[data-crm-talk-empty-search]');
            if (emptyMatch) emptyMatch.remove();
            detailHost.hidden = true;
            reader.innerHTML = '<div class="crm-mail-reader__empty crm-empty-state" role="status"><p class="crm-empty-state__text">' +
              escapeHtml(t('Select a message to read.')) + '</p></div>';

            const visible = visibleTalkModels();
            const countEl = board.querySelector('[data-crm-talk-count]');
            const qNow = currentTalkQuery();
            if (countEl) {
              if (!qNow) {
                countEl.hidden = true;
                countEl.textContent = '';
              } else {
                countEl.hidden = false;
                countEl.textContent = t('Showing %1$s of %2$s messages', [
                  String(visible.length),
                  String(models.length),
                ]);
              }
            }
            if (!visible.length) {
              const emptyLi = document.createElement('li');
              emptyLi.className = 'crm-communication-search-empty';
              emptyLi.setAttribute('data-crm-talk-empty-search', '1');
              emptyLi.setAttribute('role', 'status');
              emptyLi.innerHTML = '<div class="crm-empty-state">' +
                '<p class="crm-empty-state__text">' + escapeHtml(t('No messages match this search')) + '</p>' +
                '</div>';
              list.insertBefore(emptyLi, detailHost);
              return;
            }

            visible.forEach(function (model) {
              const item = model.item;
              const li = document.createElement('li');
              li.className = 'crm-mail-thread-row crm-review-row crm-feed__item';
              li.setAttribute('role', 'listitem');
              li.id = 'crm-feed-opt-' + model.id.replace(/[^a-zA-Z0-9_-]/g, '_');
              li.setAttribute('data-crm-feed-id', model.id);
              li.setAttribute('aria-current', 'false');
              li.tabIndex = -1;
              const fromLabel = model.from || t('Unknown sender');
              const main = document.createElement('div');
              main.className = 'crm-mail-thread-row__main';
              const fromEl = document.createElement('p');
              fromEl.className = 'crm-mail-thread-row__from';
              fromEl.textContent = fromLabel;
              const subj = document.createElement('p');
              subj.className = 'crm-mail-thread-row__subject crm-review-row__subject';
              subj.textContent = item.subject || '';
              main.appendChild(fromEl);
              main.appendChild(subj);
              if (item.snippet) {
                const snip = document.createElement('p');
                snip.className = 'crm-mail-thread-row__snippet crm-muted';
                snip.textContent = String(item.snippet).slice(0, 140);
                main.appendChild(snip);
              }
              const meta = document.createElement('p');
              meta.className = 'crm-feed__meta crm-muted';
              meta.innerHTML = '<span class="crm-badge">' + escapeHtml(feedKindLabel(item.kind)) + '</span>';
              main.appendChild(meta);
              li.appendChild(main);

              const whenEl = document.createElement(model.whenRaw ? 'time' : 'span');
              whenEl.className = 'crm-mail-thread-row__when' + (model.whenRaw ? '' : ' crm-muted');
              if (model.whenRaw) {
                whenEl.setAttribute('datetime', model.whenRaw);
                whenEl.textContent = formatDisplayDate(model.whenRaw);
              } else {
                whenEl.setAttribute('aria-hidden', 'true');
                whenEl.textContent = '—';
              }
              li.appendChild(whenEl);

              function openRow(ev) {
                if (ev && ev.target && ev.target.closest && ev.target.closest('a, input, button')) return;
                // Second click / Enter on the open row collapses the reader (accordion).
                if (li.classList.contains('is-selected')) {
                  closeTalkReader(li);
                  return;
                }
                paintTalkReader(model);
                try { li.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) { /* ignore */ }
              }
              li.addEventListener('click', openRow);
              li.addEventListener('keydown', function (ev) {
                if (ev.key !== 'Enter' && ev.key !== ' ') return;
                if (ev.target.closest && ev.target.closest('button, a, input')) return;
                ev.preventDefault();
                openRow(ev);
              });
              list.appendChild(li);
              if (selectedId && selectedId === model.id) {
                paintTalkReader(model);
              }
            });
            const rows = list.querySelectorAll('.crm-mail-thread-row');
            if (rows.length && !list.querySelector('.crm-mail-thread-row.is-selected')) {
              rows[0].tabIndex = 0;
            }
          }

          if (!list.getAttribute('data-crm-talk-kbd')) {
            list.setAttribute('data-crm-talk-kbd', '1');
            list.addEventListener('keydown', function (ev) {
              if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp' && ev.key !== 'Home' && ev.key !== 'End') {
                return;
              }
              const rows = Array.from(list.querySelectorAll('.crm-mail-thread-row'));
              if (!rows.length) return;
              const active = document.activeElement && document.activeElement.closest
                ? document.activeElement.closest('.crm-mail-thread-row')
                : null;
              let idx = active ? rows.indexOf(active) : -1;
              if (ev.key === 'Home') idx = 0;
              else if (ev.key === 'End') idx = rows.length - 1;
              else if (ev.key === 'ArrowDown') idx = Math.min(rows.length - 1, Math.max(0, idx + 1));
              else if (ev.key === 'ArrowUp') idx = Math.max(0, idx < 0 ? 0 : idx - 1);
              ev.preventDefault();
              const target = rows[idx];
              if (!target) return;
              rows.forEach(function (r) { r.tabIndex = -1; });
              target.tabIndex = 0;
              target.focus();
              target.click();
            });
          }

          const sortbar = document.createElement('div');
          sortbar.className = 'crm-review-sortbar';
          sortbar.setAttribute('role', 'toolbar');
          sortbar.setAttribute('aria-label', t('Sort'));

          const searchHost = document.createElement('div');
          searchHost.className = 'crm-review-toolbar crm-communication-search';
          searchHost.setAttribute('role', 'search');
          searchHost.setAttribute('aria-label', t('Search messages'));
          searchHost.innerHTML =
            '<form class="crm-form crm-review-search" data-crm-talk-search="1">' +
            '<div class="crm-review-search__field">' +
            '<label class="form-label" for="crm-talk-q">' + escapeHtml(t('Search messages')) + '</label>' +
            '<input type="search" id="crm-talk-q" class="form-input crm-input" autocomplete="off" ' +
            'placeholder="' + escapeHtml(t('From, subject, date, or snippet…')) + '" ' +
            'value="' + escapeHtml(persistedTalkQ) + '" />' +
            '</div>' +
            '<div class="crm-review-search__actions">' +
            '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Search')) + '</button>' +
            '</div>' +
            '</form>' +
            '<p class="crm-muted crm-review-count" data-crm-talk-count role="status" aria-live="polite" hidden></p>';

          const talkSearchForm = searchHost.querySelector('form[data-crm-talk-search]');
          const talkSearchInput = searchHost.querySelector('#crm-talk-q');
          let talkSearchTimer = null;
          function applyTalkSearchFromInput() {
            const nextQ = talkSearchInput ? String(talkSearchInput.value || '').trim() : '';
            persistTalkQuery(nextQ);
            renderTalkRows();
          }
          if (talkSearchForm) {
            talkSearchForm.addEventListener('submit', function (ev) {
              ev.preventDefault();
              if (talkSearchTimer) window.clearTimeout(talkSearchTimer);
              applyTalkSearchFromInput();
            });
          }
          if (talkSearchInput) {
            talkSearchInput.addEventListener('input', function () {
              if (talkSearchTimer) window.clearTimeout(talkSearchTimer);
              talkSearchTimer = window.setTimeout(applyTalkSearchFromInput, 280);
            });
          }

          thread.appendChild(list);
          list.appendChild(detailHost);
          pane.appendChild(thread);
          board.appendChild(toolbar);
          board.appendChild(searchHost);
          board.appendChild(sortbar);
          board.appendChild(pane);
          feedEl.innerHTML = '';
          feedEl.appendChild(board);
          renderTalkRows();
          // Stay collapsed until the user picks a message.
          // Never seed body text from list/feed JSON — on-demand preview API only.
        }
      }

      const tasksBody = document.getElementById('crm-open-tasks-body');
      if (tasksBody) {
        const items = (tasks.data && tasks.data.items) || tasks.items || [];
        if (!items.length) {
          tasksBody.innerHTML = '<p class="crm-muted" role="status">' + escapeHtml(t('Nothing open for this company.')) + '</p>';
        } else {
          let html = '<ul class="crm-todo-list" role="list">';
          items.forEach((row) => {
            const deep = safeInternalHref(row.tasksDeepLink || '/apps/tasks/', '/apps/tasks/');
            const due = row.dueDate ? formatDisplayDate(row.dueDate) : '—';
            html += '<li class="crm-todo-list__item"><div class="crm-todo-list__main">';
            html += '<p class="crm-todo-list__title">' + escapeHtml(row.title || '') + '</p>';
            html += '<p class="crm-muted crm-todo-list__meta"><span>' + escapeHtml(t('Due')) + ': ' + escapeHtml(due) + '</span>';
            if (row.isCallback) {
              html += '<span class="crm-badge">' + escapeHtml(t('Callback')) + '</span>';
            }
            html += '</p></div>';
            if (row.inTasks) {
              html += '<div class="crm-todo-list__actions"><a class="crm-btn crm-btn--primary crm-btn--lg" href="' + escapeHtml(deep) + '">' + escapeHtml(t('Open in Tasks')) + '</a></div>';
            }
            html += '</li>';
          });
          html += '</ul>';
          tasksBody.innerHTML = html;
        }
      }

      const billingBody = document.getElementById('crm-billing-body');
      if (billingBody && dto && dto.billing) {
        paintBillingSummary(billingBody, dto.billing);
      }

      const docsBody = document.getElementById('crm-documents-body');
      const unlinkFolderBtn = document.getElementById('crm-unlink-folder');
      if (docsBody) {
        const data = billingLinks.data || billingLinks;
        const linked = !!(data.folderUrl || data.path || (dto && dto.counts && dto.counts.filesLinked));
        if (unlinkFolderBtn) {
          unlinkFolderBtn.hidden = !linked || !!data.broken;
        }
        if (data.broken) {
          docsBody.innerHTML = '<div class="crm-callout crm-callout--warning" role="status"><p>' + t('That folder is gone. Link a new one.') + '</p></div>';
        } else if (linked) {
          docsBody.innerHTML = '';
          const actions = document.createElement('div');
          actions.className = 'crm-inline-actions';
          if (data.folderUrl) {
            const open = document.createElement('a');
            open.className = 'crm-btn crm-btn--secondary crm-btn--sm';
            open.href = data.folderUrl;
            open.id = 'crm-open-folder';
            open.textContent = t('Open folder');
            actions.appendChild(open);
          }
          if (data.path) {
            const path = document.createElement('p');
            path.className = 'crm-muted';
            path.textContent = data.path;
            docsBody.appendChild(path);
          }
          if (actions.childNodes.length) {
            docsBody.appendChild(actions);
          }
          const items = data.items || [];
          if (items.length) {
            const ul = document.createElement('ul');
            ul.className = 'crm-simple-list';
            items.forEach((f) => {
              const li = document.createElement('li');
              li.textContent = f.name + (f.isFolder ? '/' : '');
              ul.appendChild(li);
            });
            docsBody.appendChild(ul);
          } else {
            const empty = document.createElement('p');
            empty.className = 'crm-muted';
            empty.textContent = t('Folder is linked — open it in Files to add documents.');
            docsBody.appendChild(empty);
          }
        } else {
          docsBody.innerHTML = '<div class="crm-empty-state" role="status">' +
            '<h3 class="crm-empty-state__title">' + escapeHtml(t('No folder linked')) + '</h3>' +
            '<p class="crm-empty-state__text">' + escapeHtml(t('No document folder linked yet. Browse your Nextcloud folders and choose one (contracts, offers, notes) so everyone finds customer files in one click.')) + '</p>' +
            (shellCan('write')
              ? '<div class="crm-empty-state__actions"><button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-link-folder">' + escapeHtml(t('Browse folders')) + '</button></div>'
              : '') +
            '</div>';
        }
      }

      const plat = document.getElementById('crm-platform-links');
      if (plat) {
        const origin = window.location.origin;
        const links = platform.data || platform || {};
        const chips = (window.CustomerCheckPlatformLinks && typeof window.CustomerCheckPlatformLinks.enabledChips === 'function')
          ? window.CustomerCheckPlatformLinks.enabledChips(links, origin)
          : [];
        plat.innerHTML = '';
        plat.setAttribute('data-platform-painted', '1');
        if (!chips.length) {
          plat.hidden = true;
          plat.setAttribute('hidden', '');
        } else {
          plat.hidden = false;
          plat.removeAttribute('hidden');
          const lead = document.createElement('p');
          lead.className = 'crm-platform-links__lead';
          lead.id = 'crm-platform-links-lead';
          lead.textContent = t('Open related Nextcloud apps');
          plat.setAttribute('aria-labelledby', 'crm-platform-links-lead');
          plat.appendChild(lead);
          const row = document.createElement('div');
          row.className = 'crm-platform-links__row';
          chips.forEach((chip) => {
            const a = document.createElement('a');
            a.className = 'crm-platform-links__chip';
            a.href = chip.href;
            a.textContent = chip.label;
            row.appendChild(a);
          });
          plat.appendChild(row);
        }
      }
      if (live && !silent) live.textContent = t('Company overview updated.');
    } catch (e) {
      if (generation !== commandCenterGeneration) {
        return;
      }
      if (silent) {
        return;
      }
      if (live) live.textContent = t('Some overview data could not be loaded.');
      paintHydrateFailure('crm-glance-summary', t('Loading overview…'));
      paintHydrateFailure('crm-communication-feed', t('Loading talk…'));
      paintHydrateFailure('crm-open-tasks-body', t('Loading tasks…'));
      paintHydrateFailure('crm-billing-body', t('Loading money status…'));
    }
  }

  const unlinkFolderBtn = document.getElementById('crm-unlink-folder');
  if (unlinkFolderBtn) {
    unlinkFolderBtn.addEventListener('click', async () => {
      const ok = await C.confirmDialog({
        title: t('Unlink folder?'),
        message: t('Removes the link only. Files stay in Nextcloud Files.'),
        confirmLabel: t('Unlink folder'),
        cancelLabel: t('Cancel'),
      });
      if (!ok) return;
      try {
        const cmd = await Api.get('/companies/' + companyId + '/command');
        const updatedAt = (cmd.data && cmd.data.updatedAt) || null;
        await Api.del('/companies/' + companyId + '/files-folder', { updatedAt });
        const live = document.getElementById('crm-live-region');
        if (live) {
          live.textContent = t('Folder unlinked.');
        }
        await loadCommandCenter();
      } catch (e) {
        await C.alertDialog({ title: t('Could not unlink folder'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const createFolderBtn = document.getElementById('crm-create-folder');
  if (createFolderBtn) {
    createFolderBtn.addEventListener('click', async () => {
      try {
        const stack = document.querySelector('.crm-page-stack[data-company-id]');
        // updatedAt from command reload
        const cmd = await Api.get('/companies/' + companyId + '/command');
        const updatedAt = (cmd.data && cmd.data.updatedAt) || null;
        await Api.post('/companies/' + companyId + '/files-folder/create', { updatedAt });
        await loadCommandCenter();
      } catch (e) {
        await C.alertDialog({ title: t('Could not create folder'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

	const linkFolderBtn = document.getElementById('crm-link-folder');
  if (linkFolderBtn) {
    linkFolderBtn.addEventListener('click', async () => {
      if (linkFolderBtn.disabled) return;
      linkFolderBtn.disabled = true;
      try {
        const Picker = window.CustomerCheckFolderPicker;
        let selection = null;

        if (Picker && typeof Picker.pickFolder === 'function') {
          const picked = await Picker.pickFolder();
          if (picked && picked.unavailable) {
            const useSearch = await C.confirmDialog({
              title: t('Folder browser unavailable'),
              message: t('The Nextcloud folder browser could not be opened. Search by folder name instead?'),
              confirmLabel: t('Search by name'),
              cancelLabel: t('Cancel'),
            });
            if (useSearch) {
              selection = await linkFolderViaSearchFallback();
            }
          } else {
            selection = picked;
          }
        } else {
          selection = await linkFolderViaSearchFallback();
        }

        if (!selection || selection.unavailable) return;

        const fileId = selection.fileId ? parseInt(String(selection.fileId), 10) : 0;
        const path = selection.path ? String(selection.path) : '';
        if (!(fileId > 0) && path === '') {
          await C.alertDialog({
            title: t('Could not link folder'),
            message: t('Could not resolve that folder. Open it in the picker, then click Choose at the bottom.'),
            variant: 'danger',
          });
          return;
        }

        const cmd = await Api.get('/companies/' + companyId + '/command');
        const updatedAt = (cmd.data && cmd.data.updatedAt) || null;
        const body = { updatedAt: updatedAt };
        if (fileId > 0) {
          body.fileId = fileId;
        }
        if (path) {
          body.path = path;
        }
        await Api.put('/companies/' + companyId + '/files-folder', body);
        const live = document.getElementById('crm-live-region');
        if (live) {
          live.textContent = t('Folder linked.');
        }
        await loadCommandCenter();
      } catch (e) {
        await C.alertDialog({
          title: t('Could not link folder'),
          message: (e.payload && e.payload.message) || e.message,
          variant: 'danger',
        });
      } finally {
        linkFolderBtn.disabled = false;
      }
    });
  }

  /**
   * Last resort when the native Files picker is unavailable.
   * @returns {Promise<{ fileId: number, path: string }|null>}
   */
  async function linkFolderViaSearchFallback() {
    const data = await promptFields(t('Link a Files folder'), [
      { name: 'q', label: t('Search folder by name'), type: 'text', required: true },
    ]);
    if (!data || !data.q || String(data.q).trim().length < 2) {
      return null;
    }
    const res = await Api.get('/files/folders/search?q=' + encodeURIComponent(data.q));
    const items = res.data || res || [];
    if (!items.length) {
      await C.alertDialog({ title: t('No folders found'), message: t('Try another name.'), variant: 'info' });
      return null;
    }
    const pick = await promptFields(t('Pick a folder'), [
      {
        name: 'fileId',
        label: t('Folder'),
        type: 'select',
        required: true,
        options: items.map((f) => ({ value: String(f.fileId), label: (f.path || f.name) })),
      },
    ]);
    if (!pick) {
      return null;
    }
    const fileId = parseInt(pick.fileId, 10);
    const hit = items.find((f) => String(f.fileId) === String(pick.fileId));
    return {
      fileId: fileId,
      path: hit && hit.path ? String(hit.path) : '',
    };
  }

  const callbackBtn = document.getElementById('crm-schedule-callback');
  if (callbackBtn) {
    callbackBtn.addEventListener('click', async () => {
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 1);
      const ymd = localYmd(tomorrow);
      callbackBtn.disabled = true;
      try {
        await Api.post('/companies/' + companyId + '/callbacks', {
          dueDate: ymd,
          reason: t('Call tomorrow'),
        });
        if (document.getElementById('crm-live-region')) {
          document.getElementById('crm-live-region').textContent = t('Callback scheduled.');
        }
        await loadCommandCenter();
      } catch (e) {
        await C.alertDialog({ title: t('Could not schedule callback'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      } finally {
        callbackBtn.disabled = false;
      }
    });
  }

  const newTaskBtn = document.getElementById('crm-new-task');
  if (newTaskBtn) {
    newTaskBtn.addEventListener('click', async () => {
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 1);
      const ymd = localYmd(tomorrow);
      const data = await promptFields(t('New task'), [
        {
          name: 'title',
          label: t('What needs doing?'),
          type: 'textarea',
          rows: 2,
          required: true,
          maxlength: 255,
        },
        { name: 'dueDate', label: t('Due date'), type: 'date', required: true, value: ymd },
        {
          name: 'type',
          label: t('Type'),
          type: 'select',
          advanced: true,
          options: [
            { value: 'todo', label: t('To-do') },
            { value: 'call', label: t('Call') },
            { value: 'email', label: t('Email') },
            { value: 'meeting', label: t('Meeting') },
          ],
          value: 'todo',
        },
        { name: 'pushToTasks', label: t('Also send to Tasks'), type: 'checkbox', value: true, advanced: true },
      ]);
      if (!data) return;
      try {
        await Api.post('/companies/' + companyId + '/tasks', {
          title: data.title,
          type: data.type || 'todo',
          dueDate: data.dueDate || ymd,
          pushToTasks: data.pushToTasks === undefined ? true : !!data.pushToTasks,
        });
        await loadCommandCenter();
      } catch (e) {
        await C.alertDialog({ title: t('Could not create task'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  function accountPlanOwnerSearchLabel(plan) {
    const uid = String(plan.ownerUserId || '').trim();
    if (!uid) return '';
    const name = String(plan.ownerDisplayName || '').trim();
    if (name && name !== uid) {
      return name + ' (' + uid + ')';
    }
    return uid;
  }

  function accountPlanStepsHtml(plan, mapSteps) {
    const goalText = String((plan && plan.goal) || '').trim();
    const hasSaved = !!(plan && (plan.updatedAt || plan.id));
    const openMoves = (mapSteps || []).filter((s) => String(s.status || 'open') === 'open');
    const steps = [
      { title: t('Write the goal'), text: t('Where should this customer be in a year?'), done: goalText !== '' },
      { title: t('Save plan'), text: t('Keeps the goal for your team.'), done: hasSaved && goalText !== '' },
      { title: t('Add the next moves'), text: t('Concrete steps toward the goal.'), done: openMoves.length > 0 },
    ];
    let html = '<ol class="crm-welcome-coach__steps crm-account-plan__guide" aria-label="' + escapeHtml(t('Account plan steps')) + '">';
    steps.forEach((step, index) => {
      html += '<li class="crm-welcome-coach__step' + (step.done ? ' crm-welcome-coach__step--done' : '') + '">';
      html += '<span class="crm-welcome-coach__step-num" aria-hidden="true">' + String(index + 1) + '</span>';
      html += '<div class="crm-welcome-coach__step-body">';
      html += '<strong class="crm-welcome-coach__step-title">' + escapeHtml(step.title) + '</strong>';
      html += '<p class="crm-welcome-coach__step-text">' + escapeHtml(step.text) + '</p>';
      html += '</div></li>';
    });
    html += '</ol>';
    return html;
  }

  function planMoreShouldOpen(plan) {
    const owner = String(plan.ownerUserId || '').trim();
    const review = String(plan.nextAssignOn || '').trim();
    const priority = String(plan.priority || 'p2');
    return owner !== '' || review !== '' || (priority !== '' && priority !== 'p2');
  }

  function accountPlanJustSaved() {
    return Date.now() < accountPlanSavedUntil;
  }

  function announceAccountPlanSaved() {
    if (liveRegion()) {
      liveRegion().textContent = t('Account plan saved.');
    }
    if (C && typeof C.showToast === 'function') {
      C.showToast({ type: 'success', title: t('Saved.'), message: t('Account plan saved.') });
    }
  }

  function bindAccountPlanDirty(form) {
    if (!form) return;
    const onDirty = function (e) {
      if (e && e.isTrusted === false) {
        return;
      }
      accountPlanSavedUntil = 0;
      const banner = document.getElementById('crm-plan-save-status');
      if (banner) {
        banner.remove();
      }
      const btn = form.querySelector('button[type="submit"]');
      if (btn && form.getAttribute('aria-busy') !== 'true') {
        btn.textContent = t('Save plan');
        btn.removeAttribute('data-plan-just-saved');
        btn.classList.remove('crm-account-plan__save-btn--done');
      }
    };
    form.addEventListener('input', onDirty);
    form.addEventListener('change', onDirty);
  }

  async function loadAccountPlan(planOverride) {
    const body = document.getElementById('crm-account-plan-body');
    if (!body || !companyId) return;
    const canWrite = shellCan('write');
    const priorityLabel = function (code) {
      if (code === 'p1') return t('High');
      if (code === 'p3') return t('Low');
      return t('Normal');
    };
    const renderMovesSection = function (mapSteps, writable) {
      let html = '<section class="crm-account-plan__panel crm-account-plan__panel--moves crm-account-plan__section crm-account-plan__section--moves" aria-labelledby="crm-plan-moves-title">';
      html += '<h3 id="crm-plan-moves-title" class="crm-account-plan__panel-title crm-account-plan__heading">';
      html += '<span class="crm-account-plan__panel-num" aria-hidden="true">3</span>';
      html += '<span class="crm-account-plan__panel-label">' + escapeHtml(t('Add the next moves')) + '</span></h3>';
      html += '<p class="crm-muted crm-account-plan__lead">' + escapeHtml(t('Plan moves stay here. Day-to-day to-dos live under To-dos above.')) + '</p>';
      if (mapSteps.length) {
        html += '<ul class="crm-todo-list crm-plan-moves__list" role="list" aria-label="' + escapeHtml(t('Next moves')) + '">';
        mapSteps.forEach((s) => {
          const open = String(s.status || 'open') === 'open';
          html += '<li class="crm-todo-list__item' + (open ? '' : ' crm-todo-list__item--closed') + '" data-map-step-id="' + escapeHtml(String(s.id || '')) + '">';
          html += '<div class="crm-todo-list__main"><p class="crm-todo-list__title">' + escapeHtml(s.title || '') + '</p>';
          html += '<p class="crm-muted crm-todo-list__meta"><span class="crm-badge">' + escapeHtml(s.statusLabel || s.status || '') + '</span></p></div>';
          if (writable && open && s.id) {
            html += '<div class="crm-todo-list__actions"><button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-map-step-done="' + escapeHtml(String(s.id)) + '">' + escapeHtml(t('Done')) + '</button></div>';
          }
          html += '</li>';
        });
        html += '</ul>';
      } else {
        html += '<p class="crm-muted crm-account-plan__empty" role="status">' + escapeHtml(t('No moves yet — add one below.')) + '</p>';
      }
      if (writable) {
        html += '<form id="crm-map-step-form" class="crm-form crm-form--stack crm-plan-add-move" aria-label="' + escapeHtml(t('Add a move to this plan')) + '">';
        html += '<label class="form-label" for="crm-map-step-title">' + escapeHtml(t('What should happen next?')) + '</label>';
        html += '<textarea class="crm-input form-input" id="crm-map-step-title" name="title" rows="3" required maxlength="255" placeholder="' + escapeHtml(t('e.g. Book a QBR with their CFO')) + '"></textarea>';
        html += '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary crm-btn--lg">' + escapeHtml(t('Add move')) + '</button></div></form>';
      }
      html += '</section>';
      return html;
    };
    let generation = 0;
    try {
      generation = ++accountPlanGeneration;
      let plan;
      if (planOverride && typeof planOverride === 'object') {
        plan = planOverride;
      } else {
        const res = await Api.get('/companies/' + companyId + '/account-plan');
        if (generation !== accountPlanGeneration) {
          return;
        }
        plan = res.data || res || {};
      }
      if (generation !== accountPlanGeneration) {
        return;
      }
      const mapSteps = plan.mapSteps || [];
      let html = '';
      if (!canWrite) {
        html += '<div class="crm-account-plan">';
        html += accountPlanStepsHtml(plan, mapSteps);
        html += '<div class="crm-account-plan__stack">';
        html += '<div class="crm-account-plan__col crm-account-plan__col--plan">';
        html += '<section class="crm-account-plan__panel crm-account-plan__panel--goal crm-account-plan__section crm-account-plan__section--read" aria-labelledby="crm-plan-goal-panel-title">';
        html += '<h3 id="crm-plan-goal-panel-title" class="crm-account-plan__panel-title crm-account-plan__heading">';
        html += '<span class="crm-account-plan__panel-num" aria-hidden="true">1</span>';
        html += '<span class="crm-account-plan__panel-label">' + escapeHtml(t('Write the goal')) + '</span></h3>';
        html += '<dl class="crm-dl">';
        html += '<div><dt>' + escapeHtml(t('Goal')) + '</dt><dd>' + escapeHtml(plan.goal || '—') + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Priority')) + '</dt><dd>' + escapeHtml(priorityLabel(plan.priority || 'p2')) + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Plan owner')) + '</dt><dd>' + escapeHtml(plan.ownerDisplayName || plan.ownerUserId || '—') + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Next review')) + '</dt><dd>' + escapeHtml(plan.nextAssignOn || '—') + '</dd></div>';
        if (plan.healthLabel || plan.health) {
          html += '<div><dt>' + escapeHtml(t('Health')) + '</dt><dd><span class="crm-badge">' + escapeHtml(plan.healthLabel || plan.health) + '</span></dd></div>';
        }
        html += '</dl></section></div>';
        html += '<div class="crm-account-plan__col crm-account-plan__col--moves">';
        html += renderMovesSection(mapSteps, false);
        html += '</div></div></div>';
        body.innerHTML = html;
        return;
      }
      const moreOpen = planMoreShouldOpen(plan);
      html = '<div class="crm-account-plan">';
      html += accountPlanStepsHtml(plan, mapSteps);
      html += '<div class="crm-account-plan__stack">';
      html += '<div class="crm-account-plan__col crm-account-plan__col--plan">';
      html += '<section class="crm-account-plan__panel crm-account-plan__panel--goal" aria-labelledby="crm-plan-goal-panel-title">';
      html += '<h3 id="crm-plan-goal-panel-title" class="crm-account-plan__panel-title crm-account-plan__heading">';
      html += '<span class="crm-account-plan__panel-num" aria-hidden="true">1</span>';
      html += '<span class="crm-account-plan__panel-label">' + escapeHtml(t('Write the goal')) + '</span></h3>';
      html += '<form id="crm-account-plan-form" class="crm-form crm-form--stack crm-account-plan__form" novalidate data-plan-updated-at="' + escapeHtml(plan.updatedAt || '') + '">';
      html += '<div class="form-group crm-account-plan__goal-field">';
      html += '<label class="form-label" for="crm-plan-goal" id="crm-plan-goal-heading">' + escapeHtml(t('Goal')) + '</label>';
      html += '<textarea class="crm-input form-input crm-plan-goal" id="crm-plan-goal" name="goal" rows="4" maxlength="4000" aria-labelledby="crm-plan-goal-heading" placeholder="' + escapeHtml(t('e.g. Renew and grow seats this year')) + '">' + escapeHtml(plan.goal || '') + '</textarea></div>';
      if (plan.healthLabel || plan.health) {
        html += '<p class="crm-account-plan__health" role="status"><span class="crm-badge">' + escapeHtml(plan.healthLabel || plan.health) + '</span></p>';
      }
      html += '<details class="crm-advanced crm-account-plan__more"' + (moreOpen ? ' open' : '') + '>';
      html += '<summary class="crm-advanced__summary">' + escapeHtml(t('More options')) + '</summary>';
      html += '<div class="crm-advanced__body">';
      html += '<div class="form-group"><label class="form-label" for="crm-plan-priority">' + escapeHtml(t('Priority')) + '</label>';
      html += '<select class="crm-input form-select" id="crm-plan-priority" name="priority">';
      [
        { value: 'p1', label: t('High') },
        { value: 'p2', label: t('Normal') },
        { value: 'p3', label: t('Low') },
      ].forEach((p) => {
        html += '<option value="' + p.value + '"' + ((plan.priority || 'p2') === p.value ? ' selected' : '') + '>' + escapeHtml(p.label) + '</option>';
      });
      html += '</select></div>';
      html += '<div class="form-group"><label class="form-label" for="crm-plan-owner-search">' + escapeHtml(t('Plan owner')) + '</label>';
      html += '<div class="user-picker" id="crm-plan-owner-picker">';
      html += '<div class="user-picker__control">';
      html += '<input type="search" id="crm-plan-owner-search" class="form-input crm-input user-picker__search" role="combobox" aria-autocomplete="list" aria-controls="crm-plan-owner-suggest" aria-expanded="false" autocomplete="off" placeholder="' + escapeHtml(t('Search by name or login…')) + '" />';
      html += '<button type="button" class="user-picker__clear" id="crm-plan-owner-clear" hidden aria-label="' + escapeHtml(t('Clear')) + '">×</button></div>';
      html += '<ul id="crm-plan-owner-suggest" class="user-picker__list" role="listbox" hidden></ul>';
      html += '<input type="hidden" id="crm-plan-owner" name="ownerUserId" value="" />';
      html += '</div><p class="crm-muted crm-account-plan__hint">' + escapeHtml(t('Search by name or login, then pick a colleague. Never type a raw user id.')) + '</p></div>';
      html += '<div class="form-group"><label class="form-label" for="crm-plan-review">' + escapeHtml(t('Next review')) + '</label>';
      html += '<input class="crm-input form-input datepicker-input" id="crm-plan-review" name="nextAssignOn" type="date" value="' + escapeHtml(plan.nextAssignOn || '') + '" autocomplete="off" /></div>';
      html += '</div></details>';
      html += '<div class="crm-form-actions crm-account-plan__save">';
      if (accountPlanJustSaved()) {
        html += '<p class="crm-callout crm-callout--success crm-account-plan__saved" id="crm-plan-save-status" role="status">' + escapeHtml(t('Account plan saved.')) + '</p>';
      }
      html += '<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg' + (accountPlanJustSaved() ? ' crm-account-plan__save-btn--done' : '') + '"' + (accountPlanJustSaved() ? ' data-plan-just-saved="1"' : '') + '>' + escapeHtml(accountPlanJustSaved() ? t('Saved.') : t('Save plan')) + '</button></div></form>';
      html += '</section>';
      html += '</div>';
      html += '<div class="crm-account-plan__col crm-account-plan__col--moves">';
      html += renderMovesSection(mapSteps, true);
      html += '</div></div></div>';
      body.innerHTML = html;
      const ownerUid = String(plan.ownerUserId || '').trim();
      const ownerSearch = document.getElementById('crm-plan-owner-search');
      const ownerSuggest = document.getElementById('crm-plan-owner-suggest');
      const ownerHidden = document.getElementById('crm-plan-owner');
      const ownerClear = document.getElementById('crm-plan-owner-clear');
      let ownerTimer = null;
      let ownerSearchGeneration = 0;
      const workspaceId = (function () {
        const root = document.querySelector('.crm-page-stack[data-workspace-id]');
        const id = root ? parseInt(root.getAttribute('data-workspace-id') || '0', 10) : 0;
        return id > 0 ? id : 0;
      })();
      if (ownerHidden && ownerUid) {
        ownerHidden.value = ownerUid;
        if (ownerSearch) {
          ownerSearch.value = accountPlanOwnerSearchLabel(plan);
        }
        if (ownerClear) {
          ownerClear.hidden = false;
        }
      }
      if (ownerClear && ownerSearch && ownerHidden) {
        ownerClear.addEventListener('click', () => {
          ownerHidden.value = '';
          ownerSearch.value = '';
          if (ownerSuggest) {
            ownerSuggest.innerHTML = '';
            ownerSuggest.hidden = true;
          }
          ownerClear.hidden = true;
          ownerSearch.focus();
        });
      }
      if (ownerSearch && ownerSuggest && ownerHidden && Api) {
        const ownerNav = (window.CustomerCheckUserPickerNav && window.CustomerCheckUserPickerNav.bindListboxKeyboard)
          ? window.CustomerCheckUserPickerNav.bindListboxKeyboard(ownerSearch, ownerSuggest)
          : { reset: function () {} };
        ownerSearch.addEventListener('input', () => {
          if (ownerHidden.value) {
            ownerHidden.value = '';
            if (ownerClear) ownerClear.hidden = true;
          }
          clearTimeout(ownerTimer);
          const q = ownerSearch.value.trim();
          if (q.length < 2) {
            ownerSearchGeneration += 1;
            ownerSuggest.hidden = true;
            ownerSuggest.innerHTML = '';
            ownerSearch.setAttribute('aria-expanded', 'false');
            ownerNav.reset();
            return;
          }
          ownerSearchGeneration += 1;
          const myGeneration = ownerSearchGeneration;
          ownerTimer = setTimeout(async () => {
            try {
              let url = '/settings/users?q=' + encodeURIComponent(q);
              if (workspaceId > 0) {
                url += '&workspaceId=' + encodeURIComponent(String(workspaceId));
              }
              const res = await Api.get(url);
              if (myGeneration !== ownerSearchGeneration) {
                return;
              }
              const users = (res.data && res.data.users) || [];
              ownerSuggest.innerHTML = '';
              users.forEach((u) => {
                const opt = document.createElement('li');
                opt.className = 'user-picker__item';
                opt.setAttribute('role', 'option');
                opt.setAttribute('aria-selected', 'false');
                const name = document.createElement('span');
                name.className = 'user-picker__name';
                name.textContent = u.displayName || u.id;
                const meta = document.createElement('span');
                meta.className = 'user-picker__meta';
                meta.textContent = u.id || '';
                opt.appendChild(name);
                opt.appendChild(meta);
                opt.addEventListener('click', () => {
                  ownerHidden.value = String(u.id || '');
                  ownerSearch.value = (u.displayName || u.id || '') + (u.id ? ' (' + u.id + ')' : '');
                  ownerSuggest.innerHTML = '';
                  ownerSuggest.hidden = true;
                  ownerSearch.setAttribute('aria-expanded', 'false');
                  ownerNav.reset();
                  if (ownerClear) ownerClear.hidden = !ownerHidden.value;
                });
                ownerSuggest.appendChild(opt);
              });
              ownerSuggest.hidden = users.length === 0;
              ownerSearch.setAttribute('aria-expanded', users.length === 0 ? 'false' : 'true');
              ownerNav.reset();
            } catch (e) {
              ownerSuggest.hidden = true;
              ownerSearch.setAttribute('aria-expanded', 'false');
              ownerNav.reset();
            }
          }, 250);
        });
      }
      const form = document.getElementById('crm-account-plan-form');
      if (form) {
        bindAccountPlanDirty(form);
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          if (form.getAttribute('aria-busy') === 'true') return;
          const submitBtn = form.querySelector('button[type="submit"]');
          const submitLabel = submitBtn ? submitBtn.textContent : '';
          form.setAttribute('aria-busy', 'true');
          if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = t('Saving…');
          }
          try {
            const reviewEl = document.getElementById('crm-plan-review') || form.elements.nextAssignOn;
            const putRes = await Api.put('/companies/' + companyId + '/account-plan', {
              goal: form.elements.goal ? form.elements.goal.value : '',
              priority: form.elements.priority ? form.elements.priority.value : 'p2',
              ownerUserId: ownerHidden ? ownerHidden.value : '',
              horizon: plan.horizon || '',
              nextAssignOn: (window.CustomerCheckDatepicker && typeof window.CustomerCheckDatepicker.isoValue === 'function')
                ? window.CustomerCheckDatepicker.isoValue(reviewEl)
                : (reviewEl && reviewEl.value) || '',
              status: plan.status || 'active',
              updatedAt: form.getAttribute('data-plan-updated-at') || plan.updatedAt || '',
            });
            accountPlanSavedUntil = Date.now() + 12000;
            announceAccountPlanSaved();
            const savedPlan = putRes && putRes.data && typeof putRes.data === 'object' ? putRes.data : null;
            if (savedPlan) {
              await loadAccountPlan(savedPlan);
            } else {
              await loadAccountPlan();
            }
          } catch (e) {
            const isConflict = e && (Number(e.status) === 409
              || String((e.payload && e.payload.message) || e.message || '').indexOf('Someone else changed this account plan') >= 0);
            const errMsg = (e && e.payload && e.payload.message) || (e && e.message) || t('Could not save account plan');
            if (C && typeof C.showToast === 'function') {
              C.showToast({ type: 'error', title: t('Could not save account plan'), message: String(errMsg) });
            }
            if (C && typeof C.alertDialog === 'function') {
              await C.alertDialog({
                title: t('Could not save account plan'),
                message: errMsg,
                variant: 'danger',
              });
            }
            if (isConflict) {
              await loadAccountPlan();
            }
          } finally {
            if (form.isConnected) {
              form.removeAttribute('aria-busy');
              if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitLabel || t('Save plan');
              }
            }
          }
        });
      }
      body.querySelectorAll('[data-crm-map-step-done]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const stepId = parseInt(btn.getAttribute('data-crm-map-step-done') || '0', 10);
          if (!(stepId > 0)) return;
          btn.disabled = true;
          try {
            await Api.post('/companies/' + companyId + '/account-plan/map-steps/' + stepId + '/done', {});
            if (liveRegion()) liveRegion().textContent = t('Move marked done.');
            await loadAccountPlan();
          } catch (e) {
            btn.disabled = false;
            await C.alertDialog({ title: t('Could not mark move done'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
          }
        });
      });
      const mapForm = document.getElementById('crm-map-step-form');
      if (mapForm) {
        mapForm.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          if (mapForm.getAttribute('aria-busy') === 'true') return;
          const title = String(mapForm.elements.title.value || '').replace(/\s+/g, ' ').trim();
          if (!title) {
            mapForm.elements.title.focus();
            return;
          }
          const submitBtn = mapForm.querySelector('button[type="submit"]');
          mapForm.setAttribute('aria-busy', 'true');
          if (submitBtn) submitBtn.disabled = true;
          try {
            await Api.post('/companies/' + companyId + '/account-plan/map-steps', { title: title });
            if (liveRegion()) liveRegion().textContent = t('Move added.');
            await loadAccountPlan();
          } catch (e) {
            mapForm.removeAttribute('aria-busy');
            if (submitBtn) submitBtn.disabled = false;
            await C.alertDialog({ title: t('Could not add next step'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
          }
        });
      }
    } catch (e) {
      if (generation !== accountPlanGeneration) {
        return;
      }
      body.innerHTML = '';
      const callout = document.createElement('div');
      callout.className = 'crm-callout crm-callout--danger';
      callout.setAttribute('role', 'alert');
      const msg = document.createElement('p');
      msg.textContent = t('Could not load account plan.');
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'crm-btn crm-btn--primary';
      retry.textContent = t('Try again');
      retry.addEventListener('click', () => {
        body.innerHTML = '<p class="crm-muted">' + escapeHtml(t('Loading account plan…')) + '</p>';
        loadAccountPlan();
      });
      callout.appendChild(msg);
      callout.appendChild(retry);
      body.appendChild(callout);
    }
  }

  async function loadPromises() {
    const body = document.getElementById('crm-promises-body');
    if (!body || !companyId) return;
    let generation = 0;
    try {
      generation = ++promisesGeneration;
      const res = await Api.get('/companies/' + companyId + '/promises');
      if (generation !== promisesGeneration) {
        return;
      }
      const items = (res.data && res.data.items) || res.items || [];
      if (!items.length) {
        body.innerHTML = '<p class="crm-muted" role="status">' + escapeHtml(t('Nothing told the customer yet')) + '</p>';
        return;
      }
      let html = '<ul class="crm-todo-list" role="list">';
      items.forEach((p) => {
        const due = p.dueOn ? formatDisplayDate(p.dueOn) : '—';
        const open = (p.status || '') === 'open';
        html += '<li class="crm-todo-list__item' + (open ? '' : ' crm-todo-list__item--closed') + '" data-promise-id="' + escapeHtml(String(p.id)) + '">';
        html += '<div class="crm-todo-list__main"><p class="crm-todo-list__title">' + escapeHtml(p.title || '') + '</p>';
        html += '<p class="crm-muted crm-todo-list__meta"><span>' + escapeHtml(t('Due')) + ': ' + escapeHtml(due) + '</span>';
        html += '<span class="crm-badge">' + escapeHtml(p.statusLabel || p.status || '') + '</span></p></div>';
        if (shellCan('write') && open) {
          html += '<div class="crm-todo-list__actions">';
          html += '<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-promise-done="' + escapeHtml(String(p.id)) + '">' + escapeHtml(t('Done')) + '</button>';
          html += '<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" data-crm-promise-cancel="' + escapeHtml(String(p.id)) + '">' + escapeHtml(t('Cancel')) + '</button>';
          html += '</div>';
        }
        html += '</li>';
      });
      html += '</ul>';
      body.innerHTML = html;
      body.querySelectorAll('[data-crm-promise-done]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-crm-promise-done');
          if (!id || btn.disabled) return;
          btn.disabled = true;
          try {
            // Bachus: one tap — default note satisfies API without an Evidence jargon dialog.
            await Api.post('/companies/' + companyId + '/promises/' + id + '/done', {
              evidence: t('Done'),
            });
            if (liveRegion()) liveRegion().textContent = t('Promise marked done.');
            await loadPromises();
          } catch (e) {
            btn.disabled = false;
            await C.alertDialog({ title: t('Could not complete promise'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
          }
        });
      });
      body.querySelectorAll('[data-crm-promise-cancel]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = btn.getAttribute('data-crm-promise-cancel');
          const ok = await C.confirmDialog({
            title: t('Cancel promise?'),
            message: t('This marks the promise as cancelled. It will no longer appear as open.'),
            confirmLabel: t('Cancel promise'),
            cancelLabel: t('Keep open'),
            variant: 'danger',
          });
          if (!ok) return;
          try {
            await Api.post('/companies/' + companyId + '/promises/' + id + '/cancel', {});
            if (liveRegion()) liveRegion().textContent = t('Promise cancelled.');
            await loadPromises();
          } catch (e) {
            await C.alertDialog({ title: t('Could not cancel promise'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
          }
        });
      });
    } catch (e) {
      if (generation !== promisesGeneration) {
        return;
      }
      body.innerHTML = '';
      const callout = document.createElement('div');
      callout.className = 'crm-callout crm-callout--danger';
      callout.setAttribute('role', 'alert');
      const msg = document.createElement('p');
      msg.textContent = t('Could not load promises.');
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'crm-btn crm-btn--primary';
      retry.textContent = t('Try again');
      retry.addEventListener('click', () => {
        body.innerHTML = '<p class="crm-muted">' + escapeHtml(t('Loading promises…')) + '</p>';
        loadPromises();
      });
      callout.appendChild(msg);
      callout.appendChild(retry);
      body.appendChild(callout);
    }
  }

  // Boot only after generation counters are initialized (avoids TDZ hang).
  loadCommandCenter();
  loadAccountPlan();
  loadPromises();

  const addPromiseBtn = document.getElementById('crm-add-promise');
  if (addPromiseBtn) {
    addPromiseBtn.addEventListener('click', async () => {
      const today = localYmd();
      const data = await promptFields(t('Told the customer'), [
        {
          name: 'title',
          label: t('What did you tell them?'),
          type: 'textarea',
          rows: 3,
          required: true,
          maxlength: 255,
          hint: t('Write it in plain words — a short sentence is enough.'),
        },
        { name: 'dueOn', label: t('Due date'), type: 'date', value: today, required: true },
      ]);
      if (!data) return;
      try {
        await Api.post('/companies/' + companyId + '/promises', {
          title: data.title,
          dueOn: data.dueOn || null,
        });
        if (liveRegion()) liveRegion().textContent = t('Promise added.');
        await loadPromises();
      } catch (e) {
        await C.alertDialog({ title: t('Could not add promise'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  // SHARED-IDENTITY W1 — 360 create/link/unlink CTAs (MH-04/05 / NN-12)
  // Must stay inside this IIFE: Api / C / t / companyId / promptFields are local consts.
  (function wireIdentityLinks() {
    const box = document.getElementById('crm-identity-links');
    if (!box || !Api) return;
    const alertEl = document.getElementById('crm-identity-alert');
    let updatedAt = box.getAttribute('data-updated-at') || null;
    const companyIdLocal = parseInt(box.getAttribute('data-company-id') || '0', 10) || companyId;
    /** @type {Record<string, string>} Stable keys for in-flight / retry (same click storm → same key). */
    const pendingKeys = Object.create(null);
    let inFlight = false;

    function showAlert(msg) {
      if (!alertEl) return;
      alertEl.hidden = !msg;
      alertEl.textContent = msg || '';
    }

    function setIdentityBusy(busy) {
      box.querySelectorAll('button[data-crm-identity-create], button[data-crm-identity-link], button[data-crm-identity-unlink], button[data-crm-identity-create-field]').forEach((btn) => {
        btn.disabled = !!busy;
        btn.setAttribute('aria-busy', busy ? 'true' : 'false');
      });
    }

    function idemHeaders(sibling, mode, existingId) {
      const slot = String(sibling) + ':' + String(mode || 'create') + ':' + String(existingId || 0);
      if (!pendingKeys[slot]) {
        pendingKeys[slot] = Api.makeIdempotencyKey
          ? Api.makeIdempotencyKey()
          : ('idem-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10));
      }
      return { 'Idempotency-Key': pendingKeys[slot] };
    }

    async function createOrLink(sibling, mode, existingId) {
      if (inFlight) return;
      inFlight = true;
      setIdentityBusy(true);
      showAlert('');
      const path = sibling === 'tc'
        ? '/companies/' + companyIdLocal + '/create-tc-customer'
        : '/companies/' + companyIdLocal + '/create-pc-customer';
      const body = { updatedAt: updatedAt, mode: mode || 'create' };
      if (existingId) {
        if (sibling === 'tc') body.existingTcCustomerId = existingId;
        else body.existingPcCustomerId = existingId;
        body.mode = 'link';
      }
      const slot = String(sibling) + ':' + String(body.mode) + ':' + String(existingId || 0);
      try {
        const res = await Api.post(path, body, idemHeaders(sibling, body.mode, existingId));
        delete pendingKeys[slot];
        const company = res.data && (res.data.company || res.data);
        if (company && company.updatedAt) updatedAt = company.updatedAt;
        window.location.reload();
      } catch (e) {
        const fields = (e.payload && e.payload.fields) || {};
        const existing = parseInt(fields[sibling === 'tc' ? 'existingTcCustomerId' : 'existingPcCustomerId'], 10);
        showAlert((e.payload && e.payload.message) || e.message);
        if (Number.isFinite(existing) && existing > 0) {
          const ok = await C.confirmDialog({
            title: t('Link existing customer?'),
            message: t('A customer with this name already exists. Link the existing one?'),
            confirmLabel: t('Link existing'),
            cancelLabel: t('Cancel'),
          });
          if (ok) {
            inFlight = false;
            setIdentityBusy(false);
            await createOrLink(sibling, 'link', existing);
            return;
          }
        }
      } finally {
        inFlight = false;
        setIdentityBusy(false);
      }
    }

    async function unlink(sibling) {
      const msg = sibling === 'tc'
        ? t('Unlink TicketCheck? Tickets will hide until you link again.')
        : t('Unlink ProjectCheck? Projects and invoices will hide until you link again.');
      const ok = await C.confirmDialog({
        title: t('Unlink?'),
        message: msg,
        confirmLabel: t('Unlink'),
        cancelLabel: t('Cancel'),
        variant: 'danger',
      });
      if (!ok) return;
      showAlert('');
      const path = sibling === 'tc'
        ? '/companies/' + companyIdLocal + '/unlink/ticketcheck'
        : '/companies/' + companyIdLocal + '/unlink/projectcheck';
      try {
        const res = await Api.post(path, { updatedAt: updatedAt });
        const company = res.data && (res.data.company || res.data);
        if (company && company.updatedAt) updatedAt = company.updatedAt;
        window.location.reload();
      } catch (e) {
        showAlert((e.payload && e.payload.message) || e.message);
      }
    }

    box.querySelectorAll('[data-crm-identity-create]').forEach((btn) => {
      btn.addEventListener('click', () => createOrLink(btn.getAttribute('data-crm-identity-create'), 'create'));
    });
    box.querySelectorAll('[data-crm-identity-link]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const sibling = btn.getAttribute('data-crm-identity-link');
        const data = await promptFields(t('Link existing customer'), [
          {
            name: 'existingId',
            label: t('Customer id'),
            required: true,
            hint: t('Enter the existing customer id to link'),
          },
        ]);
        if (!data) return;
        const id = parseInt(data.existingId || '', 10);
        if (!Number.isFinite(id) || id <= 0) {
          await C.alertDialog({
            title: t('Invalid id'),
            message: t('Enter a positive customer id.'),
            variant: 'warning',
          });
          return;
        }
        await createOrLink(sibling, 'link', id);
      });
    });
    box.querySelectorAll('[data-crm-identity-unlink]').forEach((btn) => {
      btn.addEventListener('click', () => unlink(btn.getAttribute('data-crm-identity-unlink')));
    });
    const fieldBtn = box.querySelector('[data-crm-identity-create-field]');
    if (fieldBtn) {
      fieldBtn.addEventListener('click', async () => {
        showAlert('');
        try {
          await Api.post('/companies/' + companyIdLocal + '/create-field-customer', {});
          showAlert(t('Field customer created in MaintenanceCheck.'));
        } catch (e) {
          showAlert((e.payload && e.payload.message) || e.message);
        }
      });
    }
  })();

})();
