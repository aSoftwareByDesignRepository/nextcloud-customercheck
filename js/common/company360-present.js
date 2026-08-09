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

  function buildBillingSummaryTiles(billing, helpers) {
    const t = helpers.t;
    const b = billing && typeof billing === 'object' ? billing : {};
    const state = b.state ? String(b.state) : 'unknown';
    const overdue = Math.max(0, Number(b.overdueCount || 0) || 0);
    const open = Math.max(0, Number(b.openCount || 0) || 0);
    const tone = helpers.billingTone(state);
    return [
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
  }

  /**
   * XSS-safe billing strip into #crm-billing-body.
   */
  function renderBillingSummaryTiles(body, tiles, t) {
    if (!body) {
      return;
    }
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

    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
    body.appendChild(strip);
  }

  global.CustomerCheckCompany360Present = {
    safeInternalHref: safeInternalHref,
    buildBillingSummaryTiles: buildBillingSummaryTiles,
    renderBillingSummaryTiles: renderBillingSummaryTiles,
  };
}(typeof window !== 'undefined' ? window : globalThis));
