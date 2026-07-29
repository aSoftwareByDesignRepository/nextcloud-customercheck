(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg, args) => (window.t ? window.t('customercheck', msg, args || []) : msg);
  if (C && C.init) { C.init(); }

  const form = document.getElementById('crm-agreement-form');
  if (!form) return;
  const errBox = document.getElementById('crm-agreement-form-error');

  // Prefill from won-deal handoff (?companyId=&amountCents=&label=&products=)
  if (form.dataset.mode === 'create') {
    const params = new URLSearchParams(window.location.search);
    const companyId = params.get('companyId');
    const amountCents = params.get('amountCents');
    const label = params.get('label');
    if (companyId) {
      const sel = document.getElementById('crm-ag-company');
      if (sel) sel.value = companyId;
    }
    if (label) {
      const labelEl = document.getElementById('crm-ag-label');
      if (labelEl && !labelEl.value) labelEl.value = label;
    }
    if (amountCents) {
      const amountEl = document.getElementById('crm-ag-amount');
      if (amountEl && (!amountEl.value || amountEl.value === '0')) {
        amountEl.value = (parseInt(amountCents, 10) / 100).toFixed(2);
      }
    }
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    errBox.hidden = true;
    const amountEur = parseFloat(document.getElementById('crm-ag-amount').value || '0');
    const body = {
      companyId: parseInt(document.getElementById('crm-ag-company').value, 10),
      label: document.getElementById('crm-ag-label').value.trim(),
      kind: document.getElementById('crm-ag-kind').value,
      productCode: document.getElementById('crm-ag-product').value.trim(),
      startsAt: document.getElementById('crm-ag-starts').value || null,
      validUntil: document.getElementById('crm-ag-until').value || null,
      amountCents: Math.round((isNaN(amountEur) ? 0 : amountEur) * 100),
      invoiceStatus: document.getElementById('crm-ag-invoice-status').value,
      status: 'active',
      limits: { mobileSeats: 1, terminalDevices: 0 },
    };
    try {
      if (form.dataset.mode === 'edit') {
        body.updatedAt = form.dataset.updatedAt || null;
        delete body.companyId;
        await Api.put('/agreements/' + form.dataset.id, body);
        window.location.href = OC.generateUrl('/apps/customercheck/agreements');
      } else {
        await Api.post('/agreements', body);
        const companyId = body.companyId;
        window.location.href = OC.generateUrl('/apps/customercheck/companies/' + companyId);
      }
    } catch (e) {
      errBox.hidden = false;
      errBox.textContent = (e.payload && e.payload.message) || e.message;
    }
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

  const renewBtn = document.getElementById('crm-agreement-renew');
  if (renewBtn && C && Api) {
    renewBtn.addEventListener('click', async () => {
      const id = renewBtn.getAttribute('data-id');
      const updatedAt = renewBtn.getAttribute('data-updated-at') || '';
      const prevUntil = renewBtn.getAttribute('data-valid-until') || '';
      const amountCents = parseInt(renewBtn.getAttribute('data-amount-cents') || '0', 10) || 0;
      const label = renewBtn.getAttribute('data-label') || '';
      const suggested = addYears(prevUntil, 1);
      const dialogId = 'crm-renew-' + Date.now();
      C.openDialog({
        id: dialogId,
        title: t('Renew agreement'),
        content: '<form id="' + dialogId + '-form" class="crm-form">' +
          '<div class="form-group"><label class="form-label" for="' + dialogId + '-until">' + t('New valid until') + '</label>' +
          '<input class="crm-input form-input" id="' + dialogId + '-until" name="validUntil" type="date" value="' + suggested + '" required />' +
          '<p class="form-help">' + t('Creates a new agreement and marks this one superseded. Reminders re-arm for the new date.') + '</p></div>' +
          '<div class="form-group"><label class="form-label" for="' + dialogId + '-amount">' + t('Amount (EUR)') + '</label>' +
          '<input class="crm-input form-input" id="' + dialogId + '-amount" name="amountEur" type="number" min="0" step="0.01" value="' + (amountCents / 100).toFixed(2) + '" /></div>' +
          '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + t('Renew') + '</button> ' +
          '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + t('Cancel') + '</button></div></form>',
      });
      const dlgForm = document.getElementById(dialogId + '-form');
      const close = () => C.closeModal(document.getElementById(dialogId));
      dlgForm.querySelector('[data-cancel]').addEventListener('click', close);
      dlgForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const amountEur = parseFloat(dlgForm.elements.amountEur.value || '0');
        try {
          await Api.post('/agreements/' + id + '/supersede', {
            validUntil: dlgForm.elements.validUntil.value || null,
            amountCents: Math.round((Number.isFinite(amountEur) ? amountEur : 0) * 100),
            label: label || undefined,
            updatedAt: updatedAt || null,
          });
          close();
          const live = document.getElementById('crm-live-region');
          if (live) live.textContent = t('Agreement renewed.');
          location.reload();
        } catch (e) {
          await C.alertDialog({ title: t('Renew failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        }
      });
    });
  }

  const cancelBtn = document.getElementById('crm-agreement-cancel');
  if (cancelBtn && C && Api) {
    cancelBtn.addEventListener('click', async () => {
      const id = cancelBtn.getAttribute('data-id');
      const updatedAt = cancelBtn.getAttribute('data-updated-at') || '';
      const dialogId = 'crm-cancel-ag-' + Date.now();
      C.openDialog({
        id: dialogId,
        title: t('Cancel agreement'),
        content: '<form id="' + dialogId + '-form" class="crm-form">' +
          '<div class="form-group"><label class="form-label" for="' + dialogId + '-reason">' + t('Reason') + '</label>' +
          '<textarea class="crm-input form-input" id="' + dialogId + '-reason" name="reason" rows="3" required></textarea>' +
          '<p class="form-help">' + t('Required. Kept on the agreement record.') + '</p></div>' +
          '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--danger">' + t('Cancel agreement') + '</button> ' +
          '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + t('Keep agreement') + '</button></div></form>',
      });
      const dlgForm = document.getElementById(dialogId + '-form');
      const close = () => C.closeModal(document.getElementById(dialogId));
      dlgForm.querySelector('[data-cancel]').addEventListener('click', close);
      dlgForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        try {
          await Api.post('/agreements/' + id + '/cancel', {
            reason: dlgForm.elements.reason.value,
            updatedAt: updatedAt || null,
          });
          close();
          const live = document.getElementById('crm-live-region');
          if (live) live.textContent = t('Agreement cancelled.');
          const companyId = document.getElementById('crm-ag-company')?.value;
          window.location.href = companyId
            ? OC.generateUrl('/apps/customercheck/companies/' + companyId)
            : OC.generateUrl('/apps/customercheck/agreements');
        } catch (e) {
          await C.alertDialog({ title: t('Cancel failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        }
      });
    });
  }
})();
