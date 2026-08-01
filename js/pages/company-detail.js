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
        message: t('This permanently deletes personal data for this company. Type the slug to confirm: %s', [slug]),
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
      let companies = [];
      try {
        const res = await Api.get('/companies?limit=200');
        companies = (res.data || []).filter((c) => parseInt(c.id, 10) !== companyId && !c.archivedAt);
      } catch (e) {
        await C.alertDialog({ title: t('Could not load companies'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        return;
      }
      if (companies.length === 0) {
        await C.alertDialog({
          title: t('Nothing to merge into'),
          message: t('Create or restore another active company first, then merge this duplicate into it.'),
          variant: 'info',
        });
        return;
      }
      const options = companies.map((c) => ({
        value: String(c.id),
        label: (c.name || c.slug) + ' (' + (c.slug || c.id) + ')',
        updatedAt: c.updatedAt || '',
      }));
      const data = await promptFields(t('Merge into another company'), [
        {
          name: 'intoCompanyId',
          label: t('Keep this company'),
          type: 'select',
          options: options,
          value: options[0].value,
          required: true,
          hint: t('People, agreements, deals, activities and history move to the company you pick. This company is archived afterwards.'),
        },
      ]);
      if (!data) return;
      const intoId = parseInt(data.intoCompanyId, 10);
      const keeperOpt = options.find((o) => o.value === String(intoId)) || {};
      const keeperLabel = keeperOpt.label || String(intoId);
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
          intoUpdatedAt: keeperOpt.updatedAt || null,
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
  if (moveWsBtn && !moveWsBtn.disabled) {
    moveWsBtn.addEventListener('click', async () => {
      const slug = moveWsBtn.getAttribute('data-slug') || '';
      const fromWs = parseInt(moveWsBtn.getAttribute('data-workspace-id') || '0', 10);
      let workspaces = [];
      try {
        const res = await Api.get('/workspaces');
        const list = (res.data && res.data.workspaces) || res.data || [];
        workspaces = (Array.isArray(list) ? list : []).filter((w) => parseInt(w.id, 10) !== fromWs);
      } catch (e) {
        await C.alertDialog({
          title: t('Could not load workspaces'),
          message: (e.payload && e.payload.message) || e.message,
          variant: 'danger',
        });
        return;
      }
      if (workspaces.length === 0) {
        await C.alertDialog({
          title: t('No other workspace'),
          message: t('Create another workspace where you are a manager, then move this company there.'),
          variant: 'info',
        });
        return;
      }
      const options = workspaces.map((w) => ({
        value: String(w.id),
        label: w.name || ('#' + w.id),
      }));
      const data = await promptFields(t('Move to another workspace'), [
        {
          name: 'workspaceId',
          label: t('Destination workspace'),
          type: 'select',
          options: options,
          value: options[0].value,
          required: true,
          hint: t('Shared mail matches are cleared. Files folder sharing is not moved — review ACL manually.'),
        },
      ]);
      if (!data) return;
      const toWs = parseInt(data.workspaceId, 10);
      const destLabel = (options.find((o) => o.value === String(toWs)) || {}).label || String(toWs);
      const ok = await C.confirmDialog({
        title: t('Confirm move'),
        message: t('Move this company to “%s”? Type the slug to confirm. Files sharing stays as-is.', [destLabel]),
        confirmLabel: t('Move'),
        cancelLabel: t('Cancel'),
        variant: 'warning',
        requireTypedConfirm: true,
        typedConfirmPhrase: slug,
      });
      if (!ok || !ok.confirmed) return;
      moveWsBtn.disabled = true;
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Moving company…');
      try {
        const res = await Api.post('/companies/' + companyId + '/move-workspace', {
          workspaceId: toWs,
          confirmSlug: slug,
          updatedAt: moveWsBtn.getAttribute('data-updated-at') || null,
        });
        if (live) live.textContent = t('Company moved. Review Files sharing for this folder.');
        const newWs = (res.data && res.data.workspaceId) || toWs;
        window.location.href = OC.generateUrl('/apps/customercheck/companies/' + companyId + '?workspaceId=' + newWs);
      } catch (e) {
        moveWsBtn.disabled = false;
        if (live) live.textContent = '';
        await C.alertDialog({
          title: t('Move failed'),
          message: (e.payload && e.payload.message) || e.message,
          variant: 'danger',
        });
      }
    });
  }

  async function promptFields(title, fields) {
    // Lightweight modal form via openDialog
    return new Promise((resolve) => {
      const id = 'crm-prompt-' + Date.now();
      let html = '<form id="' + id + '-form" class="crm-form">';
      fields.forEach((f) => {
        html += '<div class="form-group"><label class="form-label" for="' + id + '-' + f.name + '">' + f.label + '</label>';
        if (f.type === 'textarea') {
          html += '<textarea class="crm-input form-input" id="' + id + '-' + f.name + '" name="' + f.name + '" rows="3"' + (f.required ? ' required' : '') + '>' +
            (f.value ? String(f.value).replace(/</g, '&lt;') : '') + '</textarea>';
        } else if (f.type === 'select') {
          html += '<select class="crm-input form-select" id="' + id + '-' + f.name + '" name="' + f.name + '">' +
            f.options.map((o) => {
              const value = typeof o === 'object' && o !== null ? String(o.value) : String(o);
              const label = typeof o === 'object' && o !== null ? String(o.label || o.value) : String(o);
              const selected = f.value !== undefined && String(f.value) === value ? ' selected' : '';
              return '<option value="' + value.replace(/"/g, '&quot;') + '"' + selected + '>' + label.replace(/</g, '&lt;') + '</option>';
            }).join('') + '</select>';
        } else {
          const val = f.value !== undefined && f.value !== null ? ' value="' + String(f.value).replace(/"/g, '&quot;') + '"' : '';
          html += '<input class="crm-input form-input" id="' + id + '-' + f.name + '" name="' + f.name + '" type="' + (f.type || 'text') + '"' + val + ' ' + (f.required ? 'required' : '') + ' />';
        }
        if (f.hint) {
          html += '<p class="form-help">' + String(f.hint).replace(/</g, '&lt;') + '</p>';
        }
        html += '</div>';
      });
      html += '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + t('Save') + '</button> <button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + t('Cancel') + '</button></div></form>';
      C.openDialog({ id: id, title: title, content: html, size: 'md' });
      const form = document.getElementById(id + '-form');
      const close = () => C.closeModal(document.getElementById(id));
      form.querySelector('[data-cancel]').addEventListener('click', () => { close(); resolve(null); });
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const data = {};
        fields.forEach((f) => { data[f.name] = form.elements[f.name].value; });
        close();
        resolve(data);
      });
    });
  }

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
          contactsHint = t('Tip: start typing a name — matches from Contacts appear below.');
        }
      } catch (e) {
        contactsHint = '';
      }
      const data = await promptFields(t('Add person'), [
        { name: 'name', label: t('Name'), required: true, hint: contactsHint },
        { name: 'email', label: t('Email'), type: 'email' },
        { name: 'phone', label: t('Phone') },
        { name: 'role', label: t('Role') },
        {
          name: 'buyingRole',
          label: t('Buying role'),
          type: 'select',
          value: '',
          options: buyingRoleOptionsFromDom(),
        },
      ]);
      if (!data) return;
      let contactsUid = null;
      // Optional Contacts fill-in when the name looks like a search
      if (contactsAvailable && data.name && data.name.length >= 2) {
        try {
          const resPayload = await Api.get('/contacts/suggest?q=' + encodeURIComponent(data.name) + '&limit=5');
          const res = (resPayload && resPayload.data) ? resPayload.data : resPayload;
          const items = (res && Array.isArray(res.items) && res.items.length)
            ? res.items
            : (res && Array.isArray(res.suggestions) ? res.suggestions : []);
          const first = items[0] || null;
          if (first && first.name) {
            const use = await C.confirmDialog({
              title: t('Use Contacts match?'),
              message: first.name + (first.email ? ' <' + first.email + '>' : ''),
              confirmLabel: t('Use this contact'),
              cancelLabel: t('Keep what I typed'),
            });
            if (use) {
              data.name = first.name || data.name;
              data.email = first.email || data.email || '';
              data.phone = first.phone || data.phone || '';
              if (first.uid) {
                contactsUid = String(first.uid);
              }
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
    if (!Array.isArray(persons) || persons.length === 0) {
      await C.alertDialog({
        title: t('Add a person first'),
        message: t('Add someone under People with an email — then you can log what you wrote with them in one step.'),
        variant: 'info',
      });
      const peopleJump = document.querySelector('.crm-jump-nav__link[href="#crm-persons"]');
      if (peopleJump) peopleJump.click();
      return;
    }
    const personOptions = persons.map((p) => ({
      value: String(p.id),
      label: (p.name || p.email) + ' <' + p.email + '>',
    }));
    const data = await promptFields(t('Log email'), [
      {
        name: 'personId',
        label: t('With whom'),
        type: 'select',
        options: personOptions,
        value: personOptions[0].value,
        required: true,
      },
      {
        name: 'direction',
        label: t('Direction'),
        type: 'select',
        value: 'outbound',
        options: [
          { value: 'outbound', label: t('You wrote them') },
          { value: 'inbound', label: t('They wrote you') },
        ],
      },
      { name: 'subject', label: t('Subject'), required: true },
      { name: 'snippet', label: t('Short note (optional)'), type: 'textarea' },
    ]);
    if (!data) return;
    try {
      await Api.post('/companies/' + companyId + '/emails', {
        personId: parseInt(data.personId, 10),
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
        if (liveRegion()) liveRegion().textContent = t('Buying role updated.');
      } catch (e) {
        await C.alertDialog({ title: t('Could not update buying role'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        location.reload();
      } finally {
        sel.disabled = false;
      }
    });
  });

  function billingStateLabel(state) {
    switch (state) {
      case 'ok': return t('Paid up');
      case 'attention': return t('Needs attention');
      case 'critical': return t('Overdue');
      default: return t('Unknown');
    }
  }

  function coverageMissingLabel(slot) {
    switch (slot) {
      case 'economic_buyer': return t('Budget decision-maker');
      case 'champion': return t('Champion');
      case 'day_to_day_or_technical': return t('Day-to-day or technical contact');
      default: return slot ? String(slot) : '';
    }
  }

  const addActivity = document.getElementById('crm-add-activity');
  if (addActivity) {
    addActivity.addEventListener('click', async () => {
      const today = new Date().toISOString().slice(0, 10);
      const data = await promptFields(t('Add activity'), [
        { name: 'title', label: t('Title'), required: true },
        {
          name: 'type',
          label: t('Type'),
          type: 'select',
          value: 'todo',
          options: [
            { value: 'call', label: t('Call') },
            { value: 'email', label: t('Email follow-up') },
            { value: 'meeting', label: t('Meeting') },
            { value: 'todo', label: t('To-do') },
          ],
        },
        { name: 'dueDate', label: t('Due date'), type: 'date', required: true, value: today },
      ]);
      if (!data) return;
      if (!data.dueDate) data.dueDate = today;
      try {
        await Api.post('/activities', Object.assign({ companyId }, data));
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not add activity'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const addDeal = document.getElementById('crm-add-deal');
  if (addDeal) {
    addDeal.addEventListener('click', async () => {
      const data = await promptFields(t('Add deal'), [
        { name: 'title', label: t('Title'), required: true },
        { name: 'valueEur', label: t('Value (EUR)'), type: 'number' },
        { name: 'products', label: t('Products / SKUs') },
      ]);
      if (!data) return;
      const valueCents = Math.round(parseFloat(data.valueEur || '0') * 100) || 0;
      try {
        await Api.post('/deals', {
          companyId,
          title: data.title,
          stage: 'lead',
          valueCents,
          products: data.products || '',
        });
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not add deal'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

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
        await C.openDialog({
          id: 'crm-delivery-pack',
          title: t('Delivery pack'),
          content: '<p><strong>' + (pack.subject || '') + '</strong></p><pre class="crm-pre">' +
            String(pack.bodyText || '').replace(/[<>&]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ch])) +
            '</pre><div class="crm-form-actions">' +
            '<a class="crm-btn crm-btn--primary" href="' + (pack.mailAppUrl || pack.mailtoUrl || '#') + '">' + t('Open mail') + '</a> ' +
            '<button type="button" class="crm-btn crm-btn--secondary" id="crm-send-delivery">' + t('Send from shared mailbox') + '</button> ' +
            '<button type="button" class="crm-btn crm-btn--secondary" id="crm-copy-delivery">' + t('Copy') + '</button></div>',
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
                content: '<p>' + t('Clipboard access was denied. Select all text below and copy it.') + '</p>' +
                  '<textarea class="crm-input form-textarea" id="crm-delivery-fallback-text" rows="12" readonly>' +
                  String(text).replace(/[<>&]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ch])) +
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
        title: t('Dismiss follow-ups?'),
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

  async function renewAgreementFromRow(row) {
    const id = row.getAttribute('data-agreement-id') || row.getAttribute('data-id');
    const updatedAt = row.getAttribute('data-updated-at') || '';
    const prevUntil = row.getAttribute('data-valid-until') || '';
    const amountCents = parseInt(row.getAttribute('data-amount-cents') || '0', 10) || 0;
    const label = row.getAttribute('data-label') || '';
    const suggestedUntil = addYears(prevUntil, 1);
    const data = await promptFields(t('Renew agreement'), [
      {
        name: 'validUntil',
        label: t('New valid until'),
        type: 'date',
        value: suggestedUntil,
        required: true,
        hint: t('Creates a new agreement and marks this one superseded. Reminders re-arm for the new date.'),
      },
      {
        name: 'amountEur',
        label: t('Amount (EUR)'),
        type: 'number',
        value: (amountCents / 100).toFixed(2),
      },
      {
        name: 'label',
        label: t('Label'),
        value: label,
      },
    ]);
    if (!data) return;
    const amountEur = parseFloat(data.amountEur || '0');
    try {
      await Api.post('/agreements/' + id + '/supersede', {
        validUntil: data.validUntil || null,
        amountCents: Math.round((Number.isFinite(amountEur) ? amountEur : 0) * 100),
        label: (data.label || label || '').trim() || undefined,
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
    const data = await promptFields(t('Cancel agreement'), [
      {
        name: 'reason',
        label: t('Reason'),
        type: 'textarea',
        required: true,
        hint: t('Required. Kept on the agreement record.'),
      },
    ]);
    if (!data) return;
    const ok = await C.confirmDialog({
      title: t('Cancel this agreement?'),
      message: t('The agreement becomes cancelled and stops receiving renewal reminders.'),
      confirmLabel: t('Cancel agreement'),
      cancelLabel: t('Keep agreement'),
      variant: 'danger',
    });
    if (!ok || !ok.confirmed) return;
    try {
      await Api.post('/agreements/' + id + '/cancel', {
        reason: data.reason,
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
  async function loadCommandCenter() {
    if (!companyId || !Api) return;
    const live = document.getElementById('crm-live-region');
    try {
      const [cmd, feed, tasks, billingLinks, platform] = await Promise.all([
        Api.get('/companies/' + companyId + '/command'),
        Api.get('/companies/' + companyId + '/communication?limit=25'),
        Api.get('/companies/' + companyId + '/tasks'),
        Api.get('/companies/' + companyId + '/files-folder/children').catch(() => ({ data: { items: [], broken: false } })),
        Api.get('/companies/' + companyId + '/platform-links').catch(() => ({ data: {} })),
      ]);
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
        const next = dto.nextAction || {};
        const last = dto.lastDiscussion || {};
        const runway = dto.runway || {};
        const billing = dto.billing || {};
        const primary = dto.primaryPerson;
        const cov = dto.coverage || {};
        const nextTouch = dto.nextTouchOn || '';
        const dl = document.createElement('dl');
        dl.className = 'crm-glance-dl';
        const coverageLabel = cov.gap ? t('Gap') : (cov.covered ? t('Covered') : t('Coverage'));
        const coverageBadge = cov.gap ? 'warning' : (cov.covered ? 'success' : 'neutral');
        const missingText = (cov.missingLabels && cov.missingLabels.length)
          ? cov.missingLabels.join(', ')
          : ((cov.missing || []).map(coverageMissingLabel).filter(Boolean).join(', '));
        const runwayText = runway.validUntil
          ? (runway.validUntil + (runway.daysLeft != null ? ' · ' + t('%s days left', [String(runway.daysLeft)]) : ''))
          : '—';
        const rows = [
          [t('Company'), (dto.name || '') + ' · ' + (dto.slug || '')],
          [t('Primary contact'), primary ? ((primary.name || '') + (primary.role ? ' · ' + primary.role : '')) : t('No primary person')],
          [t('Coverage'), coverageLabel + (missingText ? ' · ' + missingText : '')],
          [t('Next touch'), nextTouch || t('Not scheduled')],
          [t('Last discussion'), last.summary ? ((last.at || '') + ' · ' + last.summary) : t('Nothing logged yet')],
          [t('Next action'), next.label || t('No urgent action')],
          [t('Money'), billingStateLabel(billing.state || 'unknown') + (billing.overdueCount ? ' · ' + billing.overdueCount + ' ' + t('overdue') : '')],
          [t('Renewal'), runwayText],
        ];
        rows.forEach(([dt, dd]) => {
          const dte = document.createElement('dt');
          dte.textContent = dt;
          const dde = document.createElement('dd');
          if (dt === t('Coverage')) {
            const badge = document.createElement('span');
            badge.className = 'crm-badge crm-badge--' + coverageBadge;
            badge.textContent = coverageLabel;
            dde.appendChild(badge);
            if (missingText) {
              dde.appendChild(document.createTextNode(' · ' + missingText));
            }
          } else {
            dde.textContent = dd;
          }
          dl.appendChild(dte);
          dl.appendChild(dde);
        });
        body.innerHTML = '';
        body.appendChild(dl);
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
          feedEl.innerHTML = '<div class="crm-empty-state" role="status"><p class="crm-empty-state__text">' + t('Nothing logged yet. Log an email or add an activity.') + '</p></div>';
        } else {
          feedEl.innerHTML = '';
          items.forEach((item) => {
            const row = document.createElement('article');
            row.className = 'crm-feed__item';
            const badge = document.createElement('span');
            badge.className = 'crm-badge';
            badge.textContent = feedKindLabel(item.kind);
            row.appendChild(badge);
            if (item.shared || item.kind === 'shared_mail') {
              const sharedBadge = document.createElement('span');
              sharedBadge.className = 'crm-badge crm-badge--warning';
              sharedBadge.textContent = t('Shared');
              row.appendChild(document.createTextNode(' '));
              row.appendChild(sharedBadge);
            }
            if (item.private || item.kind === 'mail_related') {
              const privBadge = document.createElement('span');
              privBadge.className = 'crm-badge crm-badge--neutral';
              privBadge.textContent = item.privacyBadge || t('Private to you');
              row.appendChild(document.createTextNode(' '));
              row.appendChild(privBadge);
            }
            const strong = document.createElement('strong');
            strong.textContent = item.subject || '';
            const meta = document.createElement('span');
            meta.className = 'crm-muted';
            meta.textContent = ' · ' + (item.occurredAt || '') + (item.personName ? ' · ' + item.personName : '');
            row.appendChild(document.createTextNode(' '));
            row.appendChild(strong);
            row.appendChild(meta);
            feedEl.appendChild(row);
          });
        }
      }

      const tasksBody = document.getElementById('crm-open-tasks-body');
      if (tasksBody) {
        const items = (tasks.data && tasks.data.items) || tasks.items || [];
        if (!items.length) {
          tasksBody.innerHTML = '<div class="crm-empty-state" role="status"><p class="crm-empty-state__text">' + t('Nothing open for this company.') + '</p></div>';
        } else {
          const table = document.createElement('div');
          table.className = 'table-container crm-table--responsive';
          let html = '<table class="table table--hover"><thead><tr><th>' + t('Title') + '</th><th>' + t('Due') + '</th><th>' + t('Tasks') + '</th></tr></thead><tbody>';
          items.forEach((row) => {
            const tasksCell = row.inTasks
              ? ('<a class="crm-btn crm-btn--sm crm-btn--secondary" href="' + (row.tasksDeepLink || '/apps/tasks/') + '">' + t('Open in Tasks') + '</a>')
              : '—';
            html += '<tr><td>' + (row.title || '') + (row.isCallback ? ' <span class="crm-badge">' + t('Callback') + '</span>' : '') + '</td><td>' + (row.dueDate || '—') + '</td><td>' + tasksCell + '</td></tr>';
          });
          html += '</tbody></table>';
          table.innerHTML = html;
          tasksBody.innerHTML = '';
          tasksBody.appendChild(table);
        }
      }

      const billingBody = document.getElementById('crm-billing-body');
      if (billingBody && dto && dto.billing) {
        const b = dto.billing;
        const badge = b.state === 'critical' ? 'danger' : (b.state === 'attention' ? 'warning' : (b.state === 'ok' ? 'success' : 'neutral'));
        billingBody.innerHTML = '<p><span class="crm-badge crm-badge--' + badge + '">' + billingStateLabel(b.state || 'unknown') + '</span> · ' + t('Overdue') + ': ' + (b.overdueCount || 0) + ' · ' + t('Open') + ': ' + (b.openCount || 0) + '</p>';
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
          docsBody.innerHTML = '<div class="crm-empty-state" role="status"><p class="crm-empty-state__text">' + t('No document folder linked yet. Browse your Nextcloud folders and choose one (contracts, offers, notes) so everyone finds customer files in one click.') + '</p></div>';
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
      if (live) live.textContent = t('Some overview data could not be loaded.');
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
        { name: 'title', label: t('Title'), type: 'text', required: true },
        { name: 'type', label: t('Type'), type: 'select', required: true, options: [
          { value: 'todo', label: t('To-do') },
          { value: 'call', label: t('Call') },
          { value: 'email', label: t('Email') },
          { value: 'meeting', label: t('Meeting') },
        ], value: 'todo' },
        { name: 'dueDate', label: t('Due date'), type: 'date', required: true, value: ymd },
        { name: 'pushToTasks', label: t('Also send to Tasks'), type: 'checkbox', value: true },
      ]);
      if (!data) return;
      try {
        await Api.post('/companies/' + companyId + '/tasks', {
          title: data.title,
          type: data.type,
          dueDate: data.dueDate,
          pushToTasks: !!data.pushToTasks,
        });
        await loadCommandCenter();
      } catch (e) {
        await C.alertDialog({ title: t('Could not create task'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  loadCommandCenter();
  loadAccountPlan();
  loadPromises();

  async function loadAccountPlan() {
    const body = document.getElementById('crm-account-plan-body');
    if (!body || !companyId) return;
    const canWrite = shellCan('write');
    const priorityLabel = function (code) {
      if (code === 'p1') return t('High');
      if (code === 'p3') return t('Low');
      return t('Normal');
    };
    try {
      const res = await Api.get('/companies/' + companyId + '/account-plan');
      const plan = res.data || res || {};
      const mapSteps = plan.mapSteps || [];
      let html = '';
      if (!canWrite) {
        html += '<dl class="crm-dl">';
        html += '<div><dt>' + escapeHtml(t('Goal')) + '</dt><dd>' + escapeHtml(plan.goal || '—') + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Priority')) + '</dt><dd>' + escapeHtml(priorityLabel(plan.priority || 'p2')) + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Plan owner')) + '</dt><dd>' + escapeHtml(plan.ownerUserId || '—') + '</dd></div>';
        html += '<div><dt>' + escapeHtml(t('Next review')) + '</dt><dd>' + escapeHtml(plan.nextReviewOn || '—') + '</dd></div>';
        if (plan.health) {
          html += '<div><dt>' + escapeHtml(t('Health')) + '</dt><dd><span class="crm-badge">' + escapeHtml(plan.health) + '</span></dd></div>';
        }
        html += '</dl>';
        if (mapSteps.length) {
          html += '<h3 class="crm-card__title">' + escapeHtml(t('Next steps')) + '</h3><ul class="crm-simple-list">';
          mapSteps.forEach((s) => {
            html += '<li>' + escapeHtml(s.title || '') + ' · <span class="crm-badge">' + escapeHtml(s.status || '') + '</span></li>';
          });
          html += '</ul>';
        } else {
          html += '<p class="crm-muted">' + escapeHtml(t('No next steps yet.')) + '</p>';
        }
        body.innerHTML = html;
        return;
      }
      html = '<form id="crm-account-plan-form" class="crm-form">';
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
      if (plan.health) {
        html += '<p role="status"><span class="crm-badge">' + escapeHtml(plan.health) + '</span></p>';
      }
      html += '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save plan')) + '</button></div></form>';
      if (mapSteps.length) {
        html += '<h3 class="crm-card__title">' + escapeHtml(t('Next steps')) + '</h3><ul class="crm-simple-list">';
        mapSteps.forEach((s) => {
          html += '<li>' + escapeHtml(s.title || '') + ' · <span class="crm-badge">' + escapeHtml(s.status || '') + '</span></li>';
        });
        html += '</ul>';
      } else {
        html += '<p class="crm-muted">' + escapeHtml(t('No next steps yet.')) + '</p>';
      }
      html += '<form id="crm-map-step-form" class="crm-form crm-form--inline" aria-label="' + escapeHtml(t('Add next step')) + '">';
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
      body.innerHTML = '<div class="crm-callout crm-callout--danger" role="alert"><p>' + escapeHtml(t('Could not load account plan.')) + '</p></div>';
    }
  }

  async function loadPromises() {
    const body = document.getElementById('crm-promises-body');
    if (!body || !companyId) return;
    try {
      const res = await Api.get('/companies/' + companyId + '/promises');
      const items = (res.data && res.data.items) || res.items || [];
      if (!items.length) {
        body.innerHTML = '<div class="crm-empty-state" role="status"><p class="crm-empty-state__text">' + escapeHtml(t('No customer promises yet.')) + '</p></div>';
        return;
      }
      let html = '<div class="table-container crm-table--responsive"><table class="table table--hover"><thead><tr>';
      html += '<th scope="col">' + escapeHtml(t('Title')) + '</th><th scope="col">' + escapeHtml(t('Due')) + '</th><th scope="col">' + escapeHtml(t('Status')) + '</th><th scope="col">' + escapeHtml(t('Actions')) + '</th>';
      html += '</tr></thead><tbody>';
      items.forEach((p) => {
        html += '<tr data-promise-id="' + escapeHtml(String(p.id)) + '">';
        html += '<td data-label="' + escapeHtml(t('Title')) + '">' + escapeHtml(p.title || '') + '</td>';
        html += '<td data-label="' + escapeHtml(t('Due')) + '">' + escapeHtml(p.dueOn || '—') + '</td>';
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
          const data = await promptFields(t('Mark promise done'), [
            { name: 'evidence', label: t('Evidence (what was delivered?)'), type: 'textarea', required: true },
          ]);
          if (data === null) return;
          try {
            await Api.post('/companies/' + companyId + '/promises/' + id + '/done', { evidence: data.evidence || '' });
            if (liveRegion()) liveRegion().textContent = t('Promise marked done.');
            await loadPromises();
          } catch (e) {
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
      body.innerHTML = '<div class="crm-callout crm-callout--danger" role="alert"><p>' + escapeHtml(t('Could not load promises.')) + '</p></div>';
    }
  }

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
