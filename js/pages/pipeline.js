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

  function shellCan(flag) {
    if (typeof window.CustomerCheckShellCan === 'function') {
      return window.CustomerCheckShellCan(flag);
    }
    const el = document.getElementById('app-content');
    return !!(el && el.getAttribute('data-crm-can-' + flag) === '1');
  }

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

  function pipelineHasCompanies() {
    const btn = document.getElementById('crm-pipeline-new-deal');
    if (btn && btn.getAttribute('data-has-companies') === '1') {
      return true;
    }
    try {
      const el = document.getElementById('crm-pipeline-meta');
      if (!el) return false;
      const data = JSON.parse(el.textContent || '{}');
      return !!(data && data.hasCompanies);
    } catch (e) {
      return false;
    }
  }

  function companyNewUrl() {
    try {
      const el = document.getElementById('app-content');
      if (!el) return '';
      const urls = JSON.parse(String(el.getAttribute('data-crm-urls') || '{}'));
      return (urls && typeof urls.companyNew === 'string') ? urls.companyNew : '';
    } catch (e) {
      return '';
    }
  }

  /** Bachus: empty pipeline never dead-ends on an alert — offer Create company. */
  async function offerCreateCompanyFirst() {
    const url = companyNewUrl();
    if (!C) {
      if (url) {
        window.location.assign(url);
      }
      return;
    }
    const go = await C.confirmDialog({
      title: t('No companies yet'),
      message: t('Create a company first, then add a deal.'),
      confirmLabel: t('Create company'),
      cancelLabel: t('Not now'),
    });
    if (go && url) {
      window.location.assign(url);
    }
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
  const periodCustom = document.getElementById('crm-pipeline-period-custom');
  function openFilterPanel() {
    if (!filterPanel) return;
    filterPanel.hidden = false;
    if (filterToggle) filterToggle.setAttribute('aria-expanded', 'true');
    if (periodCustom) periodCustom.setAttribute('aria-expanded', 'true');
    const periodSel = document.getElementById('crm-filter-period');
    if (periodSel && periodSel.value !== 'custom') {
      periodSel.value = 'custom';
    }
  }
  if (periodCustom && filterPanel) {
    periodCustom.addEventListener('click', function () {
      openFilterPanel();
      const from = document.getElementById('crm-filter-from');
      if (from) from.focus();
    });
  }
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
        '<form id="' + id + '-form" class="crm-form crm-form--stack">' +
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

  function stageLabel(stage) {
    const token = String(stage || '');
    const sel = document.querySelector('[data-crm-deal-stage]');
    if (sel) {
      const opt = Array.from(sel.options || []).find((o) => o.value === token);
      if (opt && opt.textContent) return String(opt.textContent).trim();
    }
    const map = {
      lead: t('Lead'),
      contacted: t('Contacted'),
      quoted: t('Quoted'),
      negotiation: t('Negotiation'),
      won: t('Won'),
      lost: t('Lost'),
    };
    return map[token] || token;
  }

  async function moveDeal(id, stage, updatedAt, meta) {
    if (!shellCan('write')) {
      return;
    }
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
        live.textContent = t('Deal moved to %s.', [stageLabel(stage)]);
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

  const STAGE_ORDER = ['lead', 'contacted', 'quoted', 'negotiation', 'won', 'lost'];

  /**
   * Keyboard stage change on focused kanban cards (bible §15.1 / WCAG).
   * ArrowLeft / ArrowRight nudges stage via the same moveDeal path as the select.
   */
  document.querySelectorAll('.crm-kanban__card[tabindex="0"]').forEach((card) => {
    card.addEventListener('keydown', (ev) => {
      const tag = (ev.target && ev.target.tagName) ? String(ev.target.tagName).toLowerCase() : '';
      if (tag === 'select' || tag === 'button' || tag === 'a' || tag === 'input' || tag === 'textarea') {
        return;
      }
      if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') {
        return;
      }
      const sel = card.querySelector('[data-crm-deal-stage]');
      if (!sel || sel.disabled) {
        return;
      }
      const current = String(card.getAttribute('data-crm-stage') || sel.value || '');
      const idx = STAGE_ORDER.indexOf(current);
      if (idx < 0) {
        return;
      }
      const nextIdx = ev.key === 'ArrowRight' ? idx + 1 : idx - 1;
      if (nextIdx < 0 || nextIdx >= STAGE_ORDER.length) {
        return;
      }
      ev.preventDefault();
      const next = STAGE_ORDER[nextIdx];
      sel.value = next;
      moveDeal(
        sel.getAttribute('data-crm-deal-stage'),
        next,
        sel.getAttribute('data-updated-at') || card.getAttribute('data-updated-at'),
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

  function activeWorkspaceId() {
    const nav = document.querySelector('[data-crm-active-workspace]');
    const raw = nav ? nav.getAttribute('data-crm-active-workspace') : '';
    const id = parseInt(String(raw || ''), 10);
    return id > 0 ? id : 0;
  }

  /**
   * Single-select directory combobox. Commits only a picked uid into a hidden field.
   */
  function mountOwnerPicker(opts) {
    const search = document.getElementById(opts.searchId);
    const suggest = document.getElementById(opts.suggestId);
    const hidden = document.getElementById(opts.hiddenId);
    const clearBtn = opts.clearId ? document.getElementById(opts.clearId) : null;
    if (!search || !suggest || !hidden || !Api) {
      return { selectedUid: () => '' };
    }
    let timer = null;
    const nav = (window.CustomerCheckUserPickerNav && window.CustomerCheckUserPickerNav.bindListboxKeyboard)
      ? window.CustomerCheckUserPickerNav.bindListboxKeyboard(search, suggest)
      : { reset: function () {} };
    const initialUid = String(opts.initialUid || '').trim();
    const initialLabel = String(opts.initialLabel || initialUid || '').trim();
    if (initialUid) {
      hidden.value = initialUid;
      search.value = initialLabel + (initialLabel !== initialUid && initialUid ? ' (' + initialUid + ')' : '');
    }
    if (clearBtn) {
      clearBtn.hidden = !hidden.value;
      clearBtn.addEventListener('click', () => {
        hidden.value = '';
        search.value = '';
        suggest.innerHTML = '';
        suggest.hidden = true;
        clearBtn.hidden = true;
        search.setAttribute('aria-expanded', 'false');
        nav.reset();
        search.focus();
      });
    }
    search.addEventListener('input', () => {
      if (hidden.value) {
        hidden.value = '';
        if (clearBtn) clearBtn.hidden = true;
      }
      clearTimeout(timer);
      const q = search.value.trim();
      if (q.length < 2) {
        suggest.hidden = true;
        suggest.innerHTML = '';
        search.setAttribute('aria-expanded', 'false');
        nav.reset();
        return;
      }
      timer = setTimeout(async () => {
        try {
          let url = '/settings/users?q=' + encodeURIComponent(q);
          const wid = typeof opts.workspaceIdFn === 'function' ? opts.workspaceIdFn() : 0;
          if (wid > 0) {
            url += '&workspaceId=' + encodeURIComponent(String(wid));
          }
          const res = await Api.get(url);
          const users = (res.data && res.data.users) || [];
          suggest.innerHTML = '';
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
              hidden.value = String(u.id || '');
              search.value = (u.displayName || u.id || '') + (u.id ? ' (' + u.id + ')' : '');
              suggest.innerHTML = '';
              suggest.hidden = true;
              search.setAttribute('aria-expanded', 'false');
              nav.reset();
              if (clearBtn) clearBtn.hidden = !hidden.value;
            });
            suggest.appendChild(opt);
          });
          suggest.hidden = users.length === 0;
          search.setAttribute('aria-expanded', users.length === 0 ? 'false' : 'true');
          nav.reset();
        } catch (e) {
          suggest.hidden = true;
          search.setAttribute('aria-expanded', 'false');
          nav.reset();
        }
      }, 250);
    });
    return {
      selectedUid: () => String(hidden.value || '').trim(),
    };
  }

  async function openEditChase(host) {
    if (!host || !C) return;
    const id = host.getAttribute('data-deal-id');
    const dialogId = 'crm-chase-' + Date.now();
    const ownerUid = host.getAttribute('data-owner-user-id') || '';
    const ownerLabel = host.getAttribute('data-owner-display-name') || ownerUid;
    const forecastOpts = forecastOptions().map((o) =>
      '<option value="' + escapeHtml(o.value) + '"' +
      (String(o.value) === String(host.getAttribute('data-forecast-bucket') || '') ? ' selected' : '') +
      '>' + escapeHtml(o.label || o.value) + '</option>'
    ).join('');
    const html =
      '<form id="' + dialogId + '-form" class="crm-form crm-form--stack">' +
      '<p class="crm-muted" role="status">' + escapeHtml(t('Planning labels — not expected cash.')) + '</p>' +
      '<div class="form-group"><label class="form-label" for="' + dialogId + '-notes">' + escapeHtml(t('Notes')) + '</label>' +
      '<textarea class="crm-input form-input" id="' + dialogId + '-notes" name="notes" rows="3" maxlength="4000">' +
      escapeHtml(host.getAttribute('data-notes') || '') + '</textarea>' +
      '<p class="form-help">' + escapeHtml(t('Internal notes for your team — the customer does not see these.')) + '</p></div>' +
      '<details class="crm-advanced">' +
      '<summary class="crm-advanced__summary">' + escapeHtml(t('More options')) + '</summary>' +
      '<div class="crm-advanced__body">' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + dialogId + '-owner-search">' + escapeHtml(t('Owner')) + '</label>' +
      '<div class="user-picker" id="' + dialogId + '-owner-picker">' +
      '<div class="user-picker__control">' +
      '<input type="search" id="' + dialogId + '-owner-search" class="form-input crm-input user-picker__search" ' +
      'role="combobox" aria-autocomplete="list" aria-controls="' + dialogId + '-owner-suggest" aria-expanded="false" ' +
      'autocomplete="off" placeholder="' + escapeHtml(t('Search by name or login…')) + '" />' +
      '<button type="button" class="user-picker__clear" id="' + dialogId + '-owner-clear" hidden aria-label="' +
      escapeHtml(t('Clear')) + '">×</button></div>' +
      '<ul id="' + dialogId + '-owner-suggest" class="user-picker__list" role="listbox" hidden></ul>' +
      '<input type="hidden" id="' + dialogId + '-owner" name="ownerUserId" value="" />' +
      '</div>' +
      '<p class="crm-muted">' + escapeHtml(t('Search by name or login, then pick a colleague. Never type a raw user id.')) + '</p></div>' +
      '<div class="form-group"><label class="form-label" for="' + dialogId + '-quote">' + escapeHtml(t('Quote sent on')) + '</label>' +
      '<input class="crm-input form-input" id="' + dialogId + '-quote" name="quoteSentOn" type="date" value="' +
      escapeHtml(host.getAttribute('data-quote-sent-on') || '') + '" /></div>' +
      '<div class="form-group">' +
      '<span class="form-label" id="' + dialogId + '-pdf-label">' + escapeHtml(t('Quote PDF')) + '</span>' +
      '<p class="crm-muted" id="' + dialogId + '-pdf-hint">' +
      escapeHtml(t('Link a PDF from Files (optional). We check you can read it.')) + '</p>' +
      '<div class="crm-form-actions crm-form-actions--start">' +
      '<button type="button" class="crm-btn crm-btn--secondary" id="' + dialogId + '-pdf-pick" aria-describedby="' +
      dialogId + '-pdf-hint">' + escapeHtml(t('Pick PDF')) + '</button>' +
      '<button type="button" class="crm-btn crm-btn--secondary" id="' + dialogId + '-pdf-clear" hidden>' +
      escapeHtml(t('Clear PDF')) + '</button></div>' +
      '<p class="crm-muted" id="' + dialogId + '-pdf-name" role="status" aria-live="polite"></p>' +
      '<input type="hidden" id="' + dialogId + '-pdf" name="quoteFileId" value="" />' +
      '</div>' +
      '<div class="form-group"><label class="form-label" for="' + dialogId + '-forecast">' + escapeHtml(t('Win chance')) + '</label>' +
      '<select class="crm-input form-select" id="' + dialogId + '-forecast" name="forecastBucket">' + forecastOpts + '</select></div>' +
      '</div></details>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save')) + '</button> ' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button></div></form>';
    C.openDialog({ id: dialogId, title: t('Edit deal'), content: html, size: 'md' });
    const form = document.getElementById(dialogId + '-form');
    const ownerPicker = mountOwnerPicker({
      searchId: dialogId + '-owner-search',
      suggestId: dialogId + '-owner-suggest',
      hiddenId: dialogId + '-owner',
      clearId: dialogId + '-owner-clear',
      initialUid: ownerUid,
      initialLabel: ownerLabel,
      workspaceIdFn: activeWorkspaceId,
    });
    const quoteFileHidden = document.getElementById(dialogId + '-pdf');
    const quoteFileName = document.getElementById(dialogId + '-pdf-name');
    const quoteClearBtn = document.getElementById(dialogId + '-pdf-clear');
    const initialQuoteId = parseInt(String(host.getAttribute('data-quote-file-id') || '0'), 10);
    const setQuoteFile = (fileId, label) => {
      const id = fileId && fileId > 0 ? fileId : 0;
      quoteFileHidden.value = id > 0 ? String(id) : '';
      quoteFileName.textContent = id > 0
        ? (label ? t('Linked: %s', [label]) : t('PDF linked (file %s)', [String(id)]))
        : t('No PDF linked.');
      quoteClearBtn.hidden = id < 1;
    };
    setQuoteFile(initialQuoteId > 0 ? initialQuoteId : 0, '');
    document.getElementById(dialogId + '-pdf-pick').addEventListener('click', async () => {
      const Picker = window.CustomerCheckFolderPicker;
      if (!Picker || typeof Picker.pickFile !== 'function') {
        await C.alertDialog({
          title: t('Files picker unavailable'),
          message: t('Open Files, then try again from this dialog.'),
          variant: 'warning',
        });
        return;
      }
      const picked = await Picker.pickFile({
        mimeTypes: ['application/pdf'],
        title: t('Select quote PDF'),
      });
      if (!picked || picked.unavailable) {
        if (picked && picked.unavailable) {
          await C.alertDialog({
            title: t('Files picker unavailable'),
            message: t('Open Files, then try again from this dialog.'),
            variant: 'warning',
          });
        }
        return;
      }
      const fid = parseInt(String(picked.fileId || 0), 10);
      if (fid < 1) {
        await C.alertDialog({
          title: t('Could not link PDF'),
          message: t('Pick a PDF file you can read in Files.'),
          variant: 'warning',
        });
        return;
      }
      setQuoteFile(fid, picked.name || '');
    });
    quoteClearBtn.addEventListener('click', () => setQuoteFile(0, ''));
    const close = () => C.closeModal(document.getElementById(dialogId));
    form.querySelector('[data-cancel]').addEventListener('click', () => close());
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        const qfid = parseInt(String(quoteFileHidden.value || '0'), 10);
        await Api.put('/deals/' + id, {
          notes: String(form.elements.notes.value || '').trim(),
          ownerUserId: ownerPicker.selectedUid() || null,
          quoteSentOn: String(form.elements.quoteSentOn.value || '').trim() || null,
          quoteFileId: qfid > 0 ? qfid : null,
          forecastBucket: String(form.elements.forecastBucket.value || '').trim() || null,
          updatedAt: host.getAttribute('data-updated-at') || null,
        });
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Deal saved.');
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
  const emptyNewDealBtn = document.getElementById('crm-pipeline-empty-new');
  const openNewDeal = () => {
    if (newDealBtn) newDealBtn.click();
  };
  if (emptyNewDealBtn) {
    emptyNewDealBtn.addEventListener('click', openNewDeal);
  }
  if (newDealBtn && C) {
    newDealBtn.addEventListener('click', async () => {
      if (!pipelineHasCompanies()) {
        await offerCreateCompanyFirst();
        return;
      }
      const id = 'crm-new-deal-' + Date.now();
      const html =
        '<form id="' + id + '-form" class="crm-form crm-form--stack crm-form--new-deal">' +
        '<div class="form-group crm-new-deal__company">' +
        '<span class="form-label" id="' + id + '-company-label">' + escapeHtml(t('Company')) + '</span>' +
        '<div class="crm-company-picker crm-deal-company-picker" id="' + id + '-company-picker" ' +
        'aria-labelledby="' + id + '-company-label">' +
        '<div class="crm-company-choice" id="' + id + '-company-choice" hidden>' +
        '<div class="crm-company-choice__card">' +
        '<p class="crm-company-choice__name" id="' + id + '-company-choice-name"></p>' +
        '<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm crm-company-choice__change" id="' +
        id + '-company-change">' + escapeHtml(t('Change company')) + '</button>' +
        '</div></div>' +
        '<div class="crm-company-browse" id="' + id + '-company-browse">' +
        '<div class="crm-company-picker__filter-wrap" id="' + id + '-company-filter-wrap" hidden>' +
        '<label class="visually-hidden" for="' + id + '-company-filter">' +
        escapeHtml(t('Filter companies')) + '</label>' +
        '<input type="search" id="' + id + '-company-filter" class="form-input crm-input crm-company-picker__filter" ' +
        'autocomplete="off" placeholder="' + escapeHtml(t('Search by company name…')) + '" ' +
        'aria-controls="' + id + '-company-list" /></div>' +
        '<ul id="' + id + '-company-list" class="crm-company-list" role="listbox" aria-labelledby="' +
        id + '-company-label"></ul>' +
        '<p class="crm-muted crm-company-picker__status" id="' + id + '-company-status" role="status" aria-live="polite" hidden></p>' +
        '</div>' +
        '<input type="hidden" id="' + id + '-company" name="companyId" value="" required />' +
        '</div></div>' +
        '<div class="form-group crm-new-deal__title">' +
        '<label class="form-label" for="' + id + '-title">' + escapeHtml(t('What is this deal?')) + '</label>' +
        '<input class="form-input crm-input" id="' + id + '-title" name="title" required maxlength="255" ' +
        'placeholder="' + escapeHtml(t('e.g. Quote for workshop tools')) + '" />' +
        '<p class="form-help">' + escapeHtml(t('A title is enough. Value and products are optional.')) + '</p></div>' +
        '<details class="crm-advanced crm-new-deal__more">' +
        '<summary class="crm-advanced__summary">' + escapeHtml(t('More options')) + '</summary>' +
        '<div class="crm-advanced__body">' +
        '<div class="form-group"><label class="form-label" for="' + id + '-value">' + escapeHtml(t('Value (EUR, optional)')) + '</label>' +
        '<input class="form-input crm-input" id="' + id + '-value" name="valueEur" type="number" min="0" step="0.01" /></div>' +
        '<div class="form-group"><label class="form-label" for="' + id + '-products">' + escapeHtml(t('Products (optional)')) + '</label>' +
        '<input class="form-input crm-input" id="' + id + '-products" name="products" maxlength="255" /></div>' +
        '</div></details>' +
        '<div class="crm-form-actions crm-new-deal__actions">' +
        '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Create deal')) + '</button>' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + escapeHtml(t('Cancel')) + '</button></div></form>';
      let companyPanel = null;
      C.openDialog({
        id: id,
        title: t('New deal'),
        content: html,
        size: 'md',
        onClose: () => {
          if (companyPanel && typeof companyPanel.destroy === 'function') {
            companyPanel.destroy();
          }
          companyPanel = null;
        },
      });
      const form = document.getElementById(id + '-form');
      const dialogEl = document.getElementById(id);
      if (dialogEl) {
        dialogEl.classList.add('crm-dialog--new-deal');
      }
      const close = () => C.closeModal(dialogEl);
      form.querySelector('[data-cancel]').addEventListener('click', () => close());
      const titleInput = form.elements.title;
      const Picker = window.CustomerCheckCompanyPicker;
      let emptied = false;
      const focusTitle = () => {
        if (titleInput && typeof titleInput.focus === 'function') {
          setTimeout(() => titleInput.focus(), 40);
        }
      };
      if (Picker && typeof Picker.mountListPanel === 'function') {
        companyPanel = Picker.mountListPanel({
          listEl: document.getElementById(id + '-company-list'),
          filterEl: document.getElementById(id + '-company-filter'),
          filterWrapEl: document.getElementById(id + '-company-filter-wrap'),
          browseEl: document.getElementById(id + '-company-browse'),
          choiceEl: document.getElementById(id + '-company-choice'),
          choiceNameEl: document.getElementById(id + '-company-choice-name'),
          changeBtn: document.getElementById(id + '-company-change'),
          hiddenEl: document.getElementById(id + '-company'),
          statusEl: document.getElementById(id + '-company-status'),
          api: Api,
          workspaceIdFn: activeWorkspaceId,
          t: t,
          browseLimit: 40,
          filterMinCount: 8,
          onReady: async (count) => {
            if (count > 0) {
              if (count === 1) {
                focusTitle();
              }
              return;
            }
            if (emptied) {
              return;
            }
            emptied = true;
            if (companyPanel && typeof companyPanel.destroy === 'function') {
              companyPanel.destroy();
            }
            close();
            await offerCreateCompanyFirst();
          },
          onSelect: () => {
            focusTitle();
          },
        });
      } else {
        close();
        await offerCreateCompanyFirst();
      }
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn && submitBtn.disabled) {
          return;
        }
        const companyId = parseInt(String(form.elements.companyId.value || '0'), 10);
        const title = String(form.elements.title.value || '').trim();
        const valueCents = Math.round(parseFloat(form.elements.valueEur.value || '0') * 100) || 0;
        const products = String(form.elements.products.value || '').trim();
        if (!companyId || title.length < 1) {
          await C.alertDialog({ title: t('Almost there'), message: t('Choose a company and enter a title.'), variant: 'info' });
          return;
        }
        if (submitBtn) submitBtn.disabled = true;
        try {
          await Api.post('/deals', { companyId, title, stage: 'lead', valueCents, products });
          if (companyPanel && typeof companyPanel.destroy === 'function') {
            companyPanel.destroy();
          }
          close();
          location.reload();
        } catch (e) {
          if (submitBtn) submitBtn.disabled = false;
          await C.alertDialog({ title: t('Could not create deal'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
        }
      });
    });
  }
})();
