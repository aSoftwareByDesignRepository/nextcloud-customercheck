(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const t = (msg) => (window.t ? window.t('customercheck', msg) : msg);
  if (C && C.init) { C.init(); }

  const toggle = document.getElementById('crm-agreements-filter-toggle');
  const panel = document.getElementById('crm-agreements-filter');
  if (toggle && panel) {
    toggle.addEventListener('click', () => {
      const open = panel.hasAttribute('hidden');
      if (open) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  const Api = window.CustomerCheckApi;
  const SharedMail = window.CustomerCheckSharedMail || null;

  async function openLogHours(agreementId) {
    if (!C || !Api) return;
    const id = 'crm-hours-' + Date.now();
    const today = new Date().toISOString().slice(0, 10);
    C.openDialog({
      id: id,
      title: t('Log hours'),
      content: '<form id="' + id + '-form" class="crm-form crm-form--stack">' +
        '<div class="form-group"><label class="form-label" for="' + id + '-date">' + t('Date') + '</label><input class="crm-input form-input" id="' + id + '-date" name="entryDate" type="date" value="' + today + '" required /></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-minutes">' + t('Minutes') + '</label><input class="crm-input form-input" id="' + id + '-minutes" name="minutes" type="number" min="15" step="15" value="60" required /><p class="form-help">' + t('Steps of 15. Default is one hour.') + '</p></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-topic">' + t('Topic') + '</label><input class="crm-input form-input" id="' + id + '-topic" name="topic" required autofocus /></div>' +
        '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + t('Save') + '</button> <button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + t('Cancel') + '</button></div></form>',
    });
    const form = document.getElementById(id + '-form');
    const close = () => C.closeModal(document.getElementById(id));
    form.querySelector('[data-cancel]').addEventListener('click', close);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      let minutes = parseInt(form.elements.minutes.value, 10);
      if (!Number.isFinite(minutes) || minutes < 15) minutes = 15;
      minutes = Math.round(minutes / 15) * 15;
      try {
        await Api.post('/agreements/' + agreementId + '/hours', {
          entryDate: form.elements.entryDate.value,
          minutes: minutes,
          topic: form.elements.topic.value,
        });
        close();
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not log hours'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  document.querySelectorAll('[data-crm-partner-session], [data-crm-partner-mail]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const agreementId = btn.getAttribute('data-crm-partner-session') || btn.getAttribute('data-crm-partner-mail');
      const skipConfirm = btn.hasAttribute('data-crm-partner-session');
      try {
        const res = await Api.post('/agreements/' + agreementId + '/partner-mail', { promptHours: true });
        const data = res.data || res;
        const companyId = parseInt(String(btn.getAttribute('data-company-id') || '0'), 10);
        if (SharedMail) {
          await SharedMail.offerPreparedMail(data, { companyId: companyId, title: t('Partner mail') });
        } else if (data.mailAppUrl) {
          window.open(data.mailAppUrl, '_blank');
        } else if (data.mailtoUrl) {
          window.location.href = data.mailtoUrl;
        }
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Partner mail prepared.');
        if (data.promptHours) {
          if (skipConfirm) {
            await openLogHours(agreementId);
          } else if (C) {
            const log = await C.confirmDialog({
              title: t('Log hours?'),
              message: t('Log hours for this Partner session?'),
              confirmLabel: t('Log hours'),
              cancelLabel: t('Not now'),
              variant: 'info',
            });
            if (log && log.confirmed) await openLogHours(agreementId);
          }
        }
      } catch (e) {
        if (C) await C.alertDialog({ title: t('Partner mail'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  document.querySelectorAll('[data-crm-log-hours]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      await openLogHours(btn.getAttribute('data-crm-log-hours'));
    });
  });

  document.querySelectorAll('[data-crm-delivery-pack]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const res = await Api.post('/agreements/' + btn.getAttribute('data-crm-delivery-pack') + '/delivery-pack', {});
        const pack = res.data || {};
        if (!C) return;
        const companyId = parseInt(String(btn.getAttribute('data-company-id') || '0'), 10);
        if (SharedMail) {
          await SharedMail.offerPreparedMail(pack, { companyId: companyId, title: t('Delivery pack') });
          return;
        }
        await C.openDialog({
          id: 'crm-delivery-pack',
          title: t('Delivery pack'),
          content: (typeof C.buildMailPackDialogHtml === 'function')
            ? C.buildMailPackDialogHtml(pack, { t: t })
            : ('<p><strong>' + (C._escapeHtml ? C._escapeHtml(pack.subject || '') : '') + '</strong></p>'),
          size: 'md',
        });
      } catch (e) {
        if (C) await C.alertDialog({ title: t('Delivery pack failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });
})();
