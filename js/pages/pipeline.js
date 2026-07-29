(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg, args) => (window.t ? window.t('customercheck', msg, args || []) : msg);
  if (C && C.init) { C.init(); }

  function parseJsonScript(id) {
    const el = document.getElementById(id);
    if (!el) return [];
    try {
      const data = JSON.parse(el.textContent || '[]');
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  function companyOptions() {
    return parseJsonScript('crm-pipeline-companies');
  }

  function forecastOptions() {
    return parseJsonScript('crm-pipeline-forecast-options');
  }

  function lossOptions() {
    return parseJsonScript('crm-pipeline-loss-options');
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[<>&"]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[ch]));
  }

  const filterToggle = document.getElementById('crm-pipeline-filter-toggle');
  const filterPanel = document.getElementById('crm-pipeline-filter');
  if (filterToggle && filterPanel) {
    filterToggle.addEventListener('click', () => {
      const open = filterPanel.hasAttribute('hidden');
      if (open) filterPanel.removeAttribute('hidden');
      else filterPanel.setAttribute('hidden', '');
      filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-crm-pipeline-view]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const view = btn.getAttribute('data-crm-pipeline-view');
      try {
        await Api.put('/settings/me', { pipelineView: view });
        location.reload();
      } catch (e) {
        if (C) await C.alertDialog({ title: t('Error'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  async function offerCreateAgreement(dealMeta) {
    if (!C) return;
    const result = await C.confirmDialog({
      title: t('Create agreement?'),
      message: t('This deal is won. Create a draft agreement pre-filled with company, value and products?'),
      confirmLabel: t('Create agreement'),
      cancelLabel: t('Not now'),
      variant: 'info',
    });
    if (!result || !result.confirmed) return;
    const params = new URLSearchParams({
      companyId: String(dealMeta.companyId || ''),
      amountCents: String(dealMeta.valueCents || 0),
      products: dealMeta.products || '',
      label: dealMeta.title || '',
      fromDeal: String(dealMeta.id || ''),
    });
    window.location.href = OC.generateUrl('/apps/customercheck/agreements/new') + '?' + params.toString();
  }

  function dealMetaFromControl(el) {
    const row = el.closest('[data-deal-id]') || el.closest('tr');
    const card = el.closest('.crm-kanban__card');
    const host = card || row;
    if (!host) return { id: el.getAttribute('data-crm-deal-stage') };
    return {
      id: host.getAttribute('data-deal-id') || el.getAttribute('data-crm-deal-stage'),
      companyId: host.getAttribute('data-company-id'),
      valueCents: host.getAttribute('data-value-cents'),
      products: host.getAttribute('data-products') || '',
      title: host.getAttribute('data-title') || (host.querySelector('strong') ? host.querySelector('strong').textContent : ''),
      ownerUserId: host.getAttribute('data-owner-user-id') || '',
      quoteSentOn: host.getAttribute('data-quote-sent-on') || '',
      forecastBucket: host.getAttribute('data-forecast-bucket') || '',
      updatedAt: host.getAttribute('data-updated-at') || '',
    };
  }

  function promptLostReason() {
    return new Promise((resolve) => {
      const id = 'crm-lost-' + Date.now();
      const options = lossOptions();
      const optsHtml = options.map((o) =>
        '<option value="' + escapeHtml(o.value) + '">' + escapeHtml(o.label || o.value) + '</option>'
      ).join('');
      const html =
        '<form id="' + id + '-form" class="crm-form">' +
        '<div class="form-group"><label class="form-label" for="' + id + '-code">' + escapeHtml(t('Loss reason')) + '</label>' +
        '<select class="crm-input form-select" id="' + id + '-code" name="lostReasonCode" required>' +
        '<option value="">' + escapeHtml(t('Pick a reason')) + '</option>' + optsHtml +
        '</select></div>' +
        '<div class="form-group" id="' + id + '-detail-wrap" hidden>' +
        '<label class="form-label" for="' + id + '-detail">' + escapeHtml(t('Detail')) + '</label>' +
        '<textarea class="crm-input form-input" id="' + id + '-detail" name="lostReason" rows="3"></textarea>' +
        '<p class="form-help">' + escapeHtml(t('Required when the reason is Other.')) + '</p></div>' +
        '<div class="crm-form-actions">' +
        '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save')) + '</button> ' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button></div></form>';
      C.openDialog({ id: id, title: t('Mark as lost'), content: html, size: 'md' });
      const form = document.getElementById(id + '-form');
      const codeSel = form.elements.lostReasonCode;
      const detailWrap = document.getElementById(id + '-detail-wrap');
      const detail = form.elements.lostReason;
      const close = () => C.closeModal(document.getElementById(id));
      const syncDetail = () => {
        const isOther = codeSel.value === 'other';
        detailWrap.hidden = !isOther;
        detail.required = isOther;
      };
      codeSel.addEventListener('change', syncDetail);
      form.querySelector('[data-cancel]').addEventListener('click', () => { close(); resolve(null); });
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const code = String(codeSel.value || '').trim();
        if (!code) return;
        const reason = String(detail.value || '').trim();
        if (code === 'other' && reason.length < 3) {
          detail.focus();
          return;
        }
        close();
        resolve({ lostReasonCode: code, lostReason: reason });
      });
    });
  }

  async function moveDeal(id, stage, updatedAt, meta) {
    let extra = {};
    if (stage === 'lost') {
      const lost = await promptLostReason();
      if (!lost) {
        location.reload();
        return;
      }
      extra = lost;
    }
    try {
      await Api.post('/deals/' + id + '/stage', Object.assign({
        stage,
        updatedAt,
        lostReason: extra.lostReason || null,
        lostReasonCode: extra.lostReasonCode || null,
      }, extra));
      const live = document.getElementById('crm-live-region');
      if (live) {
        live.textContent = t('Deal moved to %s.', [stage]);
      }
      if (stage === 'won') {
        await offerCreateAgreement(Object.assign({ id }, meta || {}));
      }
      location.reload();
    } catch (e) {
      await C.alertDialog({ title: t('Could not move deal'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      location.reload();
    }
  }

  document.querySelectorAll('[data-crm-deal-stage]').forEach((sel) => {
    sel.addEventListener('change', () => {
      moveDeal(
        sel.getAttribute('data-crm-deal-stage'),
        sel.value,
        sel.getAttribute('data-updated-at'),
        dealMetaFromControl(sel),
      );
    });
  });

  document.querySelectorAll('.crm-kanban__card[draggable="true"]').forEach((card) => {
    card.addEventListener('dragstart', (ev) => {
      ev.dataTransfer.setData('text/plain', card.getAttribute('data-deal-id'));
      ev.dataTransfer.setData('application/x-crm-updated-at', card.getAttribute('data-updated-at') || '');
      ev.dataTransfer.setData('application/x-crm-meta', JSON.stringify({
        companyId: card.getAttribute('data-company-id'),
        valueCents: card.getAttribute('data-value-cents'),
        products: card.getAttribute('data-products') || '',
        title: card.getAttribute('data-title') || '',
      }));
    });
  });
  document.querySelectorAll('.crm-kanban__column').forEach((col) => {
    col.addEventListener('dragover', (ev) => { ev.preventDefault(); });
    col.addEventListener('drop', (ev) => {
      ev.preventDefault();
      const id = ev.dataTransfer.getData('text/plain');
      const updatedAt = ev.dataTransfer.getData('application/x-crm-updated-at');
      let meta = {};
      try { meta = JSON.parse(ev.dataTransfer.getData('application/x-crm-meta') || '{}'); } catch (e) { meta = {}; }
      const stage = col.getAttribute('data-stage');
      if (id && stage) { moveDeal(id, stage, updatedAt, meta); }
    });
  });

  async function openEditChase(host) {
    if (!host || !C) return;
    const id = host.getAttribute('data-deal-id');
    const dialogId = 'crm-chase-' + Date.now();
    const forecastOpts = forecastOptions().map((o) =>
      '<option value="' + escapeHtml(o.value) + '"' +
      (String(o.value) === String(host.getAttribute('data-forecast-bucket') || '') ? ' selected' : '') +
      '>' + escapeHtml(o.label || o.value) + '</option>'
    ).join('');
    const html =
      '<form id="' + dialogId + '-form" class="crm-form">' +
      '<p class="crm-muted" role="status">' + escapeHtml(t('Planning labels — not expected cash.')) + '</p>' +
      '<div class="form-group"><label class="form-label" for="' + dialogId + '-owner">' + escapeHtml(t('Owner')) + '</label>' +
      '<input class="crm-input form-input" id="' + dialogId + '-owner" name="ownerUserId" type="text" value="' +
      escapeHtml(host.getAttribute('data-owner-user-id') || '') + '" autocomplete="username" /></div>' +
      '<div class="form-group"><label class="form-label" for="' + dialogId + '-quote">' + escapeHtml(t('Quote sent on')) + '</label>' +
      '<input class="crm-input form-input" id="' + dialogId + '-quote" name="quoteSentOn" type="date" value="' +
      escapeHtml(host.getAttribute('data-quote-sent-on') || '') + '" /></div>' +
      '<div class="form-group"><label class="form-label" for="' + dialogId + '-forecast">' + escapeHtml(t('Forecast')) + '</label>' +
      '<select class="crm-input form-select" id="' + dialogId + '-forecast" name="forecastBucket">' + forecastOpts + '</select></div>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save')) + '</button> ' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button></div></form>';
    C.openDialog({ id: dialogId, title: t('Edit chase'), content: html, size: 'md' });
    const form = document.getElementById(dialogId + '-form');
    const close = () => C.closeModal(document.getElementById(dialogId));
    form.querySelector('[data-cancel]').addEventListener('click', () => close());
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await Api.put('/deals/' + id, {
          ownerUserId: String(form.elements.ownerUserId.value || '').trim() || null,
          quoteSentOn: String(form.elements.quoteSentOn.value || '').trim() || null,
          forecastBucket: String(form.elements.forecastBucket.value || '').trim() || null,
          updatedAt: host.getAttribute('data-updated-at') || null,
        });
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Deal chase fields saved.');
        close();
        location.reload();
      } catch (e) {
        await C.alertDialog({ title: t('Could not save deal'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  }

  document.querySelectorAll('[data-crm-edit-chase]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const host = btn.closest('[data-deal-id]');
      openEditChase(host);
    });
  });

  const newDealBtn = document.getElementById('crm-pipeline-new-deal');
  if (newDealBtn && C) {
    newDealBtn.addEventListener('click', async () => {
      const options = companyOptions();
      if (options.length === 0) {
        await C.alertDialog({
          title: t('No companies yet'),
          message: t('Create a company first, then add a deal.'),
          variant: 'info',
        });
        return;
      }
      const id = 'crm-new-deal-' + Date.now();
      const selectHtml = options.map((c) =>
        '<option value="' + c.id + '">' +
        String(c.name).replace(/[<>&]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ch])) +
        ' (' + String(c.slug || '').replace(/[<>&]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ch])) + ')' +
        '</option>'
      ).join('');
      const html =
        '<form id="' + id + '-form" class="crm-form form">' +
        '<div class="form-group"><label class="form-label" for="' + id + '-company">' + t('Company') + '</label>' +
        '<select class="form-select crm-input" id="' + id + '-company" name="companyId" required>' + selectHtml + '</select></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-title">' + t('Title') + '</label>' +
        '<input class="form-input crm-input" id="' + id + '-title" name="title" required maxlength="255" /></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-value">' + t('Value (EUR, optional)') + '</label>' +
        '<input class="form-input crm-input" id="' + id + '-value" name="valueEur" type="number" min="0" step="0.01" /></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-products">' + t('Products / SKUs') + '</label>' +
        '<input class="form-input crm-input" id="' + id + '-products" name="products" maxlength="255" /></div>' +
        '<div class="crm-form-actions">' +
        '<button type="submit" class="crm-btn crm-btn--primary">' + t('Create deal') + '</button> ' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + t('Cancel') + '</button></div></form>';
      C.openDialog({ id: id, title: t('New deal'), content: html, size: 'md' });
      const form = document.getElementById(id + '-form');
      const close = () => C.closeModal(document.getElementById(id));
      form.querySelector('[data-cancel]').addEventListener('click', () => close());
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const companyId = parseInt(form.elements.companyId.value, 10);
        const title = String(form.elements.title.value || '').trim();
        const valueCents = Math.round(parseFloat(form.elements.valueEur.value || '0') * 100) || 0;
        const products = String(form.elements.products.value || '').trim();
        if (!companyId || title.length < 1) {
          await C.alertDialog({ title: t('Missing fields'), message: t('Choose a company and enter a title.'), variant: 'danger' });
          return;
        }
        try {
          await Api.post('/deals', { companyId, title, stage: 'lead', valueCents, products });
          close();
          location.reload();
        } catch (e) {
          await C.alertDialog({ title: t('Could not create deal'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        }
      });
    });
  }
})();
