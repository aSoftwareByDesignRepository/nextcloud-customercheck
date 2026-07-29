(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const t = (msg, args) => (window.t ? window.t('customercheck', msg, args || []) : msg);
  if (C && C.init) { C.init(); }

  let lastScan = null;

  const csvWrap = document.getElementById('crm-import-csv-wrap');
  document.querySelectorAll('input[name="crm-import-source"]').forEach((r) => {
    r.addEventListener('change', () => {
      csvWrap.hidden = r.value !== 'csv' && !document.querySelector('input[name="crm-import-source"][value="csv"]').checked
        ? true
        : document.querySelector('input[name="crm-import-source"]:checked').value !== 'csv';
    });
  });
  // initial
  const selected = () => (document.querySelector('input[name="crm-import-source"]:checked') || {}).value || 'csv';
  csvWrap.hidden = selected() !== 'csv';

  function matchLabel(match) {
    if (!match) return '—';
    const name = (match.companyName || '').trim();
    const slug = (match.companySlug || '').trim();
    if (name && slug) return name + ' (' + slug + ')';
    if (name) return name;
    if (slug) return slug;
    const candidates = match.candidates || [];
    if (match.companyId && candidates.length) {
      const hit = candidates.find((c) => c.id === match.companyId) || candidates[0];
      if (hit && hit.name) {
        return hit.slug ? (hit.name + ' (' + hit.slug + ')') : hit.name;
      }
    }
    if (candidates[0] && candidates[0].name) {
      return candidates[0].slug ? (candidates[0].name + ' (' + candidates[0].slug + ')') : candidates[0].name;
    }
    return match.suggestedSlug || '—';
  }

  function renderProposals(scan) {
    lastScan = scan;
    const review = document.getElementById('crm-import-review');
    const tbody = document.querySelector('#crm-import-table tbody');
    const meta = document.getElementById('crm-import-meta');
    const errBox = document.getElementById('crm-import-errors');
    review.hidden = false;
    document.getElementById('crm-import-summary').hidden = true;
    meta.textContent = 'Total ' + scan.meta.total + ' · create ' + scan.meta.create + ' · link ' + scan.meta.link + ' · skip ' + scan.meta.skip + ' · needs decision ' + scan.meta.needsDecision;
    tbody.innerHTML = '';
    if (scan.errors && scan.errors.length) {
      errBox.hidden = false;
      errBox.textContent = scan.errors.map((e) => 'Line ' + e.line + (e.column ? ' (' + e.column + ')' : '') + ': ' + e.reason).join('; ');
    } else {
      errBox.hidden = true;
    }
    scan.proposals.forEach((p) => {
      const tr = document.createElement('tr');
      const company = (p.row.companyName || p.row.name || p.row.customerId || '—');
      const unambiguous = p.action === 'create' || p.action === 'link';
      const include = unambiguous && p.action !== 'skip';
      let decisionHtml = '';
      if (p.action === 'needs_decision') {
        const opts = (p.match.candidates || []).map((c) => '<option value="' + c.id + '">' + c.name + ' (' + c.slug + ' via ' + c.via + ')</option>').join('');
        decisionHtml = '<select class="crm-input" data-decision-index="' + p.index + '"><option value="skip">Skip</option><option value="create">Create new</option>' + opts + '</select>';
      } else if (p.action === 'skip') {
        decisionHtml = '<span class="crm-muted">' + (p.skipReason || 'skip') + '</span>';
      } else {
        decisionHtml = '<span class="crm-muted">' + p.action + '</span>';
      }
      tr.innerHTML =
        '<td><input type="checkbox" data-include-index="' + p.index + '" ' + (include ? 'checked' : '') + (p.action === 'skip' ? ' disabled' : '') + ' /></td>' +
        '<td>' + company + '</td>' +
        '<td><span class="crm-badge">' + p.action + '</span></td>' +
        '<td>' + matchLabel(p.match) + '</td>' +
        '<td>' + decisionHtml + '</td>';
      tbody.appendChild(tr);
    });
  }

  document.getElementById('crm-import-scan').addEventListener('click', async () => {
    const source = selected();
    const payload = { source };
    if (source === 'csv') {
      payload.csv = document.getElementById('crm-import-csv').value;
    }
    try {
      const res = await Api.post('/import/scan', payload);
      renderProposals(res.data);
    } catch (e) {
      await C.alertDialog({ title: t('Scan failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    }
  });

  document.getElementById('crm-import-accept-unambiguous').addEventListener('click', () => {
    document.querySelectorAll('[data-include-index]').forEach((cb) => {
      const idx = parseInt(cb.getAttribute('data-include-index'), 10);
      const p = lastScan && lastScan.proposals.find((x) => x.index === idx);
      if (p && (p.action === 'create' || p.action === 'link')) {
        cb.checked = true;
      }
    });
  });

  document.getElementById('crm-import-apply').addEventListener('click', async () => {
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
      if (sel) {
        const v = sel.value;
        if (v === 'skip') decisions.push({ index, action: 'skip' });
        else if (v === 'create') decisions.push({ index, action: 'create' });
        else decisions.push({ index, action: 'link', companyId: parseInt(v, 10) });
      } else {
        decisions.push({ index, action: p.action, companyId: p.match.companyId || null });
      }
    });
    const payload = {
      source: lastScan.source,
      decisions,
    };
    if (lastScan.source === 'csv') {
      payload.csv = document.getElementById('crm-import-csv').value;
    }
    try {
      const res = await Api.post('/import/apply', payload);
      const summary = res.data;
      const box = document.getElementById('crm-import-summary');
      const body = document.getElementById('crm-import-summary-body');
      box.hidden = false;
      body.innerHTML = '<p>' + t('Created %s, linked %s, agreements %s, skipped %s.', [
        String(summary.created),
        String(summary.linked),
        String(summary.agreements),
        String(summary.skipped),
      ]) + '</p>' +
        (summary.errors && summary.errors.length ? '<div class="crm-callout crm-callout--warning" role="status"><p>' + t('%s row error(s).', [String(summary.errors.length)]) + '</p></div>' : '');
      if (C && C.showToast) C.showToast({ type: 'success', message: t('Import applied') });
    } catch (e) {
      await C.alertDialog({ title: t('Apply failed'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    }
  });
})();
