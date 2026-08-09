/**
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import {
  billingStateLabel,
  billingTone,
  buildGlanceSummaryTiles,
  coverageMissingLabel,
  coverageTileParts,
  renewalTileParts,
  sanitizeGlanceTone,
} from '../../js/utils/glance-summary.js';

const t = (msg, args) => {
  if (!args || !args.length) {
    return msg;
  }
  return msg.replace('%s', args[0]);
};

const formatDisplayDate = (iso) => `D:${iso}`;

describe('coverageMissingLabel / billing helpers', () => {
  it('maps buying-role slots to granny labels and never invents Gap jargon', () => {
    expect(coverageMissingLabel('economic_buyer', t)).toBe('Budget decision-maker');
    expect(coverageMissingLabel('champion', t)).toBe('Champion');
    expect(coverageMissingLabel('day_to_day_or_technical', t)).toBe('Day-to-day or technical contact');
    expect(coverageMissingLabel('', t)).toBe('');
    expect(coverageMissingLabel(null, t)).toBe('');
  });

  it('maps billing states to labels and tones', () => {
    expect(billingStateLabel('ok', t)).toBe('Paid up');
    expect(billingStateLabel('attention', t)).toBe('Needs attention');
    expect(billingStateLabel('critical', t)).toBe('Overdue');
    expect(billingStateLabel('weird', t)).toBe('Unknown');
    expect(billingTone('ok')).toBe('success');
    expect(billingTone('attention')).toBe('warning');
    expect(billingTone('critical')).toBe('danger');
    expect(billingTone('')).toBe('neutral');
  });

  it('sanitizes unknown tile tones', () => {
    expect(sanitizeGlanceTone('primary')).toBe('primary');
    expect(sanitizeGlanceTone('hacked')).toBe('neutral');
    expect(sanitizeGlanceTone(null)).toBe('neutral');
  });
});

describe('coverageTileParts', () => {
  it('prefers Needs links over Gap and uses missingLabels when present', () => {
    const gap = coverageTileParts({
      gap: true,
      covered: false,
      missingLabels: ['Champion', 'Budget decision-maker'],
      missing: ['champion'],
    }, t);
    expect(gap.label).toBe('Needs links');
    expect(gap.tone).toBe('warning');
    expect(gap.meta).toBe('Champion, Budget decision-maker');
  });

  it('falls back to slot keys via coverageMissingLabel', () => {
    const gap = coverageTileParts({
      gap: true,
      missing: ['champion', 'economic_buyer'],
    }, t);
    expect(gap.meta).toBe('Champion, Budget decision-maker');
  });

  it('marks covered accounts success without meta', () => {
    const ok = coverageTileParts({ gap: false, covered: true }, t);
    expect(ok.label).toBe('Covered');
    expect(ok.tone).toBe('success');
    expect(ok.meta).toBe('');
  });
});

describe('renewalTileParts', () => {
  it('returns em dash when no validUntil', () => {
    expect(renewalTileParts({}, formatDisplayDate, t)).toEqual({
      value: '—',
      meta: '',
      tone: 'neutral',
    });
  });

  it('warns when renewal is within 14 days', () => {
    const hot = renewalTileParts({ validUntil: '2026-08-20', daysLeft: 11 }, formatDisplayDate, t);
    expect(hot.value).toBe('D:2026-08-20');
    expect(hot.meta).toBe('11 days left');
    expect(hot.tone).toBe('warning');
  });

  it('stays calm when runway is long', () => {
    const calm = renewalTileParts({ validUntil: '2027-01-01', daysLeft: 140 }, formatDisplayDate, t);
    expect(calm.tone).toBe('success');
  });
});

describe('buildGlanceSummaryTiles', () => {
  it('builds eight tiles with XSS-safe string values from a full DTO', () => {
    const tiles = buildGlanceSummaryTiles({
      name: '<Acme>',
      nextTouchOn: '2026-08-15',
      primaryPerson: { name: 'Ada', role: 'IT' },
      coverage: { gap: false, covered: true },
      lastDiscussion: { at: '2026-08-08T02:00:00+00:00', summary: 'Reply to inquiry' },
      nextAction: { label: 'Reply to inquiry' },
      billing: { state: 'unknown', overdueCount: 0 },
      runway: { validUntil: null },
    }, { t, formatDisplayDate });

    expect(tiles).toHaveLength(8);
    expect(tiles.map((x) => x.key)).toEqual([
      'company', 'primary', 'coverage', 'nextTouch', 'lastDiscussion', 'nextAction', 'money', 'renewal',
    ]);
    expect(tiles[0].value).toBe('<Acme>');
    expect(tiles[0].tone).toBe('primary');
    expect(tiles[1].value).toBe('Ada');
    expect(tiles[1].meta).toBe('IT');
    expect(tiles[2].value).toBe('Covered');
    expect(tiles[3].value).toBe('D:2026-08-15');
    expect(tiles[4].value).toBe('Reply to inquiry');
    expect(tiles[4].meta).toBe('D:2026-08-08T02:00:00+00:00');
    expect(tiles[5].value).toBe('Reply to inquiry');
    expect(tiles[5].tone).toBe('primary');
    expect(tiles[6].value).toBe('Unknown');
    expect(tiles[7].value).toBe('—');
  });

  it('flags missing primary, coverage gaps, unscheduled touch, and overdue money', () => {
    const tiles = buildGlanceSummaryTiles({
      name: '',
      coverage: { gap: true, missing: ['champion'] },
      billing: { state: 'critical', overdueCount: 2 },
      nextAction: {},
    }, { t, formatDisplayDate });

    const byKey = Object.fromEntries(tiles.map((x) => [x.key, x]));
    expect(byKey.company.value).toBe('—');
    expect(byKey.primary.value).toBe('No primary person');
    expect(byKey.primary.tone).toBe('warning');
    expect(byKey.coverage.value).toBe('Needs links');
    expect(byKey.coverage.meta).toBe('Champion');
    expect(byKey.coverage.value).not.toBe('Gap');
    expect(byKey.nextTouch.value).toBe('Not scheduled');
    expect(byKey.nextTouch.tone).toBe('warning');
    expect(byKey.nextAction.value).toBe('No urgent action');
    expect(byKey.nextAction.tone).toBe('success');
    expect(byKey.money.value).toBe('Overdue');
    expect(byKey.money.meta).toBe('2 overdue');
    expect(byKey.money.tone).toBe('danger');
  });

  it('survives null / non-object DTO without throwing', () => {
    expect(() => buildGlanceSummaryTiles(null, { t, formatDisplayDate })).not.toThrow();
    expect(buildGlanceSummaryTiles(undefined, { t, formatDisplayDate })).toHaveLength(8);
  });
});
