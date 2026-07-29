import { describe, expect, it } from 'vitest';
import {
  canApplyScrollSpy,
  computeScrollTop,
  hashHrefToId,
  pickMostVisibleId,
} from '../../js/common/jump-nav-core.js';

describe('hashHrefToId', () => {
  it('accepts simple fragment ids', () => {
    expect(hashHrefToId('#crm-timeline')).toBe('crm-timeline');
    expect(hashHrefToId('#crm-tickets')).toBe('crm-tickets');
  });

  it('rejects empty, relative, or unsafe fragments', () => {
    expect(hashHrefToId('')).toBeNull();
    expect(hashHrefToId('#')).toBeNull();
    expect(hashHrefToId('crm-timeline')).toBeNull();
    expect(hashHrefToId('https://evil.example/#x')).toBeNull();
    expect(hashHrefToId('#crm timeline')).toBeNull();
    expect(hashHrefToId('#a#b')).toBeNull();
    expect(hashHrefToId('#foo/bar')).toBeNull();
    expect(hashHrefToId('#%61')).toBeNull();
    expect(hashHrefToId(null)).toBeNull();
  });
});

describe('pickMostVisibleId', () => {
  it('returns the id with the highest positive ratio', () => {
    expect(
      pickMostVisibleId([
        { id: 'a', ratio: 0.1 },
        { id: 'b', ratio: 0.8 },
        { id: 'c', ratio: 0.4 },
      ]),
    ).toBe('b');
  });

  it('returns null when nothing is visible', () => {
    expect(pickMostVisibleId([{ id: 'a', ratio: 0 }, { id: 'b', ratio: 0 }])).toBeNull();
    expect(pickMostVisibleId([])).toBeNull();
    expect(pickMostVisibleId(null)).toBeNull();
  });

  it('skips malformed rows', () => {
    expect(
      pickMostVisibleId([
        { id: 'a', ratio: 0.2 },
        null,
        { id: 1, ratio: 0.9 },
        { id: 'b', ratio: 0.5 },
      ]),
    ).toBe('b');
  });
});

describe('canApplyScrollSpy', () => {
  it('blocks updates while locked', () => {
    expect(canApplyScrollSpy(1000, 1500)).toBe(false);
    expect(canApplyScrollSpy(1500, 1500)).toBe(true);
    expect(canApplyScrollSpy(1600, 1500)).toBe(true);
  });
});

describe('computeScrollTop', () => {
  it('offsets for sticky bar and never goes negative', () => {
    expect(computeScrollTop(100, 400, 50, 80)).toBe(370);
    expect(computeScrollTop(0, 40, 50, 80)).toBe(0);
  });
});
