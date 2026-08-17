/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';

function loadPageApi() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/inbox-page-core.js');
  const code = readFileSync(path, 'utf8');
  const module = { exports: {} };
  const globalThisObj = {};
  const context = {
    module,
    exports: module.exports,
    window: globalThisObj,
    globalThis: globalThisObj,
  };
  vm.runInNewContext(code, context);
  return module.exports.mergeInboxItems
    ? module.exports
    : globalThisObj.CustomerCheckInboxPage;
}

const Page = loadPageApi();

describe('inbox-page-core', () => {
  it('merges pages without duplicate ids and skips junk', () => {
    const a = [{ id: 3, subject: 'a' }, { id: 2, subject: 'b' }];
    const b = [{ id: 2, subject: 'dup' }, { id: 1, subject: 'c' }, { id: 0 }, null];
    const out = Page.mergeInboxItems(a, b);
    expect(out.map((r) => r.id)).toEqual([3, 2, 1]);
    expect(out[1].subject).toBe('b');
  });

  it('normalizes paging meta — hasMore requires a cursor', () => {
    expect(Page.normalizePagingMeta('2026-08-01 12:00:00|9', true)).toEqual({
      nextCursor: '2026-08-01 12:00:00|9',
      hasMore: true,
    });
    expect(Page.normalizePagingMeta('', true)).toEqual({ nextCursor: null, hasMore: false });
    expect(Page.normalizePagingMeta('2026-08-01 12:00:00|9', false)).toEqual({
      nextCursor: '2026-08-01 12:00:00|9',
      hasMore: false,
    });
  });

  it('shows Load more only for Personal/Team with loaded rows', () => {
    expect(Page.shouldShowLoadMore({ tab: 'mine', hasMore: true, loadedCount: 50 })).toBe(true);
    expect(Page.shouldShowLoadMore({ tab: 'team', hasMore: true, loadedCount: 1 })).toBe(true);
    expect(Page.shouldShowLoadMore({ tab: 'unmatched', hasMore: true, loadedCount: 50 })).toBe(false);
    expect(Page.shouldShowLoadMore({ tab: 'mine', hasMore: false, loadedCount: 50 })).toBe(false);
    expect(Page.shouldShowLoadMore({ tab: 'mine', hasMore: true, loadedCount: 0 })).toBe(false);
  });

  it('status text explains filtered empty vs more available', () => {
    const t = (m, args) => (args ? m.replace('%s', args[0]) : m);
    expect(Page.inboxStatusText({ count: 0, t })).toBe('No messages');
    expect(Page.inboxStatusText({
      count: 0,
      hasMore: true,
      tab: 'mine',
      filteredEmpty: true,
      t,
    })).toContain('load older');
    expect(Page.inboxStatusText({ count: 12, hasMore: true, tab: 'mine', t })).toContain('more available');
    expect(Page.inboxStatusText({ count: 1, hasMore: false, tab: 'mine', t })).toBe('1 message');
  });

  it('validates inbox cursors strictly', () => {
    expect(Page.looksLikeInboxCursor('2026-08-01 12:00:00|9')).toBe(true);
    expect(Page.looksLikeInboxCursor('|9')).toBe(true);
    expect(Page.looksLikeInboxCursor('2026-08-01T12:00:00|9')).toBe(false);
    expect(Page.looksLikeInboxCursor('x|9')).toBe(false);
    expect(Page.looksLikeInboxCursor('2026-08-01 12:00:00|0')).toBe(false);
    expect(Page.looksLikeInboxCursor('')).toBe(false);
  });
});
