/**
 * Browser bootstrap for company360-present utils.
 * Keep aligned with js/utils/company360-present.js (Company360PageContractTest).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  function safeInternalHref(href, fallback) {
    const fb = fallback == null ? '/' : String(fallback);
    const s = String(href == null ? '' : href).trim();
    if (!s) {
      return fb;
    }
    const lower = s.toLowerCase();
    if (lower.indexOf('javascript:') === 0 || lower.indexOf('data:') === 0 || lower.indexOf('vbscript:') === 0) {
      return fb;
    }
    if (s.charAt(0) !== '/' || s.indexOf('//') === 0 || s.indexOf('\\') !== -1) {
      return fb;
    }
    return s;
  }

  function buildBillingHonesty(billing, t) {
    const b = billing && typeof billing === 'object' ? billing : {};
    const sources = b.sources && typeof b.sources === 'object' ? b.sources : {};
    const licenses = b.licenses && typeof b.licenses === 'object' ? b.licenses : {};
    const mode = String(b.sourceMode || 'none');
    const icAvail = !!sources.invoiceCheckAvailable;
    const pcAvail = !!sources.projectCheckAvailable;
    const icLinked = !!sources.invoiceCheckLinked;
    const licenseTotal = Math.max(0, Number(licenses.total || 0) || 0);
    const licenseNone = Math.max(0, Number(licenses.none || 0) || 0);
    const licenseUnpaid = Math.max(0, Number(licenses.unpaid || 0) || 0);

    if (mode === 'invoicecheck') {
      return {
        tone: 'neutral',
        text: t('Numbers come from InvoiceCheck for this linked customer.'),
      };
    }
    if (mode === 'licenses_fallback') {
      return {
        tone: 'warning',
        text: t('InvoiceCheck has no open invoices — showing license payment status instead.'),
      };
    }
    if (mode === 'licenses') {
      return {
        tone: 'info',
        text: t('InvoiceCheck is off or not linked — showing license payment status.'),
      };
    }
    if (licenseTotal > 0 && licenseNone === licenseTotal) {
      return {
        tone: 'warning',
        text: t('Licenses are here, but invoice status is still “none”. Open Licenses & renewals to mark sent, paid, or overdue.'),
      };
    }
    if (!icAvail && !pcAvail && licenseTotal === 0) {
      return {
        tone: 'info',
        text: t('No InvoiceCheck / ProjectCheck and no licenses yet. Add a license to track payment here.'),
      };
    }
    if (!icAvail && !icLinked) {
      return {
        tone: 'info',
        text: pcAvail
          ? t('InvoiceCheck is not available. ProjectCheck hours may still show when linked. Add licenses to track payment.')
          : t('InvoiceCheck and ProjectCheck are not available. Track payment on licenses.'),
      };
    }
    if (icAvail && !icLinked) {
      return {
        tone: 'warning',
        text: t('InvoiceCheck is on — link ProjectCheck on this customer to load invoices.'),
      };
    }
    if (licenseUnpaid > 0) {
      return {
        tone: 'warning',
        text: t('%s license invoice(s) still unpaid.', [String(licenseUnpaid)]),
      };
    }
    return {
      tone: 'neutral',
      text: t('Overview from linked apps and licenses — not a tax audit.'),
    };
  }

  function buildBillingSummaryTiles(billing, helpers) {
    const t = helpers.t;
    const b = billing && typeof billing === 'object' ? billing : {};
    const state = b.state ? String(b.state) : 'unknown';
    const overdue = Math.max(0, Number(b.overdueCount || 0) || 0);
    const open = Math.max(0, Number(b.openCount || 0) || 0);
    const licenseUnpaid = Math.max(0, Number((b.licenses && b.licenses.unpaid) || 0) || 0);
    const tone = helpers.billingTone(state);
    const tiles = [
      {
        key: 'status',
        label: t('Status'),
        value: helpers.billingStateLabel(state),
        meta: '',
        tone: tone,
      },
      {
        key: 'overdue',
        label: t('Overdue'),
        value: String(overdue),
        meta: overdue > 0 ? t('Needs collection') : t('None overdue'),
        tone: overdue > 0 ? 'danger' : 'success',
      },
      {
        key: 'open',
        label: t('Open invoices'),
        value: String(open),
        meta: open > 0 ? t('Still unpaid') : t('Nothing open'),
        tone: open > 0 ? 'warning' : 'neutral',
      },
    ];
    if (licenseUnpaid > 0 || (b.licenses && Number(b.licenses.total || 0) > 0)) {
      tiles.push({
        key: 'licenses',
        label: t('License invoices'),
        value: String(licenseUnpaid),
        meta: licenseUnpaid > 0 ? t('Unpaid on licenses') : t('Licenses paid or unset'),
        tone: licenseUnpaid > 0 ? 'warning' : 'neutral',
      });
    }
    return tiles;
  }

  /**
   * XSS-safe billing strip + honesty into #crm-billing-body.
   */
  function renderBillingSummaryTiles(body, tiles, t, billing) {
    if (!body) {
      return;
    }
    const wrap = document.createElement('div');
    wrap.className = 'crm-billing-panel';

    const strip = document.createElement('div');
    strip.className = 'crm-stat-strip crm-billing-strip';
    strip.setAttribute('role', 'list');
    strip.setAttribute('aria-label', t('Money & invoicing'));

    (Array.isArray(tiles) ? tiles : []).forEach(function (tile) {
      if (!tile || typeof tile !== 'object') {
        return;
      }
      const allowed = ['primary', 'success', 'warning', 'danger', 'neutral'];
      const tone = allowed.indexOf(tile.tone) !== -1 ? tile.tone : 'neutral';
      const art = document.createElement('article');
      art.className = 'crm-stat-tile crm-stat-tile--' + tone;
      art.setAttribute('role', 'listitem');
      if (tile.key) {
        art.setAttribute('data-crm-billing-tile', String(tile.key));
      }

      const label = document.createElement('span');
      label.className = 'crm-stat-tile__label';
      label.textContent = String(tile.label || '');

      const value = document.createElement('span');
      value.className = 'crm-stat-tile__value';
      value.textContent = String(tile.value || '—');

      art.appendChild(label);
      art.appendChild(value);

      const meta = String(tile.meta || '').trim();
      if (meta) {
        const metaEl = document.createElement('span');
        metaEl.className = 'crm-stat-tile__meta';
        metaEl.textContent = meta;
        art.appendChild(metaEl);
      }
      strip.appendChild(art);
    });
    wrap.appendChild(strip);

    const honesty = buildBillingHonesty(billing, t);
    if (honesty && honesty.text) {
      const callout = document.createElement('div');
      const tone = honesty.tone === 'warning' ? 'warning' : (honesty.tone === 'info' ? 'info' : 'neutral');
      callout.className = 'crm-callout crm-callout--' + (tone === 'neutral' ? 'info' : tone) + ' crm-billing-honesty';
      callout.setAttribute('role', 'status');
      const p = document.createElement('p');
      p.className = 'crm-billing-honesty__text';
      p.textContent = honesty.text;
      callout.appendChild(p);
      wrap.appendChild(callout);
    }

    const actions = document.createElement('div');
    actions.className = 'crm-inline-actions crm-billing-actions';
    const licensesLink = document.createElement('a');
    licensesLink.className = 'crm-btn crm-btn--secondary crm-btn--sm';
    licensesLink.href = '#crm-contracts';
    licensesLink.textContent = t('Licenses & renewals');
    actions.appendChild(licensesLink);

    const linkApps = document.createElement('a');
    linkApps.className = 'crm-btn crm-btn--ghost crm-btn--sm';
    linkApps.href = '#crm-identity-links';
    linkApps.textContent = t('Link apps');
    actions.appendChild(linkApps);
    wrap.appendChild(actions);

    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
    body.appendChild(wrap);
  }

  global.CustomerCheckCompany360Present = {
    safeInternalHref: safeInternalHref,
    buildBillingHonesty: buildBillingHonesty,
    buildBillingSummaryTiles: buildBillingSummaryTiles,
    renderBillingSummaryTiles: renderBillingSummaryTiles,
  };
}(typeof window !== 'undefined' ? window : globalThis));
