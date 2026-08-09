(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg, args) => (window.t ? window.t('customercheck', msg, args || []) : msg);
  if (C && C.init) { C.init(); }

  let lastScan = null;
  let scanSeq = 0;

  const root = document.getElementById('crm-import-root');
  const csvWrap = document.getElementById('crm-import-csv-wrap');
  const linkDefaultWrap = document.getElementById('crm-import-link-default');
  const scanBtn = document.getElementById('crm-import-scan');
  const acceptBtn = document.getElementById('crm-import-accept-unambiguous');
  const applyBtn = document.getElementById('crm-import-apply');
  const sourceStatus = document.getElementById('crm-import-source-status');
  const selected = () => (document.querySelector('input[name="crm-import-source"]:checked') || {}).value || 'csv';

  function escapeHtml(s) {
    return String(s || '').replace(/[<>&"']/g, (ch) => ({
      '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;',
    }[ch]));
  }

  function setBusy(busy, message) {
    if (root) {
      root.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
    if (scanBtn) {
      scanBtn.disabled = !!busy;
    }
    if (sourceStatus) {
      sourceStatus.textContent = message || '';
    }
  }

  function syncSourceUi() {
    const src = selected();
    csvWrap.hidden = src !== 'csv';
    if (linkDefaultWrap) {
      linkDefaultWrap.hidden = src !== 'projectcheck';
    }
    if (scanBtn) {
      scanBtn.textContent = src === 'csv' ? t('Check my list') : t('Preview matches');
    }
  }

  document.querySelectorAll('input[name="crm-import-source"]').forEach((r) => {
    r.addEventListener('change', () => {
      syncSourceUi();
      const src = selected();
      if (src !== 'csv') {
        runScan({ auto: true });
      } else if (sourceStatus) {
        sourceStatus.textContent = t('Add a CSV or JSON file, or paste it, then tap Check my list.');
      }
    });
  });
  syncSourceUi();

  function linkSiblingPreference() {
    if (selected() !== 'projectcheck') {
      return true;
    }
    const el = document.querySelector('input[name="crm-import-link-sibling"]:checked');
    return !el || el.value !== '0';
  }

  function actionLabel(action) {
    switch (String(action || '')) {
      case 'create': return t('Add as new company');
      case 'link': return t('Link to existing');
      case 'skip': return t('Skip');
      case 'needs_decision': return t('Pick a match');
      default: return String(action || '—');
    }
  }

  function humanErrorReason(reason) {
    switch (String(reason || '')) {
      case 'missing_header': return t('Missing column header');
      case 'required': return t('Required');
      case 'column_count': return t('Wrong number of columns');
      case 'empty': return t('List is empty');
      case 'EMAIL_INVALID': return t('Invalid email');
      case 'SLUG_INVALID': return t('Invalid id');
      case 'PRODUCT_CODE_INVALID': return t('Unknown product code');
      case 'bad_date': return t('Invalid date');
      case 'json_invalid': return t('That JSON could not be read');
      case 'json_row_invalid': return t('One JSON row was invalid');
      case 'too_large': return t('File is too large (max 2 MB)');
      case 'too_many_rows': return t('Only the first 5000 rows were checked');
      default: return String(reason || t('Error'));
    }
  }

  /** Safe single-value interpolate — NC JS leaves bare %s unsubstituted in some locales. */
  function fillOne(template, value) {
    const raw = t(template);
    const v = String(value);
    if (raw.indexOf('%1$s') !== -1) return raw.replace('%1$s', v);
    if (raw.indexOf('%s') !== -1) return raw.replace('%s', v);
    return raw + ' ' + v;
  }

  const fileInput = document.getElementById('crm-import-file');
  const dropZone = document.getElementById('crm-import-drop');
  const fileNameEl = document.getElementById('crm-import-file-name');
  const pasteArea = document.getElementById('crm-import-csv');
  const MAX_FILE_BYTES = 2 * 1024 * 1024;

  function setFileLabel(name) {
    if (!fileNameEl) return;
    if (!name) {
      fileNameEl.hidden = true;
      fileNameEl.textContent = '';
      return;
    }
    fileNameEl.hidden = false;
    fileNameEl.textContent = fillOne('Loaded: %s', name);
  }

  async function readImportFile(file) {
    if (!file) return;
    const name = String(file.name || 'file');
    const lower = name.toLowerCase();
    const okExt = /\.(csv|json|txt)$/.test(lower)
      || /^(text\/(csv|plain)|application\/json)/.test(String(file.type || ''));
    if (!okExt) {
      await C.alertDialog({
        title: t('Unsupported file'),
        message: t('Please choose a .csv, .json, or .txt file from mail.'),
        variant: 'warning',
      });
      return;
    }
    if (file.size > MAX_FILE_BYTES) {
      await C.alertDialog({
        title: t('File is too large'),
        message: t('Keep the file under 2 MB, or paste only the rows you need.'),
        variant: 'warning',
      });
      return;
    }
    const text = await file.text();
    if (pasteArea) {
      pasteArea.value = text;
      pasteArea.focus();
    }
    setFileLabel(name);
    if (sourceStatus) {
      sourceStatus.textContent = t('File loaded — tap Check my list when ready.');
    }
  }

  if (fileInput) {
    fileInput.addEventListener('change', () => {
      const f = fileInput.files && fileInput.files[0];
      readImportFile(f);
    });
  }

  if (dropZone) {
    ['dragenter', 'dragover'].forEach((ev) => {
      dropZone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach((ev) => {
      dropZone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.remove('is-dragover');
      });
    });
    dropZone.addEventListener('drop', (e) => {
      const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      readImportFile(f);
    });
  }

  function matchLabel(match) {
    if (!match) return '';
    const name = (match.companyName || '').trim();
    if (name) return name;
    const candidates = match.candidates || [];
    if (match.companyId && candidates.length) {
      const hit = candidates.find((c) => c.id === match.companyId) || candidates[0];
      if (hit && hit.name) return hit.name;
    }
    if (candidates[0] && candidates[0].name) return candidates[0].name;
    return '';
  }

  function updateActionButtons(scan) {
    const m = scan.meta || {};
    const ready = (m.create || 0) + (m.link || 0);
    const needs = m.needsDecision || 0;
    if (acceptBtn) {
      acceptBtn.disabled = ready < 1;
      if (ready < 1) {
        acceptBtn.textContent = t('Import ready');
      } else if (ready === 1) {
        acceptBtn.textContent = t('Import 1 ready company');
      } else {
        acceptBtn.textContent = fillOne('Import %s ready companies', ready);
      }
    }
    if (applyBtn) {
      applyBtn.hidden = needs < 1;
    }
    if (root) {
      root.setAttribute('data-crm-import-ready', String(ready));
    }
  }

  function renderProposals(scan) {
    lastScan = scan;
    const review = document.getElementById('crm-import-review');
    const tbody = document.querySelector('#crm-import-table tbody');
    const meta = document.getElementById('crm-import-meta');
    const errBox = document.getElementById('crm-import-errors');
    const empty = document.getElementById('crm-import-empty');
    const stats = document.getElementById('crm-import-stats');
    review.hidden = false;
    document.getElementById('crm-import-summary').hidden = true;

    const m = scan.meta || {};
    const ready = (m.create || 0) + (m.link || 0);
    const needs = m.needsDecision || 0;
    const skip = m.skip || 0;
    const total = m.total || (scan.proposals || []).length;

    document.getElementById('crm-import-stat-ready').textContent = String(ready);
    document.getElementById('crm-import-stat-choice').textContent = String(needs);
    document.getElementById('crm-import-stat-skip').textContent = String(skip);
    if (stats) stats.hidden = total < 1;

    if (meta) {
      if (total < 1) {
        meta.textContent = t('Nothing matched yet.');
      } else if (ready > 0 && needs === 0) {
        meta.textContent = t('Everything below looks clear — import when you are ready.');
      } else if (needs > 0) {
        meta.textContent = t('Some names need a quick pick. Ready rows can still import now.');
      } else {
        meta.textContent = t('Nothing ready to import yet.');
      }
    }

    updateActionButtons(scan);

    tbody.innerHTML = '';
    if (scan.errors && scan.errors.length) {
      errBox.hidden = false;
      errBox.textContent = scan.errors.map((e) => {
        const lineBit = e.line != null ? (t('Line') + ' ' + String(e.line)) : t('Row');
        const colBit = e.column ? ' (' + e.column + ')' : '';
        const reason = humanErrorReason(e.reason);
        return lineBit + colBit + ': ' + reason;
      }).join('; ');
    } else {
      errBox.hidden = true;
    }

    if (empty) {
      empty.hidden = (scan.proposals || []).length > 0;
    }

    (scan.proposals || []).forEach((p) => {
      const tr = document.createElement('tr');
      const company = (p.row.companyName || p.row.name || p.row.customerId || '—');
      const unambiguous = p.action === 'create' || p.action === 'link';
      const include = unambiguous && p.action !== 'skip';
      const matched = matchLabel(p.match);

      let decisionHtml = '';
      if (p.action === 'needs_decision') {
        const opts = (p.match.candidates || []).map((c) =>
          '<option value="' + c.id + '">' + escapeHtml(c.name || '') + '</option>'
        ).join('');
        decisionHtml =
          '<label class="crm-sr-only" for="crm-import-dec-' + p.index + '">' + escapeHtml(t('Pick a match')) + '</label>' +
          '<select class="crm-input" id="crm-import-dec-' + p.index + '" data-decision-index="' + p.index + '">' +
          '<option value="skip">' + escapeHtml(t('Skip')) + '</option>' +
          '<option value="create">' + escapeHtml(t('Create new')) + '</option>' +
          opts +
          '</select>';
      } else if (p.action === 'skip') {
        decisionHtml = '<span class="crm-muted">' + escapeHtml(p.skipReason || t('Skip')) + '</span>';
      } else if (matched) {
        decisionHtml =
          '<span class="crm-import-plan">' +
          '<span class="crm-badge">' + escapeHtml(actionLabel(p.action)) + '</span>' +
          '<span class="crm-import-plan__match">' + escapeHtml(matched) + '</span>' +
          '</span>';
      } else {
        decisionHtml = '<span class="crm-badge">' + escapeHtml(actionLabel(p.action)) + '</span>';
      }

      tr.className = 'crm-import-row' + (unambiguous ? ' crm-import-row--ready' : '') + (p.action === 'needs_decision' ? ' crm-import-row--choice' : '');
      const includeDisabled = p.action === 'skip';
      const includeLabel = include ? t('Include') : t('Skip');
      const includeBtnClass = include
        ? 'crm-btn crm-btn--sm crm-btn--primary'
        : 'crm-btn crm-btn--sm crm-btn--secondary';
      tr.innerHTML =
        '<td><strong class="crm-import-company">' + escapeHtml(company) + '</strong></td>' +
        '<td>' + decisionHtml + '</td>' +
        '<td data-label="' + escapeHtml(t('Include')) + '" class="actions-cell crm-table-actions-col">' +
        '<div class="crm-table-actions">' +
        '<label class="crm-import-include' + (includeDisabled ? ' crm-import-include--disabled' : '') + '">' +
        '<input type="checkbox" class="crm-sr-only" data-include-index="' + p.index + '" ' +
          (include ? 'checked' : '') +
          (includeDisabled ? ' disabled' : '') +
          ' />' +
        '<span class="' + includeBtnClass + '" aria-hidden="true">' + escapeHtml(includeLabel) + '</span>' +
        '<span class="crm-sr-only">' + escapeHtml(fillOne('Include %s', company)) + '</span>' +
        '</label></div></td>';
      tbody.appendChild(tr);
      const includeInput = tr.querySelector('input[data-include-index]');
      if (includeInput && !includeDisabled) {
        includeInput.addEventListener('change', function () {
          const face = tr.querySelector('.crm-import-include .crm-btn');
          if (!(face instanceof HTMLElement)) return;
          const on = includeInput.checked;
          face.textContent = on ? t('Include') : t('Skip');
          face.className = on
            ? 'crm-btn crm-btn--sm crm-btn--primary'
            : 'crm-btn crm-btn--sm crm-btn--secondary';
        });
      }
    });

    if (C && C.announce) {
      C.announce(fillOne('Preview ready: %s companies', total));
    }
  }

  async function runScan(opts) {
    const auto = !!(opts && opts.auto);
    const source = selected();
    const payload = { source };
    if (source === 'csv') {
      const csv = document.getElementById('crm-import-csv').value;
      if (!String(csv || '').trim()) {
        if (!auto) {
          await C.alertDialog({
            title: t('Add a list first'),
            message: t('Drop a CSV/JSON file from mail, or paste the contents, then try again.'),
            variant: 'warning',
          });
        }
        return;
      }
      payload.csv = csv;
    }

    const mySeq = ++scanSeq;
    setBusy(true, auto ? t('Looking for matches…') : t('Checking your list…'));
    try {
      const res = await Api.post('/import/scan', payload);
      if (mySeq !== scanSeq) return;
      renderProposals(res.data);
      setBusy(false, '');
      const review = document.getElementById('crm-import-review');
      if (review && typeof review.focus === 'function') {
        review.setAttribute('tabindex', '-1');
        review.focus({ preventScroll: false });
      }
    } catch (e) {
      if (mySeq !== scanSeq) return;
      setBusy(false, '');
      await C.alertDialog({
        title: t('Could not check the list'),
        message: (e.payload && e.payload.message) || e.message,
        variant: 'danger',
      });
    }
  }

  scanBtn.addEventListener('click', () => runScan({ auto: false }));

  async function applySelected(mode) {
    if (!lastScan) return;
    const decisions = [];
    document.querySelectorAll('[data-include-index]').forEach((cb) => {
      const index = parseInt(cb.getAttribute('data-include-index'), 10);
      const p = lastScan.proposals.find((x) => x.index === index);
      if (!p) return;
      if (!cb.checked) {
        decisions.push({ index, action: 'skip' });
        return;
      }
      const sel = document.querySelector('[data-decision-index="' + index + '"]');
      let decision;
      if (sel) {
        const v = sel.value;
        if (v === 'skip') decision = { index, action: 'skip' };
        else if (v === 'create') decision = { index, action: 'create' };
        else decision = { index, action: 'link', companyId: parseInt(v, 10) };
      } else {
        decision = { index, action: p.action, companyId: p.match.companyId || null };
      }
      if (decision.action === 'create' && lastScan.source === 'projectcheck') {
        decision.linkSibling = linkSiblingPreference();
      }
      decisions.push(decision);
    });

    if (mode === 'ready-only') {
      // Already enforced by checkbox prep in the click handler.
    }

    const payload = {
      source: lastScan.source,
      decisions,
    };
    if (lastScan.source === 'csv') {
      payload.csv = document.getElementById('crm-import-csv').value;
    }

    setBusy(true, t('Importing…'));
    if (acceptBtn) acceptBtn.disabled = true;
    if (applyBtn) applyBtn.disabled = true;
    try {
      const res = await Api.post('/import/apply', payload);
      const summary = res.data;
      const box = document.getElementById('crm-import-summary');
      const body = document.getElementById('crm-import-summary-body');
      box.hidden = false;
      const companiesUrl = (window.OC && OC.generateUrl)
        ? OC.generateUrl('/apps/customercheck/companies')
        : '/apps/customercheck/companies';
      const created = Number(summary.created) || 0;
      const linked = Number(summary.linked) || 0;
      const skipped = Number(summary.skipped) || 0;
      const agreements = Number(summary.agreements) || 0;
      const parts = [];
      if (created) parts.push(fillOne('Added %s new', created));
      if (linked) parts.push(fillOne('Linked %s existing', linked));
      if (agreements) parts.push(fillOne('Updated %s agreements', agreements));
      if (skipped) parts.push(fillOne('Skipped %s', skipped));
      body.innerHTML =
        '<p class="crm-import-summary-lead">' + escapeHtml(parts.join(' · ') || t('Nothing changed.')) + '</p>' +
        (summary.errors && summary.errors.length
          ? '<div class="crm-callout crm-callout--warning" role="status"><p>' +
            escapeHtml(fillOne('%s row error(s).', summary.errors.length)) +
            '</p></div>'
          : '') +
        '<p class="crm-form-actions">' +
        '<a class="crm-btn crm-btn--primary crm-btn--lg" href="' + companiesUrl + '">' +
        escapeHtml(t('Open companies')) +
        '</a></p>';
      if (C && C.showToast) C.showToast({ type: 'success', message: t('Import finished') });
      setBusy(false, '');
      box.setAttribute('tabindex', '-1');
      box.focus({ preventScroll: false });
    } catch (e) {
      setBusy(false, '');
      updateActionButtons(lastScan);
      if (applyBtn) applyBtn.disabled = false;
      await C.alertDialog({
        title: t('Import failed'),
        message: (e.payload && e.payload.message) || e.message,
        variant: 'danger',
      });
    }
  }

  acceptBtn.addEventListener('click', async () => {
    if (!lastScan) return;
    document.querySelectorAll('[data-include-index]').forEach((cb) => {
      const idx = parseInt(cb.getAttribute('data-include-index'), 10);
      const p = lastScan.proposals.find((x) => x.index === idx);
      if (p && (p.action === 'create' || p.action === 'link')) {
        cb.checked = true;
      } else if (p) {
        cb.checked = false;
      }
    });
    await applySelected('ready-only');
  });

  applyBtn.addEventListener('click', async () => {
    await applySelected('selected');
  });
})();
