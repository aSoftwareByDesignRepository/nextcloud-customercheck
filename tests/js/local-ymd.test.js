import { describe, expect, it } from 'vitest';
import { localYmd, utcYmd } from '../../js/utils/local-ymd.js';

describe('localYmd', () => {
  it('uses local calendar parts (not UTC)', () => {
    // 2026-08-07 02:00 local — same as agreement-form todayIso contract
    expect(localYmd(new Date(2026, 7, 7, 2, 0, 0))).toBe('2026-08-07');
  });

  it('stays on the local day even when UTC is already previous day', () => {
    // 00:30 local — east of UTC this is still the previous calendar day in UTC.
    const early = new Date(2026, 8, 13, 0, 30, 0);
    expect(localYmd(early)).toBe('2026-09-13');
    const isoDay = early.toISOString().slice(0, 10);
    if (early.getTimezoneOffset() < 0) {
      expect(isoDay).toBe('2026-09-12');
      expect(isoDay).not.toBe(localYmd(early));
    }
  });
});

describe('utcYmd', () => {
  it('formats UTC calendar parts from Date.UTC', () => {
    const d = new Date(Date.UTC(2026, 8, 13));
    expect(utcYmd(d)).toBe('2026-09-13');
  });
});
