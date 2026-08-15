/**
 * Inbox Unassigned mapping helpers — meaningful unit coverage (mutants must die).
 */
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const code = readFileSync(join(root, 'js/common/inbox-assign-map.js'), 'utf8');
const sandbox = {
  module: { exports: {} },
  globalThis: {},
};
sandbox.window = sandbox.globalThis;
runInContext(code, createContext(sandbox));
const M = sandbox.module.exports;

describe('CustomerCheckInboxAssignMap', () => {
  it('exports helpers onto module.exports and global', () => {
    expect(typeof M.normalizeAssignKind).toBe('function');
    expect(sandbox.globalThis.CustomerCheckInboxAssignMap).toBe(M);
  });

  it('normalizes legacy mail kinds to virtual mail', () => {
    expect(M.normalizeAssignKind('shared_mail')).toBe('mail');
    expect(M.normalizeAssignKind('personal_mail_shared_unmatched')).toBe('mail');
    expect(M.normalizeAssignKind('mail')).toBe('mail');
    expect(M.normalizeAssignKind('')).toBe('mail');
    expect(M.normalizeAssignKind(null)).toBe('mail');
    expect(M.normalizeAssignKind('contact_suggest')).toBe('contact_suggest');
  });

  it('recognizes only shared mail source kinds', () => {
    expect(M.isMailSourceKind('shared_mail')).toBe(true);
    expect(M.isMailSourceKind('personal_mail_shared_unmatched')).toBe(true);
    expect(M.isMailSourceKind('mail')).toBe(false);
    expect(M.isMailSourceKind('file')).toBe(false);
    expect(M.isMailSourceKind('')).toBe(false);
  });

  it('maps dual ids and keeps private personal out of hasMail unless shared kind', () => {
    const row = M.mapReviewItemToRow({
      id: 42,
      subject: 'Hello',
      snippet: 'Body',
      sourceKind: 'shared_mail',
      sentAt: '2026-08-01 10:00:00',
      suggestions: [{ companyId: 9, companyName: 'Acme', score: 88 }],
      sourceRef: { mailMsgId: 7, from: 'a@example.com' },
    }, { teamMail: 'Team mail', personalShared: 'Personal shared' });

    expect(row.id).toBe(7);
    expect(row.reviewItemId).toBe(42);
    expect(row.from).toBe('a@example.com');
    expect(row.hasMail).toBe(true);
    expect(row.mailboxLabel).toBe('Team mail');
    expect(row.suggestions[0].companyId).toBe(9);
    expect(M.isUnifiedMailAssignRow(row)).toBe(true);
  });

  it('labels personal shared unmatched distinctly', () => {
    const row = M.mapReviewItemToRow({
      id: 3,
      sourceKind: 'personal_mail_shared_unmatched',
      sourceRef: { mailMsgId: 11, from: 'b@x.test' },
      suggestions: [],
    }, { personalShared: 'Personal shared', teamMail: 'Team mail' });
    expect(row.mailboxLabel).toBe('Personal shared');
    expect(row.hasMail).toBe(true);
  });

  it('rejects rows without mailMsgId (no preview/reply path)', () => {
    const row = M.mapReviewItemToRow({
      id: 5,
      sourceKind: 'shared_mail',
      sourceRef: { from: 'x@y.z' },
      suggestions: [],
    });
    expect(row.id).toBe(0);
    expect(row.hasMail).toBe(false);
    expect(M.isUnifiedMailAssignRow(row)).toBe(false);
  });

  it('rejects non-mail kinds even with a mailMsgId in ref', () => {
    const row = M.mapReviewItemToRow({
      id: 8,
      sourceKind: 'file',
      sourceRef: { mailMsgId: 99 },
      suggestions: [],
    });
    expect(row.hasMail).toBe(false);
    expect(M.isUnifiedMailAssignRow(row)).toBe(false);
  });

  it('falls back sentAt to createdAt', () => {
    const row = M.mapReviewItemToRow({
      id: 1,
      sourceKind: 'shared_mail',
      createdAt: '2026-01-02 03:04:05',
      sourceRef: { mailMsgId: 2 },
    });
    expect(row.sentAt).toBe('2026-01-02 03:04:05');
  });

  it('isUnifiedMailAssignRow rejects missing reviewItemId', () => {
    expect(M.isUnifiedMailAssignRow({ hasMail: true, id: 1, reviewItemId: 0 })).toBe(false);
    expect(M.isUnifiedMailAssignRow({ hasMail: true, id: 1, reviewItemId: 2 })).toBe(true);
  });

  it('reviewApiKind keeps team/personal filters (does not collapse to mail)', () => {
    expect(M.reviewApiKind('shared_mail')).toBe('shared_mail');
    expect(M.reviewApiKind('personal_mail_shared_unmatched')).toBe('personal_mail_shared_unmatched');
    expect(M.reviewApiKind('mail')).toBe('mail');
    expect(M.reviewApiKind('nope')).toBe('mail');
  });

  it('sortMailRows sorts by date/from/subject and toggles direction', () => {
    const rows = [
      { id: 1, from: 'b@x.test', subject: 'Zebra', sentAt: '2026-01-01 10:00:00' },
      { id: 2, from: 'a@x.test', subject: 'Alpha', sentAt: '2026-02-01 10:00:00' },
      { id: 3, from: 'c@x.test', subject: 'Middle', sentAt: '2026-01-15 10:00:00' },
    ];
    expect(M.sortMailRows(rows, 'sent', 'desc').map((r) => r.id)).toEqual([2, 3, 1]);
    expect(M.sortMailRows(rows, 'sent', 'asc').map((r) => r.id)).toEqual([1, 3, 2]);
    expect(M.sortMailRows(rows, 'from', 'asc').map((r) => r.id)).toEqual([2, 1, 3]);
    expect(M.sortMailRows(rows, 'subject', 'asc').map((r) => r.id)).toEqual([2, 3, 1]);
  });

  it('matchMailQuery matches from/subject/snippet case-insensitively', () => {
    const row = { from: 'Alex@X.DE', subject: 'Lizenz', snippet: 'Hallo' };
    expect(M.matchMailQuery(row, 'alex')).toBe(true);
    expect(M.matchMailQuery(row, 'LIZENZ')).toBe(true);
    expect(M.matchMailQuery(row, 'hallo')).toBe(true);
    expect(M.matchMailQuery(row, 'missing')).toBe(false);
    expect(M.matchMailQuery(row, '  ')).toBe(true);
  });

  it('nextSortState toggles same column and defaults new columns', () => {
    expect(M.nextSortState('sent', 'desc', 'sent')).toEqual({ key: 'sent', dir: 'asc' });
    expect(M.nextSortState('sent', 'asc', 'sent')).toEqual({ key: 'sent', dir: 'desc' });
    expect(M.nextSortState('sent', 'desc', 'from')).toEqual({ key: 'from', dir: 'asc' });
    expect(M.nextSortState('from', 'asc', 'sent')).toEqual({ key: 'sent', dir: 'desc' });
  });

  it('accountsForInboxTab never mixes Personal with Team (Bachus toolbar)', () => {
    const accounts = [
      { id: 3, kind: 'personal', label: 'Mine' },
      { id: 1, kind: 'shared', label: 'Team box' },
      { id: 4, kind: 'personal', label: 'Second personal' },
      { id: 0, kind: 'personal', label: 'bogus' },
    ];
    expect(M.accountsForInboxTab(accounts, 'mine').map((a) => a.id)).toEqual([3, 4]);
    expect(M.accountsForInboxTab(accounts, 'team').map((a) => a.id)).toEqual([1]);
    expect(M.accountsForInboxTab(accounts, 'unmatched')).toEqual([]);
    expect(M.accountsForInboxTab(null, 'mine')).toEqual([]);
    expect(M.accountFilterVisible(accounts, 'mine')).toBe(true);
    expect(M.accountFilterVisible([{ id: 3, kind: 'personal' }], 'mine')).toBe(false);
    expect(M.accountFilterVisible(accounts, 'team')).toBe(false);
    expect(M.accountFilterVisible(accounts, 'unmatched')).toBe(false);
  });

  it('pickComposeSendAccount prefers message mailbox over Team-first list order', () => {
    const accounts = [
      { id: 1, kind: 'team', hasSignature: false, label: 'Team' },
      { id: 3, kind: 'personal', hasSignature: true, label: 'Mine' },
    ];
    expect(M.pickComposeSendAccount(accounts, { mailboxId: 3 }, 'mine')?.id).toBe(3);
    expect(M.pickComposeSendAccount(accounts, { mailboxKind: 'personal' }, 'mine')?.id).toBe(3);
    expect(M.pickComposeSendAccount(accounts, {}, 'mine')?.id).toBe(3);
    expect(M.pickComposeSendAccount(accounts, { mailboxKind: 'team' }, 'team')?.id).toBe(1);
    expect(M.pickComposeSendAccount(accounts, { mailboxId: 99 }, 'mine')?.id).toBe(3);
    expect(M.pickComposeSendAccount([], { mailboxId: 3 }, 'mine')).toBeNull();
  });

  it('mutateActionVisibility shows Delete/Spam on Personal and Team, Assign-only on Unassigned', () => {
    expect(M.mutateActionVisibility({
      tab: 'mine', hasRow: true, hasReview: false, canWrite: true,
    })).toEqual({ discard: true, spam: true, mode: 'inbox', discardIsMail: true });
    expect(M.mutateActionVisibility({
      tab: 'team', hasRow: true, hasReview: false, canWrite: true,
    })).toEqual({ discard: true, spam: true, mode: 'inbox', discardIsMail: true });
    expect(M.mutateActionVisibility({
      tab: 'mine', hasRow: true, hasReview: false, canWrite: false,
    })).toEqual({ discard: false, spam: false, mode: 'none', discardIsMail: true });
    expect(M.mutateActionVisibility({
      tab: 'unmatched', hasRow: true, hasReview: true, canWrite: true, sourceKind: 'shared_mail',
    })).toEqual({ discard: true, spam: true, mode: 'assign', discardIsMail: true });
    expect(M.mutateActionVisibility({
      tab: 'unmatched', hasRow: true, hasReview: true, canWrite: true,
      sourceKind: 'personal_mail_shared_unmatched',
    })).toEqual({ discard: true, spam: false, mode: 'assign', discardIsMail: false });
    expect(M.mutateActionVisibility({
      tab: 'unmatched', hasRow: true, hasReview: false, canWrite: true, sourceKind: 'shared_mail',
    })).toEqual({ discard: false, spam: false, mode: 'none', discardIsMail: true });
  });
});
