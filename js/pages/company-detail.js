(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg, args) => (window.t ? window.t('customercheck', msg, args || []) : msg);
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
          html += '<textarea class="crm-input form-input" id="' + id + '-' + fname + '" name="' + fname + '" rows="3"' +
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

  const addPerson = document.getElementById('crm-add-person');
  if (addPerson) {
    addPerson.addEventListener('click', async () => {
      let contactsHint = '';
      let contactsAvailable = false;
      try {
        const suggestPayload = await Api.get('/contacts/suggest?q=aa&limit=1');
        const suggest = (suggestPayload && suggestPayload.data) ? suggestPayload.data : suggestPayload;
        contactsAvailable = !!(suggest && suggest.available);
        if (contactsAvailable) {
          contactsHint = t('Tip: start typing a name — matches from Contacts appear after you save.');
        }
      } catch (e) {
        contactsHint = '';
      }
      const data = await promptFields(t('Add person'), [
        { name: 'name', label: t('Name'), required: true, hint: contactsHint || t('Who do you talk to at this company?') },
        { name: 'email', label: t('Email'), type: 'email', hint: t('Optional — needed to log emails with them.') },
        { name: 'phone', label: t('Phone'), advanced: true },
        { name: 'role', label: t('Job title'), advanced: true },
        {
          name: 'buyingRole',
          label: t('Role in buying'),
          type: 'select',
          value: '',
          options: buyingRoleOptionsFromDom(),
          advanced: true,
        },
      ]);
      if (!data) return;
      let contactsUid = null;
      // Optional Contacts fill-in when the name looks like a search.
      // Bachus: one exact match applies silently; only ask when 2+ matches compete.
      if (contactsAvailable && data.name && data.name.length >= 2) {
        try {
          const resPayload = await Api.get('/contacts/suggest?q=' + encodeURIComponent(data.name) + '&limit=5');
          const res = (resPayload && resPayload.data) ? resPayload.data : resPayload;
          const items = (res && Array.isArray(res.items) && res.items.length)
            ? res.items
            : (res && Array.isArray(res.suggestions) ? res.suggestions : []);
          const applyContact = (match) => {
            if (!match || !match.name) return;
            data.name = match.name || data.name;
            data.email = match.email || data.email || '';
            data.phone = match.phone || data.phone || '';
            if (match.uid) {
              contactsUid = String(match.uid);
            }
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
            if (use) {
              applyContact(first);
            }
          }
        } catch (e) {
          // Contacts optional — continue with typed values.
        }
      }
      try {
        const payload = Object.assign({ isPrimary: false }, data);
        if (contactsUid) {
          payload.contactsUid = contactsUid;
        }
        await Api.post('/companies/' + companyId + '/persons', payload);
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not add person'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
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
    const data = await promptFields(t('Log email'), fields);
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
        const row = btn.closest('tr[data-person-id]');
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
      const row = sel.closest('tr[data-person-id]');
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
      Company360Present.renderBillingSummaryTiles(body, tiles, t);
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
    const rows = [
      [t('Company'), dto.name || '—', ''],
      [t('Primary contact'), primary ? ((primary.name || '') + (primary.role ? ' · ' + primary.role : '')) : t('No primary person'), ''],
      [t('Coverage'), coverageLabel, missingText],
      [t('Next touch'), nextTouch ? formatDisplayDate(nextTouch) : t('Not scheduled'), ''],
      [t('Last discussion'), last.summary ? last.summary : t('Nothing logged yet'), last.summary && last.at ? formatDisplayDate(last.at) : ''],
      [t('Next action'), next.label || t('No urgent action'), ''],
      [t('Money'), billingStateLabel(billing.state || 'unknown') + (billing.overdueCount ? ' · ' + billing.overdueCount + ' ' + t('overdue') : ''), ''],
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
      const today = new Date().toISOString().slice(0, 10);
      const data = await promptFields(t('Add reminder'), [
        { name: 'title', label: t('What should you remember?'), required: true, hint: t('Example: Call Anna about renewal.') },
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
      ]);
      if (!data) return;
      if (!data.dueDate) data.dueDate = today;
      if (!data.type) data.type = 'todo';
      try {
        await Api.post('/activities', Object.assign({ companyId }, data));
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not add reminder'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const addDeal = document.getElementById('crm-add-deal');
  if (addDeal) {
    addDeal.addEventListener('click', async () => {
      const data = await promptFields(t('Add deal'), [
        {
          name: 'title',
          label: t('What is this deal?'),
          required: true,
          hint: t('Example: Renewal 2026 or Website project. A title is enough.'),
          maxlength: 255,
        },
        {
          name: 'notes',
          label: t('Notes'),
          type: 'textarea',
          advanced: true,
          maxlength: 4000,
          hint: t('Internal notes for your team — the customer does not see these.'),
        },
        { name: 'valueEur', label: t('Value (EUR, optional)'), type: 'number', min: 0, step: '0.01', advanced: true },
        { name: 'products', label: t('Products (optional)'), advanced: true, maxlength: 255 },
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
        await Api.post('/deals', {
          companyId,
          title,
          stage: 'lead',
          valueCents,
          products: data.products || '',
          notes: String(data.notes || '').trim(),
        });
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not add deal'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

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

  async function openLogHours(agreementId) {
    const today = new Date().toISOString().slice(0, 10);
    const data = await promptFields(t('Log hours'), [
      { name: 'entryDate', label: t('Date'), type: 'date', required: true, value: today },
      {
        name: 'minutes',
        label: t('Minutes'),
        type: 'number',
        required: true,
        value: '60',
        hint: t('Steps of 15 minutes. Default is one hour.'),
      },
      { name: 'topic', label: t('Topic'), required: true },
    ]);
    if (!data) return;
    if (!data.entryDate) data.entryDate = today;
    let minutes = parseInt(data.minutes, 10);
    if (!Number.isFinite(minutes) || minutes < 15) minutes = 15;
    minutes = Math.round(minutes / 15) * 15;
    try {
      await Api.post('/agreements/' + agreementId + '/hours', {
        entryDate: data.entryDate,
        minutes: minutes,
        topic: data.topic,
      });
      location.reload();
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
      btn.disabled = true;
      try {
        await Api.post('/activities/' + btn.getAttribute('data-crm-complete-activity') + '/done', {
          done: true,
          updatedAt: btn.getAttribute('data-updated-at') || null,
        });
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Activity marked done.');
        location.reload();
      } catch (e) {
        btn.disabled = false;
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
      return d.toISOString().slice(0, 10);
    }
    const parts = String(isoDate).split('-').map((n) => parseInt(n, 10));
    if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) {
      const d = new Date();
      d.setFullYear(d.getFullYear() + years);
      return d.toISOString().slice(0, 10);
    }
    const d = new Date(Date.UTC(parts[0] + years, parts[1] - 1, parts[2]));
    return d.toISOString().slice(0, 10);
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
  let promisesGeneration = 0;
  async function loadCommandCenter() {
    if (!companyId || !Api) return;
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
                '<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-log-email">' + escapeHtml(t('Log email')) + '</button> ' +
                '<button type="button" class="crm-btn crm-btn--secondary" data-crm-trigger="crm-add-activity">' + escapeHtml(t('Add reminder')) + '</button>' +
                '</div>'
              : '') +
            '</div>';
        } else {
          feedEl.innerHTML = '';
          items.forEach((item) => {
            const row = document.createElement('article');
            row.className = 'crm-feed__item';

            const badges = document.createElement('div');
            badges.className = 'crm-feed__badges';
            const badge = document.createElement('span');
            badge.className = 'crm-badge';
            badge.textContent = feedKindLabel(item.kind);
            badges.appendChild(badge);
            if (item.shared || item.kind === 'shared_mail') {
              const sharedBadge = document.createElement('span');
              sharedBadge.className = 'crm-badge crm-badge--warning';
              sharedBadge.textContent = t('Shared');
              badges.appendChild(sharedBadge);
            }
            if (item.private || item.kind === 'mail_related') {
              const privBadge = document.createElement('span');
              privBadge.className = 'crm-badge crm-badge--neutral';
              privBadge.textContent = item.privacyBadge || t('Private to you');
              badges.appendChild(privBadge);
            }
            row.appendChild(badges);

            const strong = document.createElement('strong');
            strong.className = 'crm-feed__subject';
            strong.textContent = item.subject || '';
            row.appendChild(strong);

            const meta = document.createElement('p');
            meta.className = 'crm-feed__meta crm-muted';
            const metaParts = [];
            if (item.occurredAt) metaParts.push(formatDisplayDate(item.occurredAt));
            if (item.personName) metaParts.push(item.personName);
            meta.textContent = metaParts.join(' · ');
            row.appendChild(meta);
            feedEl.appendChild(row);
          });
        }
      }

      const tasksBody = document.getElementById('crm-open-tasks-body');
      if (tasksBody) {
        const items = (tasks.data && tasks.data.items) || tasks.items || [];
        if (!items.length) {
          const canWriteTasks = shellCan('write');
          tasksBody.innerHTML = '<div class="crm-empty-state" role="status">' +
            '<h3 class="crm-empty-state__title">' + escapeHtml(t('No open tasks')) + '</h3>' +
            '<p class="crm-empty-state__text">' + escapeHtml(t('Nothing open for this company.')) + '</p>' +
            (canWriteTasks
              ? '<div class="crm-empty-state__actions"><button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-new-task">' + escapeHtml(t('New task')) + '</button></div>'
              : '') +
            '</div>';
        } else {
          const table = document.createElement('div');
          table.className = 'table-container crm-table--responsive';
          let html = '<table class="table table--hover"><thead><tr><th scope="col">' + escapeHtml(t('Title')) + '</th><th scope="col">' + escapeHtml(t('Due')) + '</th><th scope="col" class="crm-table-actions-col">' + escapeHtml(t('Action')) + '</th></tr></thead><tbody>';
          items.forEach((row) => {
            const deep = safeInternalHref(row.tasksDeepLink || '/apps/tasks/', '/apps/tasks/');
            const tasksCell = row.inTasks
              ? ('<div class="crm-table-actions"><a class="crm-btn crm-btn--sm crm-btn--primary" href="' + escapeHtml(deep) + '">' + escapeHtml(t('Open in Tasks')) + '</a></div>')
              : ('<div class="crm-table-actions"><span class="crm-muted" aria-hidden="true">—</span></div>');
            html += '<tr><td data-label="' + escapeHtml(t('Title')) + '">' + escapeHtml(row.title || '') + (row.isCallback ? ' <span class="crm-badge">' + escapeHtml(t('Callback')) + '</span>' : '') + '</td><td data-label="' + escapeHtml(t('Due')) + '">' + escapeHtml(row.dueDate ? formatDisplayDate(row.dueDate) : '—') + '</td><td class="actions-cell crm-table-actions-col" data-label="' + escapeHtml(t('Action')) + '">' + tasksCell + '</td></tr>';
          });
          html += '</tbody></table>';
          table.innerHTML = html;
          tasksBody.innerHTML = '';
          tasksBody.appendChild(table);
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
        const links = platform.data || platform || {};
        plat.innerHTML = '';
        Object.keys(links).forEach((key) => {
          const info = links[key];
          if (!info || !info.url) return;
          const a = document.createElement('a');
          a.className = 'crm-platform-links__chip';
          a.href = info.url;
          a.textContent = info.label || key;
          plat.appendChild(a);
        });
        if (!plat.children.length) {
          plat.innerHTML = '<p class="crm-muted">' + t('No related apps enabled.') + '</p>';
        }
      }
      if (live) live.textContent = t('Company overview updated.');
    } catch (e) {
      if (generation !== commandCenterGeneration) {
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
      const ymd = tomorrow.toISOString().slice(0, 10);
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
      const ymd = tomorrow.toISOString().slice(0, 10);
      const data = await promptFields(t('New task'), [
        { name: 'title', label: t('What needs doing?'), type: 'text', required: true },
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

  async function loadAccountPlan() {
    const body = document.getElementById('crm-account-plan-body');
    if (!body || !companyId) return;
    const canWrite = shellCan('write');
    const priorityLabel = function (code) {
      if (code === 'p1') return t('High');
      if (code === 'p3') return t('Low');
      return t('Normal');
    };
    let generation = 0;
    try {
      generation = ++accountPlanGeneration;
      const res = await Api.get('/companies/' + companyId + '/account-plan');
      if (generation !== accountPlanGeneration) {
        return;
      }
      const plan = res.data || res || {};
      const mapSteps = plan.mapSteps || [];
      let html = '';
      if (!canWrite) {
        html += '<dl class="crm-dl">';
        html += '<div><dt>' + escapeHtml(t('Goal')) + '</dt><dd>' + escapeHtml(plan.goal || '—') + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Priority')) + '</dt><dd>' + escapeHtml(priorityLabel(plan.priority || 'p2')) + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Plan owner')) + '</dt><dd>' + escapeHtml(plan.ownerUserId || '—') + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Next review')) + '</dt><dd>' + escapeHtml(plan.nextReviewOn || '—') + '</dd></div>';
        if (plan.healthLabel || plan.health) {
          html += '<div><dt>' + escapeHtml(t('Health')) + '</dt><dd><span class="crm-badge">' + escapeHtml(plan.healthLabel || plan.health) + '</span></dd></div>';
        }
        html += '</dl>';
        if (mapSteps.length) {
          html += '<h3 class="crm-card__title">' + escapeHtml(t('Next steps')) + '</h3><ul class="crm-simple-list">';
          mapSteps.forEach((s) => {
            html += '<li>' + escapeHtml(s.title || '') + ' · <span class="crm-badge">' + escapeHtml(s.statusLabel || s.status || '') + '</span></li>';
          });
          html += '</ul>';
        } else {
          html += '<p class="crm-muted">' + escapeHtml(t('No next steps yet.')) + '</p>';
        }
        body.innerHTML = html;
        return;
      }
      html = '<form id="crm-account-plan-form" class="crm-form crm-form--stack" data-plan-updated-at="' + escapeHtml(plan.updatedAt || '') + '">';
      html += '<div class="form-group"><label class="form-label" for="crm-plan-goal">' + escapeHtml(t('Goal')) + '</label>';
      html += '<textarea class="crm-input form-input" id="crm-plan-goal" name="goal" rows="3">' + escapeHtml(plan.goal || '') + '</textarea></div>';
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
      html += '</div><p class="crm-muted">' + escapeHtml(t('Search by name or login, then pick a colleague. Never type a raw user id.')) + '</p></div>';
      html += '<div class="form-group"><label class="form-label" for="crm-plan-review">' + escapeHtml(t('Next review')) + '</label>';
      html += '<input class="crm-input form-input" id="crm-plan-review" name="nextReviewOn" type="date" value="' + escapeHtml(plan.nextReviewOn || '') + '" /></div>';
      if (plan.healthLabel || plan.health) {
        html += '<p role="status"><span class="crm-badge">' + escapeHtml(plan.healthLabel || plan.health) + '</span></p>';
      }
      html += '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save plan')) + '</button></div></form>';
      if (mapSteps.length) {
        html += '<h3 class="crm-card__title">' + escapeHtml(t('Next steps')) + '</h3><ul class="crm-simple-list">';
        mapSteps.forEach((s) => {
          html += '<li>' + escapeHtml(s.title || '') + ' · <span class="crm-badge">' + escapeHtml(s.statusLabel || s.status || '') + '</span></li>';
        });
        html += '</ul>';
      } else {
        html += '<p class="crm-muted">' + escapeHtml(t('No next steps yet.')) + '</p>';
      }
      html += '<form id="crm-map-step-form" class="crm-form crm-form--stack crm-form--inline" aria-label="' + escapeHtml(t('Add next step')) + '">';
      html += '<label class="form-label" for="crm-map-step-title">' + escapeHtml(t('New next step')) + '</label>';
      html += '<input class="crm-input form-input" id="crm-map-step-title" name="title" type="text" required maxlength="255" />';
      html += '<button type="submit" class="crm-btn crm-btn--secondary">' + escapeHtml(t('Add step')) + '</button></form>';
      body.innerHTML = html;
      const ownerUid = String(plan.ownerUserId || '').trim();
      const ownerSearch = document.getElementById('crm-plan-owner-search');
      const ownerSuggest = document.getElementById('crm-plan-owner-suggest');
      const ownerHidden = document.getElementById('crm-plan-owner');
      const ownerClear = document.getElementById('crm-plan-owner-clear');
      let ownerTimer = null;
      const workspaceId = (function () {
        const root = document.querySelector('.crm-page-stack[data-workspace-id]');
        const id = root ? parseInt(root.getAttribute('data-workspace-id') || '0', 10) : 0;
        return id > 0 ? id : 0;
      })();
      if (ownerHidden && ownerUid) {
        ownerHidden.value = ownerUid;
        if (ownerSearch) {
          ownerSearch.value = ownerUid;
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
            ownerSuggest.hidden = true;
            ownerSuggest.innerHTML = '';
            ownerSearch.setAttribute('aria-expanded', 'false');
            ownerNav.reset();
            return;
          }
          ownerTimer = setTimeout(async () => {
            try {
              let url = '/settings/users?q=' + encodeURIComponent(q);
              if (workspaceId > 0) {
                url += '&workspaceId=' + encodeURIComponent(String(workspaceId));
              }
              const res = await Api.get(url);
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
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          try {
            await Api.put('/companies/' + companyId + '/account-plan', {
              goal: form.elements.goal.value,
              priority: form.elements.priority.value,
              ownerUserId: ownerHidden ? ownerHidden.value : '',
              nextReviewOn: form.elements.nextReviewOn.value,
              status: plan.status || 'active',
              updatedAt: form.getAttribute('data-plan-updated-at') || plan.updatedAt || '',
            });
            if (liveRegion()) liveRegion().textContent = t('Account plan saved.');
            await loadAccountPlan();
          } catch (e) {
            await C.alertDialog({ title: t('Could not save account plan'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
          }
        });
      }
      const mapForm = document.getElementById('crm-map-step-form');
      if (mapForm) {
        mapForm.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const title = String(mapForm.elements.title.value || '').trim();
          if (!title) return;
          try {
            await Api.post('/companies/' + companyId + '/account-plan/map-steps', { title: title });
            if (liveRegion()) liveRegion().textContent = t('Next step added.');
            await loadAccountPlan();
          } catch (e) {
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
        body.innerHTML = '<div class="crm-empty-state" role="status">' +
          '<h3 class="crm-empty-state__title">' + escapeHtml(t('No promises yet')) + '</h3>' +
          '<p class="crm-empty-state__text">' + escapeHtml(t('No customer promises yet.')) + '</p>' +
          (shellCan('write')
            ? '<div class="crm-empty-state__actions"><button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-promises-empty-add">' + escapeHtml(t('Add promise')) + '</button></div>'
            : '') +
          '</div>';
        const emptyAdd = document.getElementById('crm-promises-empty-add');
        const headerAdd = document.getElementById('crm-add-promise');
        if (emptyAdd && headerAdd) {
          emptyAdd.addEventListener('click', () => headerAdd.click());
        }
        return;
      }
      let html = '<div class="table-container crm-table--responsive"><table class="table table--hover"><thead><tr>';
      html += '<th scope="col">' + escapeHtml(t('Title')) + '</th><th scope="col">' + escapeHtml(t('Due')) + '</th><th scope="col">' + escapeHtml(t('Status')) + '</th><th scope="col">' + escapeHtml(t('Actions')) + '</th>';
      html += '</tr></thead><tbody>';
      items.forEach((p) => {
        html += '<tr data-promise-id="' + escapeHtml(String(p.id)) + '">';
        html += '<td data-label="' + escapeHtml(t('Title')) + '">' + escapeHtml(p.title || '') + '</td>';
        html += '<td data-label="' + escapeHtml(t('Due')) + '">' + escapeHtml(p.dueOn ? formatDisplayDate(p.dueOn) : '—') + '</td>';
        html += '<td data-label="' + escapeHtml(t('Status')) + '"><span class="crm-badge">' + escapeHtml(p.statusLabel || p.status || '') + '</span></td>';
        html += '<td data-label="' + escapeHtml(t('Actions')) + '">';
        if (shellCan('write') && (p.status || '') === 'open') {
          html += '<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-promise-done="' + escapeHtml(String(p.id)) + '">' + escapeHtml(t('Mark done')) + '</button> ';
          html += '<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-promise-cancel="' + escapeHtml(String(p.id)) + '">' + escapeHtml(t('Cancel')) + '</button>';
        } else {
          html += '—';
        }
        html += '</td></tr>';
      });
      html += '</tbody></table></div>';
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
      const today = new Date().toISOString().slice(0, 10);
      const data = await promptFields(t('Add promise'), [
        { name: 'title', label: t('Title'), required: true },
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

})();

  // SHARED-IDENTITY W1 — 360 create/link/unlink CTAs (MH-04/05 / NN-12)
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
