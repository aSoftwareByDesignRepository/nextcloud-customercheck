/**
 * Browser bootstrap for glance-summary utils (classic script — no ESM in NC page shell).
 * Logic must stay aligned with js/utils/glance-summary.js (GlanceSummaryContractTest).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  function coverageMissingLabel(slot, t) {
    switch (slot) {
      case 'economic_buyer':
        return t('Budget decision-maker');
      case 'champion':
        return t('Champion');
      case 'day_to_day_or_technical':
        return t('Day-to-day or technical contact');
      default:
        return slot ? String(slot) : '';
    }
  }

  function billingStateLabel(state, t) {
    switch (state) {
      case 'ok':
        return t('Paid up');
      case 'attention':
        return t('Needs attention');
      case 'critical':
        return t('Overdue');
      default:
        return t('Unknown');
    }
  }

  function billingTone(state) {
    switch (state) {
      case 'ok':
        return 'success';
      case 'attention':
        return 'warning';
      case 'critical':
        return 'danger';
      default:
        return 'neutral';
    }
  }

  function coverageTileParts(cov, t) {
    const c = cov && typeof cov === 'object' ? cov : {};
    const label = c.gap ? t('Needs links') : (c.covered ? t('Covered') : t('Coverage'));
    const tone = c.gap ? 'warning' : (c.covered ? 'success' : 'neutral');
    let meta = '';
    if (Array.isArray(c.missingLabels) && c.missingLabels.length) {
      meta = c.missingLabels.map(function (x) { return String(x || '').trim(); }).filter(Boolean).join(', ');
    } else if (Array.isArray(c.missing) && c.missing.length) {
      meta = c.missing.map(function (slot) { return coverageMissingLabel(slot, t); }).filter(Boolean).join(', ');
    }
    return { label: label, meta: meta, tone: tone };
  }

  function renewalTileParts(runway, formatDisplayDate, t) {
    const r = runway && typeof runway === 'object' ? runway : {};
    if (!r.validUntil) {
      return { value: '—', meta: '', tone: 'neutral' };
    }
    const value = formatDisplayDate(String(r.validUntil));
    const days = r.daysLeft;
    let meta = '';
    let tone = 'neutral';
    if (days != null && Number.isFinite(Number(days))) {
      const n = Number(days);
      meta = t('%s days left', [String(n)]);
      if (n <= 14) {
        tone = 'warning';
      } else if (n > 60) {
        tone = 'success';
      }
    }
    return { value: value, meta: meta, tone: tone };
  }

  function sanitizeGlanceTone(tone) {
    return ['primary', 'success', 'warning', 'danger', 'neutral'].indexOf(tone) !== -1
      ? tone
      : 'neutral';
  }

  function buildGlanceSummaryTiles(dto, helpers) {
    const t = helpers.t;
    const formatDisplayDate = helpers.formatDisplayDate;
    const d = dto && typeof dto === 'object' ? dto : {};
    const next = (d.nextAction && typeof d.nextAction === 'object') ? d.nextAction : {};
    const last = (d.lastDiscussion && typeof d.lastDiscussion === 'object') ? d.lastDiscussion : {};
    const billing = (d.billing && typeof d.billing === 'object') ? d.billing : {};
    const primary = (d.primaryPerson && typeof d.primaryPerson === 'object') ? d.primaryPerson : null;
    const cov = coverageTileParts(d.coverage, t);
    const renewal = renewalTileParts(d.runway, formatDisplayDate, t);
    const nextTouch = d.nextTouchOn ? String(d.nextTouchOn) : '';
    const billingState = billing.state ? String(billing.state) : 'unknown';
    const overdue = Number(billing.overdueCount || 0);

    let primaryValue = t('No primary person');
    let primaryMeta = '';
    if (primary) {
      primaryValue = String(primary.name || '').trim() || t('No primary person');
      primaryMeta = primary.role ? String(primary.role) : '';
    }

    let lastValue = t('Nothing logged yet');
    let lastMeta = '';
    if (last.summary) {
      lastValue = String(last.summary);
      lastMeta = last.at ? formatDisplayDate(String(last.at)) : '';
    }

    const moneyMeta = overdue > 0
      ? (String(overdue) + ' ' + t('overdue'))
      : '';

    return [
      {
        key: 'company',
        label: t('Company'),
        value: String(d.name || '').trim() || '—',
        meta: '',
        tone: 'primary',
      },
      {
        key: 'primary',
        label: t('Primary contact'),
        value: primaryValue,
        meta: primaryMeta,
        tone: primary && primaryValue !== t('No primary person') ? 'neutral' : 'warning',
      },
      {
        key: 'coverage',
        label: t('Coverage'),
        value: cov.label,
        meta: cov.meta,
        tone: cov.tone,
      },
      {
        key: 'nextTouch',
        label: t('Next touch'),
        value: nextTouch ? formatDisplayDate(nextTouch) : t('Not scheduled'),
        meta: '',
        tone: nextTouch ? 'neutral' : 'warning',
      },
      {
        key: 'lastDiscussion',
        label: t('Last discussion'),
        value: lastValue,
        meta: lastMeta,
        tone: 'neutral',
      },
      {
        key: 'nextAction',
        label: t('Next action'),
        value: next.label ? String(next.label) : t('No urgent action'),
        meta: '',
        tone: next.label ? 'primary' : 'success',
      },
      {
        key: 'money',
        label: t('Money'),
        value: billingStateLabel(billingState, t),
        meta: moneyMeta,
        tone: billingTone(billingState),
      },
      {
        key: 'renewal',
        label: t('Renewal'),
        value: renewal.value,
        meta: renewal.meta,
        tone: renewal.tone,
      },
    ];
  }

  /**
   * XSS-safe mount: textContent only. Preserves .crm-glance-dl for e2e / contracts.
   * Uses div/span tiles (not dt/dd) so meta lines stay WCAG-valid — axe rejects
   * div>p inside dl wrappers (definition-list / only-dlitems).
   */
  function renderGlanceSummaryTiles(body, tiles, t) {
    if (!body) {
      return;
    }
    const strip = document.createElement('div');
    strip.className = 'crm-glance-dl crm-stat-strip';
    strip.setAttribute('role', 'list');
    strip.setAttribute('aria-label', t('At a glance'));

    (Array.isArray(tiles) ? tiles : []).forEach(function (tile) {
      if (!tile || typeof tile !== 'object') {
        return;
      }
      const tone = sanitizeGlanceTone(tile.tone);
      const wrap = document.createElement('div');
      wrap.className = 'crm-stat-tile crm-stat-tile--' + tone;
      wrap.setAttribute('role', 'listitem');

      const label = document.createElement('span');
      label.className = 'crm-stat-tile__label';
      label.textContent = String(tile.label || '');

      const value = document.createElement('span');
      value.className = 'crm-stat-tile__value';
      value.textContent = String(tile.value || '—');

      wrap.appendChild(label);
      wrap.appendChild(value);

      const meta = String(tile.meta || '').trim();
      if (meta) {
        const metaEl = document.createElement('span');
        metaEl.className = 'crm-stat-tile__meta';
        metaEl.textContent = meta;
        wrap.appendChild(metaEl);
      }

      strip.appendChild(wrap);
    });

    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
    body.appendChild(strip);
  }

  global.CustomerCheckGlanceSummary = {
    coverageMissingLabel: coverageMissingLabel,
    billingStateLabel: billingStateLabel,
    billingTone: billingTone,
    coverageTileParts: coverageTileParts,
    renewalTileParts: renewalTileParts,
    sanitizeGlanceTone: sanitizeGlanceTone,
    buildGlanceSummaryTiles: buildGlanceSummaryTiles,
    renderGlanceSummaryTiles: renderGlanceSummaryTiles,
  };
}(typeof window !== 'undefined' ? window : globalThis));
