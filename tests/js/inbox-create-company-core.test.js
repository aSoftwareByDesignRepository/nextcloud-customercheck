/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';

function loadCore() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/inbox-create-company-core.js');
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
  return module.exports.validateInlineCompanyName
    ? module.exports
    : globalThisObj.CustomerCheckInboxCreateCompanyCore;
}

const Core = loadCore();

describe('inbox-create-company-core', () => {
  it('validateInlineCompanyName rejects empty and overlong names', () => {
    expect(Core.validateInlineCompanyName('')).toEqual({ ok: false, name: '', error: 'empty' });
    expect(Core.validateInlineCompanyName('   ')).toEqual({ ok: false, name: '', error: 'empty' });
    const long = 'x'.repeat(256);
    expect(Core.validateInlineCompanyName(long)).toEqual({ ok: false, name: long, error: 'too_long' });
    expect(Core.validateInlineCompanyName('  Acme GmbH  ')).toEqual({
      ok: true,
      name: 'Acme GmbH',
      error: '',
    });
  });

  it('buildMinimalCompanyCreateBody keeps trimmed name only', () => {
    expect(Core.buildMinimalCompanyCreateBody('  Foo  ')).toEqual({ name: 'Foo' });
  });

  it('extractCompanyIdFromCreateResponse unwraps data and nested company', () => {
    expect(Core.extractCompanyIdFromCreateResponse({ data: { id: 12 } })).toBe(12);
    expect(Core.extractCompanyIdFromCreateResponse({ data: { company: { id: '9' } } })).toBe(9);
    expect(Core.extractCompanyIdFromCreateResponse({ id: 3 })).toBe(3);
    expect(Core.extractCompanyIdFromCreateResponse({ data: {} })).toBe(0);
    expect(Core.extractCompanyIdFromCreateResponse(null)).toBe(0);
  });
});
