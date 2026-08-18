/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';

function loadCore() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/person-dialog-core.js');
  const code = readFileSync(path, 'utf8');
  const module = { exports: {} };
  const globalThisObj = {};
  vm.runInNewContext(code, {
    module,
    exports: module.exports,
    window: globalThisObj,
    globalThis: globalThisObj,
  });
  return module.exports.DIALOG_ID ? module.exports : globalThisObj.CustomerCheckPersonDialogCore;
}

const Core = loadCore();

describe('person-dialog-core ids', () => {
  it('keeps stable dialog and form ids for a11y wiring', () => {
    expect(Core.DIALOG_ID).toBe('crm-person-dialog');
    expect(Core.FORM_ID).toBe('crm-person-form');
    expect(Core.FOCUS_AFTER_OPEN_MS).toBe(80);
    expect(Core.FOCUS_AFTER_OPEN_MS).toBeGreaterThan(50);
  });
});

describe('validatePersonFields', () => {
  it('requires a name and accepts optional valid email/phone', () => {
    expect(Core.validatePersonFields({ name: '  Ada  ', email: '', phone: '' })).toEqual({
      ok: true,
      code: null,
      name: 'Ada',
      email: '',
      phone: '',
    });
    expect(Core.validatePersonFields({ name: 'Ada', email: 'ada@example.com', phone: '+49 431 1234' }).ok).toBe(true);
  });

  it('rejects empty name, junk email, and illegal phone characters', () => {
    expect(Core.validatePersonFields({ name: '   ', email: '', phone: '' }).code).toBe('name');
    expect(Core.validatePersonFields(null).code).toBe('name');
    expect(Core.validatePersonFields({ name: 'Ada', email: 'not-an-email', phone: '' }).code).toBe('email');
    expect(Core.validatePersonFields({ name: 'Ada', email: 'ada@', phone: '' }).code).toBe('email');
    expect(Core.validatePersonFields({ name: 'Ada', email: '', phone: 'abc' }).code).toBe('phone');
    expect(Core.validatePersonFields({ name: 'Ada', email: '', phone: '123' }).code).toBe('phone');
    expect(Core.validatePersonFields({ name: 'Ada', email: '', phone: '++++' }).ok).toBe(true);
  });

  it('does not treat whitespace-only email as invalid', () => {
    const r = Core.validatePersonFields({ name: 'Ada', email: '  ', phone: '  ' });
    expect(r.ok).toBe(true);
    expect(r.email).toBe('');
    expect(r.phone).toBe('');
  });
});

describe('shouldOpenAdvanced', () => {
  it('opens More only when job title or phone is already filled', () => {
    expect(Core.shouldOpenAdvanced('', '')).toBe(false);
    expect(Core.shouldOpenAdvanced('  ', '  ')).toBe(false);
    expect(Core.shouldOpenAdvanced('CTO', '')).toBe(true);
    expect(Core.shouldOpenAdvanced('', '+1 2')).toBe(true);
  });
});

describe('payload builders', () => {
  it('omits contactsUid unless a non-empty uid is provided', () => {
    const base = Core.buildCreatePayload({ name: 'Ada', email: 'a@b.c', phone: '', role: ' CTO ' }, '');
    expect(base).toEqual({
      name: 'Ada',
      email: 'a@b.c',
      phone: '',
      role: 'CTO',
      isPrimary: false,
    });
    expect(Object.prototype.hasOwnProperty.call(base, 'contactsUid')).toBe(false);

    const linked = Core.buildCreatePayload({ name: 'Ada', email: '', phone: '', role: '' }, '  uid-1  ');
    expect(linked.contactsUid).toBe('uid-1');
    expect(linked.isPrimary).toBe(false);
  });

  it('keeps updatedAt null rather than dropping the optimistic-lock field', () => {
    expect(Core.buildUpdatePayload({ name: 'Ada', email: '', phone: '', role: '' }, '')).toEqual({
      name: 'Ada',
      email: '',
      phone: '',
      role: '',
      updatedAt: null,
    });
    expect(Core.buildUpdatePayload({ name: 'Ada', email: '', phone: '', role: 'Dev' }, '2026-01-01T00:00:00Z').updatedAt).toBe('2026-01-01T00:00:00Z');
  });
});

describe('dialog open / suggest / dismiss races', () => {
  it('treats .open true or the open attribute as open, never a missing node', () => {
    expect(Core.isNativeDialogOpen(null)).toBe(false);
    expect(Core.isNativeDialogOpen({})).toBe(false);
    expect(Core.isNativeDialogOpen({ open: false })).toBe(false);
    expect(Core.isNativeDialogOpen({ open: true })).toBe(true);
    expect(Core.isNativeDialogOpen({ open: false, hasAttribute: (n) => n === 'open' })).toBe(true);
  });

  it('suggests Contacts only in add mode while the dialog is open and idle', () => {
    expect(Core.shouldSuggestContacts('add', false, true)).toBe(true);
    expect(Core.shouldSuggestContacts('edit', false, true)).toBe(false);
    expect(Core.shouldSuggestContacts('add', true, true)).toBe(false);
    expect(Core.shouldSuggestContacts('add', false, false)).toBe(false);
    expect(Core.shouldSuggestContacts('add', false, undefined)).toBe(false);
  });

  it('blocks dismiss only while a save is in flight', () => {
    expect(Core.shouldBlockDialogDismiss(true)).toBe(true);
    expect(Core.shouldBlockDialogDismiss(false)).toBe(false);
    expect(Core.shouldBlockDialogDismiss(0)).toBe(false);
  });
});

describe('hostDialogOnBody and persist flags', () => {
  it('moves the dialog onto body exactly once', () => {
    const body = { children: [], appendChild(node) { this.children.push(node); node.parentElement = this; } };
    const dlg = { parentElement: { id: 'crm-persons' } };
    expect(Core.hostDialogOnBody(dlg, body)).toBe(true);
    expect(dlg.parentElement).toBe(body);
    expect(Core.hostDialogOnBody(dlg, body)).toBe(false);
    expect(Core.hostDialogOnBody(null, body)).toBe(false);
    expect(Core.hostDialogOnBody(dlg, null)).toBe(false);
  });

  it('marks persist so stacked confirm dialogs cannot destroy the form', () => {
    const dlg = { dataset: {} };
    expect(Core.markPersist(dlg)).toBe(true);
    expect(dlg.dataset.azcModalPersist).toBe('true');
    expect(dlg.dataset.azcFocusTrapStandalone).toBe('1');
    expect(Core.markPersist(null)).toBe(false);
  });

  it('toggles dismiss lock without leaving a stale false string after save', () => {
    const dlg = { dataset: {} };
    Core.setDismissible(dlg, false);
    expect(dlg.dataset.azcModalDismiss).toBe('false');
    Core.setDismissible(dlg, true);
    expect(dlg.dataset.azcModalDismiss).toBeUndefined();
    Core.setDismissible(null, false);
  });
});
