/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';

function loadCore() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/filter-live-core.js');
  const code = readFileSync(path, 'utf8');
  const module = { exports: {} };
  const globalThisObj = {};
  vm.runInNewContext(code, {
    module,
    exports: module.exports,
    window: globalThisObj,
    globalThis: globalThisObj,
    URL,
    URLSearchParams,
  });
  return module.exports.mergeInboxItems
    ? module.exports
    : (module.exports.SEARCH_DEBOUNCE_MS ? module.exports : globalThisObj.CustomerCheckFilterLiveCore);
}

const Core = loadCore();
const HERE = 'https://cloud.example/apps/customercheck/companies?workspaceId=1';

describe('filter-live-core debounce + safety', () => {
  it('uses a 450ms trailing debounce so hunt-and-peck typing does not fire per key', () => {
    expect(Core.SEARCH_DEBOUNCE_MS).toBe(450);
    expect(Core.SEARCH_DEBOUNCE_MS).toBeGreaterThanOrEqual(400);
  });

  it('treats IME composition as in-progress', () => {
    expect(Core.isComposingEvent({ isComposing: true })).toBe(true);
    expect(Core.isComposingEvent({ keyCode: 229 })).toBe(true);
    expect(Core.isComposingEvent({ isComposing: false, keyCode: 65 })).toBe(false);
    expect(Core.isComposingEvent(null)).toBe(false);
  });

  it('builds the list URL from merged forms and drops empty keys', () => {
    const url = Core.buildFilterUrl(
      [
        [['workspaceId', '1'], ['status', 'active'], ['q', '']],
        [['q', 'Auto'], ['status', '']],
      ],
      '/apps/customercheck/companies',
      HERE,
    );
    expect(url).toBeTruthy();
    expect(url.searchParams.get('workspaceId')).toBe('1');
    expect(url.searchParams.get('q')).toBe('Auto');
    expect(url.searchParams.get('status')).toBeNull();
    expect(url.pathname).toBe('/apps/customercheck/companies');
    expect(url.origin).toBe('https://cloud.example');
  });

  it('keeps the current pathname so index.php vs pretty URLs cannot drift', () => {
    const loc = 'https://cloud.example/index.php/apps/customercheck/companies?workspaceId=1';
    const url = Core.buildFilterUrl([[['q', 'A'], ['workspaceId', '1']]], '/apps/customercheck/companies', loc);
    expect(url.pathname).toBe('/index.php/apps/customercheck/companies');
    expect(url.searchParams.get('q')).toBe('A');
  });

  it('rejects other origins, other paths, credentials, and overlong URLs', () => {
    const loc = new URL(HERE);
    const ok = Core.buildFilterUrl([[['q', 'A'], ['workspaceId', '1']]], '', HERE);
    expect(Core.isSafeFilterUrl(ok, loc)).toBe(true);

    const evil = new URL('https://evil.example/apps/customercheck/companies?q=A');
    expect(Core.isSafeFilterUrl(evil, loc)).toBe(false);

    const otherPage = new URL('https://cloud.example/apps/customercheck/today?q=A');
    expect(Core.isSafeFilterUrl(otherPage, loc)).toBe(false);

    const js = { href: 'javascript:alert(1)', protocol: 'javascript:', origin: loc.origin, pathname: loc.pathname, username: '', password: '' };
    expect(Core.isSafeFilterUrl(js, loc)).toBe(false);

    const authed = new URL(HERE);
    authed.username = 'x';
    authed.password = 'y';
    expect(Core.isSafeFilterUrl(authed, loc)).toBe(false);

    const huge = new URL(HERE);
    huge.searchParams.set('q', 'A'.repeat(Core.MAX_FILTER_URL_LENGTH));
    expect(Core.isSafeFilterUrl(huge, loc)).toBe(false);

    expect(Core.isSafeFilterUrl(null, loc)).toBe(false);
    expect(Core.isSafeFilterUrl(ok, null)).toBe(false);
  });

  it('allows the Nextcloud index.php prefix on the same companies path', () => {
    const loc = new URL('https://cloud.example/index.php/apps/customercheck/companies?workspaceId=1');
    const pretty = new URL('https://cloud.example/apps/customercheck/companies?workspaceId=1&q=A');
    expect(Core.isSafeFilterUrl(pretty, loc)).toBe(true);
  });

  it('knows when list filters are active and how to clear them', () => {
    const withQ = new URL('https://cloud.example/apps/customercheck/companies?workspaceId=1&q=A&status=active');
    expect(Core.hasActiveListFilters(withQ.searchParams)).toBe(true);
    expect(Core.clearListHref(withQ)).toBe('/apps/customercheck/companies?workspaceId=1');
    const bare = new URL('https://cloud.example/apps/customercheck/companies?workspaceId=1');
    expect(Core.hasActiveListFilters(bare.searchParams)).toBe(false);
    expect(Core.hasActiveListFilters(new URLSearchParams('scope=open'))).toBe(false);
    expect(Core.hasActiveListFilters(new URLSearchParams('scope=done'))).toBe(true);
    expect(Core.hasActiveListFilters(new URLSearchParams('archived=1'))).toBe(true);
    expect(Core.hasActiveListFilters(new URLSearchParams('archived=0'))).toBe(false);
  });

  it('counts real rows and announces busy / empty / results with page labels', () => {
    expect(Core.countResultRows([{ empty: true }, { empty: false }, null, { empty: false }])).toBe(2);
    expect(Core.countResultRows(null)).toBe(0);
    const t = (s) => s;
    expect(Core.statusMessage({ busy: true, t })).toBe('Updating the list…');
    expect(Core.statusMessage({ failed: true, t })).toBe('Could not update the list. Try again.');
    expect(Core.statusMessage({ failed: true, failedLabel: 'Could not update the list. Press Search.', t })).toBe('Could not update the list. Press Search.');
    expect(Core.statusMessage({ count: 0, t })).toBe('No matches');
    expect(Core.statusMessage({ count: 0, emptyLabel: 'No companies match', t })).toBe('No companies match');
    expect(Core.statusMessage({ count: 3, t })).toBe('List updated.');
    expect(Core.statusMessage({ count: 3, resultsLabel: 'Search results', t })).toBe('Search results');
  });

  it('treats modifier clicks as native navigation and recognizes filter chips', () => {
    expect(Core.isModifiedClick(null)).toBe(true);
    expect(Core.isModifiedClick({ button: 0 })).toBe(false);
    expect(Core.isModifiedClick({ button: 1 })).toBe(true);
    expect(Core.isModifiedClick({ button: 0, ctrlKey: true })).toBe(true);
    expect(Core.isModifiedClick({ button: 0, metaKey: true })).toBe(true);
    expect(Core.isLiveFilterNavAnchor({ id: 'crm-companies-search-clear', className: '' })).toBe(true);
    expect(Core.isLiveFilterNavAnchor({ id: 'btn-clear-filter', className: 'crm-btn' })).toBe(true);
    expect(Core.isLiveFilterNavAnchor({ id: '', className: 'crm-quick-filter is-active' })).toBe(true);
    expect(Core.isLiveFilterNavAnchor({ id: '', className: 'crm-btn crm-btn--sm' })).toBe(false);
    expect(Core.isLiveFilterNavAnchor(null)).toBe(false);
  });

  it('syncs select defaults from the URL so chip clicks do not leave stale filters', () => {
    expect(Core.defaultSelectValue('archived', ['0', '1'])).toBe('0');
    expect(Core.defaultSelectValue('scope', ['open', 'done', 'all'])).toBe('open');
    expect(Core.defaultSelectValue('state', ['', 'overdue', 'sent'])).toBe('');
    const params = new URLSearchParams('scope=done&state=sent&archived=1&status=active');
    expect(Core.selectValueFromParams('scope', ['open', 'done', 'all'], params)).toBe('done');
    expect(Core.selectValueFromParams('scope', ['open', 'done', 'all'], new URLSearchParams())).toBe('open');
    expect(Core.selectValueFromParams('state', ['', 'overdue', 'sent'], params)).toBe('sent');
    expect(Core.selectValueFromParams('kind', ['', 'license'], new URLSearchParams())).toBe('');
    expect(Core.hiddenFilterValue('archived', params)).toBe('1');
    expect(Core.hiddenFilterValue('archived', new URLSearchParams('archived=0'))).toBe('');
    expect(Core.hiddenFilterValue('status', params)).toBe('active');
    expect(Core.hiddenFilterValue('status', new URLSearchParams())).toBe('');
  });

  it('requires a main swap region before in-place fetch', () => {
    expect(Core.canSwapInPlace({ querySelector: (sel) => (sel === '[data-crm-filter-swap="main"]' ? {} : null) })).toBe(true);
    expect(Core.canSwapInPlace({ querySelector: () => null })).toBe(false);
    expect(Core.canSwapInPlace(null)).toBe(false);
    expect(Core.extractSwapNames('<div data-crm-filter-swap="nav"></div><div data-crm-filter-swap="main">rows</div>')).toEqual(['nav', 'main']);
    expect(Core.extractSwapNames('')).toEqual([]);
  });

  it('urlsEqual ignores empty and matches hrefs', () => {
    expect(Core.urlsEqual(HERE, HERE)).toBe(true);
    expect(Core.urlsEqual({ href: HERE }, HERE)).toBe(true);
    expect(Core.urlsEqual('', HERE)).toBe(false);
    expect(Core.urlsEqual(null, HERE)).toBe(false);
  });

  it('sends the fragment header and RFC 7240 Prefer: return=minimal', () => {
    expect(Core.FRAGMENT_HEADER).toBe('X-CustomerCheck-Fragment');
    expect(Core.PREFER_HEADER).toBe('Prefer');
    expect(Core.PREFER_MINIMAL).toBe('return=minimal');
    const headers = Core.fragmentRequestHeaders();
    expect(headers[Core.FRAGMENT_HEADER]).toBe('1');
    expect(headers[Core.PREFER_HEADER]).toBe('return=minimal');
    expect(headers.Accept).toBe('text/html');
    expect(headers['X-Requested-With']).toBe('XMLHttpRequest');
    expect(Object.keys(headers).sort()).toEqual(
      ['Accept', 'Prefer', 'X-CustomerCheck-Fragment', 'X-Requested-With'].sort(),
    );
  });
});
