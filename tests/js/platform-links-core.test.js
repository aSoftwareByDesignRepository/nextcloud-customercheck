/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';

function loadCore() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/platform-links-core.js');
  const code = readFileSync(path, 'utf8');
  const module = { exports: {} };
  const globalThisObj = {};
  vm.runInNewContext(code, {
    module,
    exports: module.exports,
    window: globalThisObj,
    globalThis: globalThisObj,
    URL,
    Object,
    Array,
    String,
  });
  return module.exports;
}

const Core = loadCore();
const ORIGIN = 'https://nc.test';

describe('platform-links-core', () => {
  it('accepts same-origin sibling app homes', () => {
    expect(Core.safeHref('https://nc.test/apps/calendar/', ORIGIN)).toBe('https://nc.test/apps/calendar/');
    expect(Core.safeHref('/apps/files/?fileid=9', ORIGIN)).toBe('https://nc.test/apps/files/?fileid=9');
  });

  it('rejects javascript, data, off-origin, and non-app paths', () => {
    expect(Core.safeHref('javascript:alert(1)', ORIGIN)).toBe('');
    expect(Core.safeHref('data:text/html,x', ORIGIN)).toBe('');
    expect(Core.safeHref('https://evil.test/apps/calendar/', ORIGIN)).toBe('');
    expect(Core.safeHref('https://nc.test/settings/admin', ORIGIN)).toBe('');
    expect(Core.safeHref('', ORIGIN)).toBe('');
    expect(Core.safeHref(null, ORIGIN)).toBe('');
    expect(Core.safeHref('/apps/calendar/', '')).toBe('');
  });

  it('drops unavailable, empty, and unsafe chips', () => {
    const chips = Core.enabledChips({
      calendar: { available: true, url: 'https://nc.test/apps/calendar/', label: 'Calendar' },
      tasks: { available: false, url: 'https://nc.test/apps/tasks/', label: 'Tasks' },
      files: { available: true, url: 'javascript:alert(1)', label: 'Files' },
      mail: { available: true, url: 'https://evil.test/apps/mail/', label: 'Mail' },
    }, ORIGIN);
    expect(chips).toEqual([
      { id: 'calendar', href: 'https://nc.test/apps/calendar/', label: 'Calendar' },
    ]);
  });

  it('returns no chips for missing or list payloads', () => {
    expect(Core.enabledChips(null, ORIGIN)).toEqual([]);
    expect(Core.enabledChips([], ORIGIN)).toEqual([]);
    expect(Core.enabledChips(undefined, ORIGIN)).toEqual([]);
  });
});
