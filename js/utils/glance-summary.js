/**
 * Pure “At a glance” summary tiles for Company 360 command DTO.
 * Shared by vitest + company-detail.js (keep strings in sync via GlanceSummaryContractTest).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * window.t / OC.L10N.translate fills {name} tokens only — %s/%d pass
 * through raw. Wrap an injected translator so leftover sprintf
 * placeholders are always filled (idempotent when already filled).
 * @param {unknown} base
 * @returns {(msg: string, args?: string[]) => string}
 */
export function sprintfT(base) {
  const baseT = typeof base === 'function' ? base : function (m) { return m; };
  return function (msg, args) {
    let out = baseT(msg, args);
    const list = Array.isArray(args) ? args : [];
    if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
      let i = 0;
      out = out.replace(/%%|%(?:\d+\$)?[sd]/g, function (m) {
        if (m === '%%') { return '%'; }
        const v = list[i];
        i += 1;
        return v == null ? '' : String(v);
      });
    }
    return out;
  };
}

/**
 * @param {string|null|undefined} slot
 * @param {(msg: string, args?: string[]) => string} t
 * @returns {string}
 */
export function coverageMissingLabel(slot, t) {
  t = sprintfT(t);
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

/**
 * @param {string|null|undefined} state
 * @param {(msg: string, args?: string[]) => string} t
 * @returns {string}
 */
export function billingStateLabel(state, t) {
  t = sprintfT(t);
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

/**
 * @param {string|null|undefined} state
 * @returns {'success'|'warning'|'danger'|'neutral'}
 */
export function billingTone(state) {
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

/**
 * Money tile tone: billing severity wins on overdue; coverage gap must never
 * hide under Paid-up success (granny must see “Needs links”).
 *
 * @param {string|null|undefined} billingState
 * @param {boolean} coverageGap
 * @returns {'success'|'warning'|'danger'|'neutral'}
 */
export function moneyGlanceTone(billingState, coverageGap) {
  const bill = billingTone(billingState);
  if (!coverageGap) {
    return bill;
  }
  return bill === 'danger' ? 'danger' : 'warning';
}

/**
 * @param {{ gap?: boolean, covered?: boolean, missingLabels?: string[], missing?: string[] }|null|undefined} cov
 * @param {(msg: string, args?: string[]) => string} t
 * @returns {{ label: string, meta: string, tone: 'success'|'warning'|'neutral' }}
 */
export function coverageTileParts(cov, t) {
  t = sprintfT(t);
  const c = cov && typeof cov === 'object' ? cov : {};
  const label = c.gap ? t('Needs links') : (c.covered ? t('Covered') : t('Coverage'));
  const tone = c.gap ? 'warning' : (c.covered ? 'success' : 'neutral');
  let meta = '';
  if (Array.isArray(c.missingLabels) && c.missingLabels.length) {
    meta = c.missingLabels.map((x) => String(x || '').trim()).filter(Boolean).join(', ');
  } else if (Array.isArray(c.missing) && c.missing.length) {
    meta = c.missing.map((slot) => coverageMissingLabel(slot, t)).filter(Boolean).join(', ');
  }
  return { label, meta, tone };
}

/**
 * @param {{ validUntil?: string|null, daysLeft?: number|null }|null|undefined} runway
 * @param {(iso: string) => string} formatDisplayDate
 * @param {(msg: string, args?: string[]) => string} t
 * @returns {{ value: string, meta: string, tone: 'warning'|'neutral'|'success' }}
 */
export function renewalTileParts(runway, formatDisplayDate, t) {
  t = sprintfT(t);
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
  return { value, meta, tone };
}

/**
 * Build granny-readable KPI tiles from CompanyCommandService DTO.
 *
 * @param {Record<string, unknown>|null|undefined} dto
 * @param {{
 *   t: (msg: string, args?: string[]) => string,
 *   formatDisplayDate: (iso: string) => string,
 * }} helpers
 * @returns {Array<{
 *   key: string,
 *   label: string,
 *   value: string,
 *   meta: string,
 *   tone: 'primary'|'success'|'warning'|'danger'|'neutral',
 * }>}
 */
export function buildGlanceSummaryTiles(dto, helpers) {
  const t = sprintfT(helpers.t);
  const formatDisplayDate = helpers.formatDisplayDate;
  const d = dto && typeof dto === 'object' ? dto : {};
  const next = (d.nextAction && typeof d.nextAction === 'object') ? d.nextAction : {};
  const last = (d.lastDiscussion && typeof d.lastDiscussion === 'object') ? d.lastDiscussion : {};
  const billing = (d.billing && typeof d.billing === 'object') ? d.billing : {};
  const primary = (d.primaryPerson && typeof d.primaryPerson === 'object') ? d.primaryPerson : null;
  const coverageRaw = (d.coverage && typeof d.coverage === 'object') ? d.coverage : {};
  const cov = coverageTileParts(coverageRaw, t);
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

  let moneyMeta = overdue > 0
    ? (String(overdue) + ' ' + t('overdue'))
    : '';
  if (!moneyMeta && coverageRaw.gap) {
    moneyMeta = cov.meta ? (cov.label + ': ' + cov.meta) : cov.label;
  }

  return [
    {
      key: 'primary',
      label: t('Primary contact'),
      value: primaryValue,
      meta: primaryMeta,
      tone: primary && primaryValue !== t('No primary person') ? 'neutral' : 'warning',
    },
    {
      key: 'nextAction',
      label: t('Next action'),
      value: next.label ? String(next.label) : t('No urgent action'),
      meta: last.summary
        ? (t('Last talk') + ': ' + String(last.summary) + (last.at ? ' · ' + formatDisplayDate(String(last.at)) : ''))
        : (nextTouch ? (t('Next touch') + ': ' + formatDisplayDate(nextTouch)) : ''),
      tone: next.label ? 'primary' : 'success',
    },
    {
      key: 'money',
      label: t('Money'),
      value: billingStateLabel(billingState, t),
      meta: moneyMeta,
      tone: moneyGlanceTone(billingState, !!coverageRaw.gap),
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
 * Allowed left-accent tones for crm-stat-tile (never invent unknown modifiers).
 * @param {unknown} tone
 * @returns {'primary'|'success'|'warning'|'danger'|'neutral'}
 */
export function sanitizeGlanceTone(tone) {
  return ['primary', 'success', 'warning', 'danger', 'neutral'].includes(tone)
    ? /** @type {'primary'|'success'|'warning'|'danger'|'neutral'} */ (tone)
    : 'neutral';
}
