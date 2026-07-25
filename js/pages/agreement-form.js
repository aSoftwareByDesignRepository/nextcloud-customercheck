(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
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
      let res;
      if (form.dataset.mode === 'edit') {
        body.updatedAt = form.dataset.updatedAt || null;
        delete body.companyId;
        res = await Api.put('/agreements/' + form.dataset.id, body);
        window.location.href = OC.generateUrl('/apps/customercheck/agreements');
      } else {
        res = await Api.post('/agreements', body);
        const companyId = body.companyId;
        window.location.href = OC.generateUrl('/apps/customercheck/companies/' + companyId);
      }
    } catch (e) {
      errBox.hidden = false;
      errBox.textContent = (e.payload && e.payload.message) || e.message;
    }
  });
})();
