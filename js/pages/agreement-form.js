(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
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

  const form = document.getElementById('crm-agreement-form');
  if (!form) return;
  const errBox = document.getElementById('crm-agreement-form-error');
  const saveBtn = form.querySelector('button[type="submit"]');
  const isTerminal = form.dataset.terminal === '1';

  function escapeHtml(str) {
    if (C && typeof C._escapeHtml === 'function') {
      return C._escapeHtml(str);
    }
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function todayIso(d) {
    const x = d instanceof Date ? d : new Date();
    const y = x.getFullYear();
    const m = String(x.getMonth() + 1).padStart(2, '0');
    const day = String(x.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function addYears(isoDate, years) {
    const y = Number(years);
    if (!Number.isFinite(y)) {
      return todayIso();
    }
    if (!isoDate) {
      const d = new Date();
      d.setFullYear(d.getFullYear() + y);
      return todayIso(d);
    }
    const parts = String(isoDate).split('-').map((n) => parseInt(n, 10));
    if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) {
      const d = new Date();
      d.setFullYear(d.getFullYear() + y);
      return todayIso(d);
    }
    const d = new Date(Date.UTC(parts[0] + y, parts[1] - 1, parts[2]));
    return d.getUTCFullYear()
      + '-' + String(d.getUTCMonth() + 1).padStart(2, '0')
      + '-' + String(d.getUTCDate()).padStart(2, '0');
  }

  function eurosToCents(amountEur) {
    const n = parseFloat(String(amountEur ?? '0'));
    return Math.round((Number.isFinite(n) ? n : 0) * 100);
  }

  /**
   * SECURITY: edit must never send limits — preserves partner hoursQuota / license seats.
   * Create omits limits so the server applies product defaults.
   */
  function buildAgreementSaveBody(input) {
    const mode = input.mode === 'edit' ? 'edit' : 'create';
    const kind = String(input.kind || 'license');
    let validUntil = input.validUntil || null;
    if (kind === 'onetime') {
      validUntil = null;
    }
    const body = {
      label: String(input.label || '').trim(),
      kind: kind,
      productCode: String(input.productCode || '').trim(),
      startsAt: input.startsAt || null,
      validUntil: validUntil,
      amountCents: Number.isFinite(input.amountCents) ? Math.round(input.amountCents) : 0,
      invoiceStatus: String(input.invoiceStatus || 'none'),
    };
    if (mode === 'edit') {
      if (input.updatedAt) {
        body.updatedAt = input.updatedAt;
      }
      return body;
    }
    body.companyId = Number.isFinite(input.companyId) ? input.companyId : 0;
    body.status = 'active';
    return body;
  }

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
        const cents = parseInt(amountCents, 10);
        if (Number.isFinite(cents)) {
          amountEl.value = (cents / 100).toFixed(2);
        }
      }
    }
    const startsEl = document.getElementById('crm-ag-starts');
    if (startsEl && !startsEl.value) {
      startsEl.value = todayIso();
    }
  }

  function companyIdFromForm() {
    const el = document.getElementById('crm-ag-company');
    if (!el) return 0;
    const n = parseInt(el.value, 10);
    return Number.isFinite(n) && n > 0 ? n : 0;
  }

  function goCompanyOrList(companyId) {
    window.location.href = companyId > 0
      ? OC.generateUrl('/apps/customercheck/companies/' + companyId)
      : OC.generateUrl('/apps/customercheck/agreements');
  }

  function setBusy(btn, busy) {
    if (!btn) return;
    btn.disabled = !!busy;
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  function showError(msg, focusEl) {
    if (!errBox) return;
    errBox.hidden = false;
    errBox.textContent = msg;
    if (focusEl && typeof focusEl.focus === 'function') {
      focusEl.focus();
    }
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (isTerminal) {
      showError(t('This agreement is closed. Create a new agreement instead of editing it.'));
      return;
    }
    if (errBox) errBox.hidden = true;
    if (saveBtn && saveBtn.disabled) return;

    const labelEl = document.getElementById('crm-ag-label');
    const label = labelEl ? labelEl.value.trim() : '';
    if (label === '') {
      showError(t('Enter a short label.'), labelEl);
      return;
    }
    const kind = document.getElementById('crm-ag-kind').value;
    const untilEl = document.getElementById('crm-ag-until');
    const validUntil = untilEl ? untilEl.value : '';
    if (kind !== 'onetime' && !validUntil) {
      showError(t('Licenses and Check Partner packages need an end date.'), untilEl);
      return;
    }

    const companyId = companyIdFromForm();
    const startsEl = document.getElementById('crm-ag-starts');
    let startsAt = startsEl ? startsEl.value : '';
    if (!startsAt && form.dataset.mode === 'create') {
      startsAt = todayIso();
      if (startsEl) startsEl.value = startsAt;
    }

    const body = buildAgreementSaveBody({
      mode: form.dataset.mode === 'edit' ? 'edit' : 'create',
      label: label,
      kind: kind,
      productCode: document.getElementById('crm-ag-product').value.trim(),
      startsAt: startsAt || null,
      validUntil: validUntil || null,
      amountCents: eurosToCents(document.getElementById('crm-ag-amount').value),
      invoiceStatus: document.getElementById('crm-ag-invoice-status').value,
      companyId: companyId,
      updatedAt: form.dataset.updatedAt || null,
    });

    setBusy(saveBtn, true);
    try {
      if (form.dataset.mode === 'edit') {
        await Api.put('/agreements/' + form.dataset.id, body);
        goCompanyOrList(companyId);
      } else {
        if (companyId < 1) {
          showError(t('Choose a company.'), document.getElementById('crm-ag-company'));
          setBusy(saveBtn, false);
          return;
        }
        await Api.post('/agreements', body);
        goCompanyOrList(companyId);
      }
    } catch (e) {
      showError((e.payload && e.payload.message) || e.message);
      setBusy(saveBtn, false);
    }
  });

  const renewBtn = document.getElementById('crm-agreement-renew');
  if (renewBtn && C && Api && !isTerminal) {
    renewBtn.addEventListener('click', async () => {
      if (renewBtn.disabled) return;
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
        content: '<form id="' + dialogId + '-form" class="crm-form crm-form--stack">' +
          '<div class="form-group"><label class="form-label" for="' + dialogId + '-until">' + escapeHtml(t('New valid until')) + '</label>' +
          '<input class="crm-input form-input" id="' + dialogId + '-until" name="validUntil" type="date" value="' + escapeHtml(suggested) + '" required />' +
          '<p class="form-help">' + escapeHtml(t('Creates a new agreement and marks this one superseded. Reminders re-arm for the new date.')) + '</p></div>' +
          '<div class="form-group"><label class="form-label" for="' + dialogId + '-amount">' + escapeHtml(t('Amount (EUR)')) + '</label>' +
          '<input class="crm-input form-input" id="' + dialogId + '-amount" name="amountEur" type="number" min="0" step="0.01" value="' + escapeHtml((amountCents / 100).toFixed(2)) + '" /></div>' +
          '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Renew')) + '</button> ' +
          '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button></div></form>',
      });
      const dlgForm = document.getElementById(dialogId + '-form');
      const close = () => C.closeModal(document.getElementById(dialogId));
      dlgForm.querySelector('[data-cancel]').addEventListener('click', close);
      dlgForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const submitBtn = dlgForm.querySelector('button[type="submit"]');
        if (submitBtn && submitBtn.disabled) return;
        setBusy(submitBtn, true);
        setBusy(renewBtn, true);
        try {
          await Api.post('/agreements/' + id + '/supersede', {
            validUntil: dlgForm.elements.validUntil.value || null,
            amountCents: eurosToCents(dlgForm.elements.amountEur.value),
            label: label || undefined,
            updatedAt: updatedAt || null,
          });
          close();
          const live = document.getElementById('crm-live-region');
          if (live) live.textContent = t('Agreement renewed.');
          location.reload();
        } catch (e) {
          setBusy(submitBtn, false);
          setBusy(renewBtn, false);
          await C.alertDialog({ title: t('Renew failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        }
      });
    });
  }

  const cancelBtn = document.getElementById('crm-agreement-cancel');
  if (cancelBtn && C && Api && !isTerminal) {
    cancelBtn.addEventListener('click', async () => {
      if (cancelBtn.disabled) return;
      const id = cancelBtn.getAttribute('data-id');
      const updatedAt = cancelBtn.getAttribute('data-updated-at') || '';
      const dialogId = 'crm-cancel-ag-' + Date.now();
      C.openDialog({
        id: dialogId,
        title: t('Cancel agreement'),
        content: '<form id="' + dialogId + '-form" class="crm-form crm-form--stack">' +
          '<div class="form-group"><label class="form-label" for="' + dialogId + '-reason">' + escapeHtml(t('Reason')) + '</label>' +
          '<textarea class="crm-input form-input" id="' + dialogId + '-reason" name="reason" rows="3" required></textarea>' +
          '<p class="form-help">' + escapeHtml(t('Required. Kept on the agreement record.')) + '</p></div>' +
          '<div class="crm-form-actions"><button type="submit" class="crm-btn crm-btn--danger">' + escapeHtml(t('Cancel agreement')) + '</button> ' +
          '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Keep agreement')) + '</button></div></form>',
      });
      const dlgForm = document.getElementById(dialogId + '-form');
      const close = () => C.closeModal(document.getElementById(dialogId));
      dlgForm.querySelector('[data-cancel]').addEventListener('click', close);
      dlgForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const submitBtn = dlgForm.querySelector('button[type="submit"]');
        if (submitBtn && submitBtn.disabled) return;
        setBusy(submitBtn, true);
        setBusy(cancelBtn, true);
        try {
          await Api.post('/agreements/' + id + '/cancel', {
            reason: dlgForm.elements.reason.value,
            updatedAt: updatedAt || null,
          });
          close();
          const live = document.getElementById('crm-live-region');
          if (live) live.textContent = t('Agreement cancelled.');
          goCompanyOrList(companyIdFromForm());
        } catch (e) {
          setBusy(submitBtn, false);
          setBusy(cancelBtn, false);
          await C.alertDialog({ title: t('Cancel failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        }
      });
    });
  }

  // Expose for contract / debug — not a public API.
  window.CustomerCheckAgreementForm = {
    todayIso: todayIso,
    addYears: addYears,
    eurosToCents: eurosToCents,
    buildAgreementSaveBody: buildAgreementSaveBody,
  };
})();
