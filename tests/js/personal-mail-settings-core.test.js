/**
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import {
  hidePersonalMailOpsForReconnect,
  isPersonalMailOAuth,
  needsPersonalMailReconnect,
  personalConnectSubmitLabel,
  personalEditConnectionButtonLabel,
  personalMailHealId,
  showPersonalMailConnectForm,
} from '../../js/utils/personal-mail-settings-core.js';

const t = (msg) => msg;

describe('needsPersonalMailReconnect', () => {
  it('is false for healthy boxes', () => {
    expect(needsPersonalMailReconnect(null)).toBe(false);
    expect(needsPersonalMailReconnect({ status: 'ok', authFailCount: 0 })).toBe(false);
    expect(needsPersonalMailReconnect({ status: 'ok', authFailCount: 2 })).toBe(false);
  });

  it('is true for auth quarantine statuses and fail streak', () => {
    expect(needsPersonalMailReconnect({ status: 'error' })).toBe(true);
    expect(needsPersonalMailReconnect({ status: 'auth' })).toBe(true);
    expect(needsPersonalMailReconnect({ status: 'auth_error' })).toBe(true);
    expect(needsPersonalMailReconnect({ status: 'ok', authFailCount: 3 })).toBe(true);
  });
});

describe('showPersonalMailConnectForm', () => {
  it('shows for first connect, quarantine, and voluntary edit — not plain ok', () => {
    expect(showPersonalMailConnectForm({ box: null })).toBe(true);
    expect(showPersonalMailConnectForm({ box: { status: 'error' } })).toBe(true);
    expect(showPersonalMailConnectForm({ box: { status: 'ok' }, editConnection: true })).toBe(true);
    expect(showPersonalMailConnectForm({ box: { status: 'ok' }, editConnection: false })).toBe(false);
  });
});

describe('hidePersonalMailOpsForReconnect', () => {
  it('hides ops only for quarantine — not voluntary edit', () => {
    expect(hidePersonalMailOpsForReconnect({ status: 'error' })).toBe(true);
    expect(hidePersonalMailOpsForReconnect({ status: 'ok' })).toBe(false);
  });
});

describe('personalMailHealId', () => {
  it('returns id only when emails match', () => {
    const box = { id: 9, emailAddress: 'a@example.com' };
    expect(personalMailHealId(box, 'a@example.com')).toBe(9);
    expect(personalMailHealId(box, 'A@Example.com')).toBe(9);
    expect(personalMailHealId(box, 'other@example.com')).toBe(0);
    expect(personalMailHealId({ id: 0, emailAddress: 'a@example.com' }, 'a@example.com')).toBe(0);
  });
});

describe('labels', () => {
  it('picks reconnect / save / connect and oauth vs imap edit labels', () => {
    expect(personalConnectSubmitLabel({ needsReconnect: true }, t)).toBe('Save and reconnect');
    expect(personalConnectSubmitLabel({ box: { id: 1 }, editConnection: true }, t)).toBe('Save connection');
    expect(personalConnectSubmitLabel({}, t)).toBe('Connect my email');
    expect(personalEditConnectionButtonLabel({ authKind: 'google' }, t)).toBe('Reconnect account');
    expect(personalEditConnectionButtonLabel({ authKind: 'imap' }, t)).toBe('Update password or servers');
    expect(isPersonalMailOAuth({ authKind: 'microsoft' })).toBe(true);
    expect(isPersonalMailOAuth({ authKind: 'imap' })).toBe(false);
  });
});
