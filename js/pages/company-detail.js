(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  if (C && C.init) { C.init(); }

  const root = document.querySelector('.crm-page-stack[data-company-id]');
  const companyId = root ? parseInt(root.getAttribute('data-company-id'), 10) : 0;

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
        if (live) { live.textContent = 'Export downloaded.'; }
      } catch (e) {
        await C.alertDialog({ title: 'Export failed', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const eraseBtn = document.getElementById('crm-company-gdpr-erase');
  if (eraseBtn) {
    eraseBtn.addEventListener('click', async () => {
      const slug = eraseBtn.getAttribute('data-slug') || '';
      const result = await C.confirmDialog({
        title: 'Erase company (GDPR)',
        message: 'This permanently deletes personal data for this company. Type the slug to confirm: ' + slug,
        confirmLabel: 'Erase',
        cancelLabel: 'Cancel',
        variant: 'danger',
        requireTypedConfirm: true,
        typedConfirmPhrase: slug,
      });
      if (!result || !result.confirmed) return;
      try {
        await Api.del('/companies/' + companyId, { confirmSlug: slug });
        window.location.href = OC.generateUrl('/apps/customercheck/companies');
      } catch (e) {
        await C.alertDialog({ title: 'Erase failed', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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
          html += '<textarea class="crm-input form-input" id="' + id + '-' + f.name + '" name="' + f.name + '" rows="3">' +
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
      html += '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">Save</button> <button type="button" class="crm-btn crm-btn--secondary" data-cancel>Cancel</button></div></form>';
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
      const data = await promptFields('Add person', [
        { name: 'name', label: 'Name', required: true },
        { name: 'email', label: 'Email', type: 'email' },
        { name: 'phone', label: 'Phone' },
        { name: 'role', label: 'Role' },
      ]);
      if (!data) return;
      try {
        await Api.post('/companies/' + companyId + '/persons', Object.assign({ isPrimary: false }, data));
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: 'Could not add person', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const logEmail = document.getElementById('crm-log-email');
  if (logEmail) {
    logEmail.addEventListener('click', async () => {
      let persons = [];
      try {
        persons = JSON.parse(root.getAttribute('data-crm-persons') || '[]');
      } catch (e) {
        persons = [];
      }
      if (!Array.isArray(persons) || persons.length === 0) {
        await C.alertDialog({
          title: 'Add a person first',
          message: 'Add someone under People with an email — then you can log what you wrote with them in one step.',
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
      const data = await promptFields('Log email', [
        {
          name: 'personId',
          label: 'With whom',
          type: 'select',
          options: personOptions,
          value: personOptions[0].value,
          required: true,
        },
        {
          name: 'direction',
          label: 'Direction',
          type: 'select',
          value: 'outbound',
          options: [
            { value: 'outbound', label: 'You wrote them' },
            { value: 'inbound', label: 'They wrote you' },
          ],
        },
        { name: 'subject', label: 'Subject', required: true },
        { name: 'snippet', label: 'Short note (optional)', type: 'textarea' },
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
        await C.alertDialog({ title: 'Could not log email', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const addActivity = document.getElementById('crm-add-activity');
  if (addActivity) {
    addActivity.addEventListener('click', async () => {
      const today = new Date().toISOString().slice(0, 10);
      const data = await promptFields('Add activity', [
        { name: 'title', label: 'Title', required: true },
        {
          name: 'type',
          label: 'Type',
          type: 'select',
          value: 'todo',
          options: [
            { value: 'call', label: 'Call' },
            { value: 'email', label: 'Email follow-up' },
            { value: 'meeting', label: 'Meeting' },
            { value: 'todo', label: 'To-do' },
          ],
        },
        { name: 'dueDate', label: 'Due date', type: 'date', required: true, value: today },
      ]);
      if (!data) return;
      if (!data.dueDate) data.dueDate = today;
      try {
        await Api.post('/activities', Object.assign({ companyId }, data));
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: 'Could not add activity', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  const addDeal = document.getElementById('crm-add-deal');
  if (addDeal) {
    addDeal.addEventListener('click', async () => {
      const data = await promptFields('Add deal', [
        { name: 'title', label: 'Title', required: true },
        { name: 'valueEur', label: 'Value (EUR)', type: 'number' },
        { name: 'products', label: 'Products / SKUs' },
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
        await C.alertDialog({ title: 'Could not add deal', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  async function openLogHours(agreementId) {
    const today = new Date().toISOString().slice(0, 10);
    const data = await promptFields('Log hours', [
      { name: 'entryDate', label: 'Date', type: 'date', required: true, value: today },
      {
        name: 'minutes',
        label: 'Minutes',
        type: 'number',
        required: true,
        value: '60',
        hint: 'Steps of 15 minutes. Default is one hour.',
      },
      { name: 'topic', label: 'Topic', required: true },
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
      await C.alertDialog({ title: 'Could not log hours', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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
          title: 'Delivery pack',
          content: '<p><strong>' + (pack.subject || '') + '</strong></p><pre class="crm-pre">' +
            String(pack.bodyText || '').replace(/[<>&]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ch])) +
            '</pre><div class="crm-form-actions">' +
            '<a class="crm-btn crm-btn--primary" href="' + (pack.mailAppUrl || pack.mailtoUrl || '#') + '">Open mail</a> ' +
            '<button type="button" class="crm-btn crm-btn--secondary" id="crm-copy-delivery">Copy</button></div>',
          size: 'md',
        });
        const copyBtn = document.getElementById('crm-copy-delivery');
        if (copyBtn) {
          copyBtn.addEventListener('click', async () => {
            const text = (pack.subject || '') + '\n\n' + (pack.bodyText || '');
            try {
              await navigator.clipboard.writeText(text);
              const live = document.getElementById('crm-live-region');
              if (live) { live.textContent = 'Copied to clipboard.'; }
            } catch (e) {
              // §8.12 E1 — clipboard denied: show select-all text instead of failing silently
              await C.openDialog({
                id: 'crm-delivery-copy-fallback',
                title: 'Copy manually',
                content: '<p>Clipboard access was denied. Select all text below and copy it.</p>' +
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
        await C.alertDialog({ title: 'Delivery pack failed', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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
        const composeUrl = pack.mailAppUrl || pack.mailtoUrl || '';
        if (composeUrl) {
          window.open(composeUrl, '_blank', 'noopener,noreferrer');
        }
        if (pack.promptHours) {
          if (skipHoursConfirm) {
            await openLogHours(agreementId);
            return;
          }
          const log = await C.confirmDialog({
            title: 'Log hours?',
            message: 'Log hours for this Partner session?',
            confirmLabel: 'Log hours',
            cancelLabel: 'Not now',
            variant: 'info',
          });
          if (log && log.confirmed) {
            await openLogHours(agreementId);
          }
        }
      } catch (e) {
        await C.alertDialog({ title: 'Partner mail failed', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  document.querySelectorAll('[data-crm-dismiss-followups]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const dealId = btn.getAttribute('data-crm-dismiss-followups');
      const ok = await C.confirmDialog({
        title: 'Dismiss follow-ups?',
        message: 'Mark remaining auto follow-up activities for this deal as done?',
        confirmLabel: 'Dismiss',
        cancelLabel: 'Cancel',
        variant: 'warning',
      });
      if (!ok || !ok.confirmed) return;
      try {
        await Api.post('/deals/' + dealId + '/dismiss-followups', {});
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: 'Could not dismiss', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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
        if (live) live.textContent = 'Activity marked done.';
        location.reload();
      } catch (e) {
        btn.disabled = false;
        await C.alertDialog({ title: 'Update failed', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  document.querySelectorAll('[data-crm-push-todo]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await Api.post('/activities/' + btn.getAttribute('data-crm-push-todo') + '/push-todo', {});
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = 'Sent to Tasks.';
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: 'Send to Tasks failed', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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
        if (pack.mailAppUrl) window.open(pack.mailAppUrl, '_blank');
        else if (pack.mailtoUrl) window.location.href = pack.mailtoUrl;
      } catch (e) {
        await C.alertDialog({ title: 'Compose failed', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });
})();
