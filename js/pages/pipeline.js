(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  if (C && C.init) { C.init(); }

  function companyOptions() {
    const el = document.getElementById('crm-pipeline-companies');
    if (!el) return [];
    try {
      const data = JSON.parse(el.textContent || '[]');
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
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
        if (C) await C.alertDialog({ title: 'Error', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });

  async function offerCreateAgreement(dealMeta) {
    if (!C) return;
    const result = await C.confirmDialog({
      title: 'Create agreement?',
      message: 'This deal is won. Create a draft agreement pre-filled with company, value and products?',
      confirmLabel: 'Create agreement',
      cancelLabel: 'Not now',
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
    };
  }

  async function moveDeal(id, stage, updatedAt, meta) {
    let lostReason = null;
    if (stage === 'lost') {
      const result = await C.confirmDialog({
        title: 'Mark as lost',
        message: 'Please enter a short lost reason (at least 3 characters).',
        confirmLabel: 'Save',
        cancelLabel: 'Cancel',
        variant: 'warning',
        requireReason: true,
      });
      if (!result || !result.confirmed) {
        location.reload();
        return;
      }
      lostReason = (result.reason || '').trim();
      if (lostReason.length < 3) {
        location.reload();
        return;
      }
    }
    try {
      await Api.post('/deals/' + id + '/stage', { stage, lostReason, updatedAt });
      const live = document.getElementById('crm-live-region');
      if (live) {
        live.textContent = 'Deal moved to ' + stage + '.';
      }
      if (stage === 'won') {
        await offerCreateAgreement(Object.assign({ id }, meta || {}));
      }
      location.reload();
    } catch (e) {
      await C.alertDialog({ title: 'Could not move deal', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
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

  const newDealBtn = document.getElementById('crm-pipeline-new-deal');
  if (newDealBtn && C) {
    newDealBtn.addEventListener('click', async () => {
      const options = companyOptions();
      if (options.length === 0) {
        await C.alertDialog({
          title: 'No companies yet',
          message: 'Create a company first, then add a deal.',
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
        '<div class="form-group"><label class="form-label" for="' + id + '-company">Company</label>' +
        '<select class="form-select crm-input" id="' + id + '-company" name="companyId" required>' + selectHtml + '</select></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-title">Title</label>' +
        '<input class="form-input crm-input" id="' + id + '-title" name="title" required maxlength="255" /></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-value">Value (EUR, optional)</label>' +
        '<input class="form-input crm-input" id="' + id + '-value" name="valueEur" type="number" min="0" step="0.01" /></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-products">Products / SKUs</label>' +
        '<input class="form-input crm-input" id="' + id + '-products" name="products" maxlength="255" /></div>' +
        '<div class="crm-form-actions">' +
        '<button type="submit" class="crm-btn crm-btn--primary">Create deal</button> ' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>Cancel</button></div></form>';
      C.openDialog({ id: id, title: 'New deal', content: html, size: 'md' });
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
          await C.alertDialog({ title: 'Missing fields', message: 'Choose a company and enter a title.', variant: 'danger' });
          return;
        }
        try {
          await Api.post('/deals', { companyId, title, stage: 'lead', valueCents, products });
          close();
          location.reload();
        } catch (e) {
          await C.alertDialog({ title: 'Could not create deal', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        }
      });
    });
  }
})();
