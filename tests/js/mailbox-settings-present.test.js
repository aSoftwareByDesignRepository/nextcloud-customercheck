/**
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import {
  buildFolderPickerOptions,
  buildMailboxListRows,
  mailboxStatusLabel,
  mailboxStatusTone,
  readCheckedFolderPaths,
} from '../../js/utils/mailbox-settings-present.js';

const t = (msg, args) => (args && args.length ? msg.replace('%s', args[0]) : msg);

describe('mailboxStatusTone / mailboxStatusLabel', () => {
  it('maps API statuses to granny tones and labels', () => {
    expect(mailboxStatusTone('ok')).toBe('success');
    expect(mailboxStatusTone('error')).toBe('danger');
    expect(mailboxStatusTone('warn')).toBe('warning');
    expect(mailboxStatusTone('')).toBe('neutral');
    expect(mailboxStatusLabel('ok', t)).toBe('Connected');
    expect(mailboxStatusLabel('error', t)).toBe('Connection error');
    expect(mailboxStatusLabel('weird', t)).toBe('Unknown');
  });
});

describe('buildFolderPickerOptions / readCheckedFolderPaths', () => {
  it('merges remote options and keeps selected paths checked', () => {
    const opts = buildFolderPickerOptions(['INBOX', 'Sent', 'Archive'], ['Sent'], 20);
    expect(opts.map((o) => o.path)).toEqual(['INBOX', 'Sent', 'Archive']);
    expect(opts.find((o) => o.path === 'Sent')?.checked).toBe(true);
    expect(opts.find((o) => o.path === 'INBOX')?.checked).toBe(false);
  });

  it('defaults to INBOX when nothing selected', () => {
    const opts = buildFolderPickerOptions(['INBOX', 'Drafts'], [], 20);
    expect(opts.find((o) => o.path === 'INBOX')?.checked).toBe(true);
  });

  it('keeps selected folders that are missing from remote', () => {
    const opts = buildFolderPickerOptions(['INBOX'], ['INBOX', 'Custom'], 20);
    expect(opts.map((o) => o.path)).toEqual(['INBOX', 'Custom']);
    expect(opts.every((o) => o.checked)).toBe(true);
  });

  it('reads checked folder paths from a checkbox host', () => {
    const root = {
      querySelectorAll(sel) {
        expect(sel).toContain('checkbox');
        return [
          { value: 'INBOX' },
          { value: ' Sent ' },
          { value: '' },
        ];
      },
    };
    expect(readCheckedFolderPaths(root)).toEqual(['INBOX', 'Sent']);
    expect(readCheckedFolderPaths(null)).toEqual([]);
  });
});

describe('buildMailboxListRows', () => {
  it('filters invalid ids and builds meta without secrets', () => {
    const rows = buildMailboxListRows([
      {
        id: 3,
        label: 'Team inbox',
        emailAddress: 'team@example.com',
        status: 'ok',
        lastSyncAt: '2026-08-09T10:00:00+00:00',
        authFailCount: 0,
        password: 'secret',
      },
      { id: 0, label: 'bad' },
      {
        id: 9,
        label: '',
        emailAddress: 'x@y.z',
        status: 'error',
        authFailCount: 3,
        lastError: 'IMAP auth failed ×3',
      },
    ], t);
    expect(rows).toHaveLength(2);
    expect(rows[0].label).toBe('Team inbox');
    expect(rows[0].tone).toBe('success');
    expect(rows[0].meta).toContain('Last sync:');
    expect(JSON.stringify(rows)).not.toContain('secret');
    expect(rows[1].label).toBe('Shared mailbox');
    expect(rows[1].tone).toBe('danger');
    expect(rows[1].meta).toContain('3 failed logins');
    expect(rows[1].lastError).toBe('IMAP auth failed ×3');
  });

  it('survives null / non-array input', () => {
    expect(buildMailboxListRows(null, t)).toEqual([]);
    expect(buildMailboxListRows(undefined, t)).toEqual([]);
  });
});
