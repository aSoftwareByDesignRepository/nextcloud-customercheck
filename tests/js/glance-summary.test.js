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
  moneyGlanceTone,
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
    expect(moneyGlanceTone('ok', false)).toBe('success');
    expect(moneyGlanceTone('ok', true)).toBe('warning');
    expect(moneyGlanceTone('attention', true)).toBe('warning');
    expect(moneyGlanceTone('critical', true)).toBe('danger');
    expect(moneyGlanceTone('unknown', true)).toBe('warning');
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

  it('fills %s via sprintfT when base t never sprintfs (window.t semantics)', () => {
    const rawT = (msg) => msg;
    const mid = renewalTileParts({ validUntil: '2026-09-01', daysLeft: 40 }, formatDisplayDate, rawT);
    expect(mid.meta).toBe('40 days left');
  });

  it('stays calm when runway is long', () => {
    const calm = renewalTileParts({ validUntil: '2027-01-01', daysLeft: 140 }, formatDisplayDate, t);
    expect(calm.tone).toBe('success');
  });
});

describe('buildGlanceSummaryTiles', () => {
  it('builds four calm tiles without repeating the company name', () => {
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

    expect(tiles).toHaveLength(4);
    expect(tiles.map((x) => x.key)).toEqual([
      'primary', 'nextAction', 'money', 'renewal',
    ]);
    expect(tiles[0].value).toBe('Ada');
    expect(tiles[0].meta).toBe('IT');
    expect(tiles[1].value).toBe('Reply to inquiry');
    expect(tiles[1].meta).toContain('Last talk');
    expect(tiles[1].meta).toContain('Reply to inquiry');
    expect(tiles[1].tone).toBe('primary');
    expect(tiles[2].value).toBe('Unknown');
    expect(tiles[3].value).toBe('—');
  });

  it('folds coverage gaps into money meta and flags missing primary', () => {
    const tiles = buildGlanceSummaryTiles({
      name: '',
      coverage: { gap: true, missing: ['champion'] },
      billing: { state: 'critical', overdueCount: 2 },
      nextAction: {},
    }, { t, formatDisplayDate });

    const byKey = Object.fromEntries(tiles.map((x) => [x.key, x]));
    expect(byKey.company).toBeUndefined();
    expect(byKey.coverage).toBeUndefined();
    expect(byKey.nextTouch).toBeUndefined();
    expect(byKey.lastDiscussion).toBeUndefined();
    expect(byKey.primary.value).toBe('No primary person');
    expect(byKey.primary.tone).toBe('warning');
    expect(byKey.nextAction.value).toBe('No urgent action');
    expect(byKey.nextAction.tone).toBe('success');
    expect(byKey.money.value).toBe('Overdue');
    expect(byKey.money.meta).toBe('2 overdue');
    expect(byKey.money.tone).toBe('danger');
  });

  it('puts Needs links on money meta when there is no overdue count', () => {
    const tiles = buildGlanceSummaryTiles({
      coverage: { gap: true, missingLabels: ['Champion'] },
      billing: { state: 'ok', overdueCount: 0 },
      nextAction: {},
    }, { t, formatDisplayDate });
    const money = tiles.find((x) => x.key === 'money');
    expect(money.meta).toBe('Needs links: Champion');
    expect(money.tone).toBe('warning');
    expect(money.value).toBe('Paid up');
  });

  it('keeps danger when overdue even if coverage also gaps', () => {
    const tiles = buildGlanceSummaryTiles({
      coverage: { gap: true, missing: ['champion'] },
      billing: { state: 'critical', overdueCount: 1 },
      nextAction: {},
    }, { t, formatDisplayDate });
    const money = tiles.find((x) => x.key === 'money');
    expect(money.tone).toBe('danger');
    expect(money.meta).toBe('1 overdue');
  });

  it('survives null / non-object DTO without throwing', () => {
    expect(() => buildGlanceSummaryTiles(null, { t, formatDisplayDate })).not.toThrow();
    expect(buildGlanceSummaryTiles(undefined, { t, formatDisplayDate })).toHaveLength(4);
  });
});
