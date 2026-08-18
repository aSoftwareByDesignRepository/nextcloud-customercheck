import { describe, expect, it } from 'vitest';
import {
  canOpenWorkspaceMenu,
  listFallbackKeyForPage,
  parseWorkspaceId,
  resolveActiveWorkspaceId,
  withWorkspaceId,
} from '../../js/common/workspace-switcher-core.js';

describe('parseWorkspaceId', () => {
  it('accepts positive integers only', () => {
    expect(parseWorkspaceId('12')).toBe(12);
    expect(parseWorkspaceId(3)).toBe(3);
    expect(parseWorkspaceId('0')).toBe(0);
    expect(parseWorkspaceId('-2')).toBe(0);
    expect(parseWorkspaceId('')).toBe(0);
    expect(parseWorkspaceId(null)).toBe(0);
    expect(parseWorkspaceId('abc')).toBe(0);
  });
});

describe('resolveActiveWorkspaceId', () => {
  it('prefers URL over attribute and control value', () => {
    expect(
      resolveActiveWorkspaceId({
        search: '?workspaceId=9',
        activeAttr: '2',
        controlValue: '3',
      }),
    ).toBe(9);
  });

  it('falls back to SSR attribute then control value', () => {
    expect(resolveActiveWorkspaceId({ search: '', activeAttr: '5', controlValue: '8' })).toBe(5);
    expect(resolveActiveWorkspaceId({ search: '', activeAttr: null, controlValue: '8' })).toBe(8);
    expect(resolveActiveWorkspaceId({ search: '', activeAttr: null, controlValue: null })).toBe(0);
  });
});

describe('withWorkspaceId', () => {
  it('sets workspaceId on same-origin relative paths', () => {
    expect(withWorkspaceId('/apps/customercheck/pulse', 4)).toBe(
      '/apps/customercheck/pulse?workspaceId=4',
    );
    expect(withWorkspaceId('/apps/customercheck/pulse?foo=1', 4)).toBe(
      '/apps/customercheck/pulse?foo=1&workspaceId=4',
    );
  });

  it('rejects absolute and protocol-relative URLs', () => {
    expect(withWorkspaceId('https://evil.example/x', 1)).toBe('#');
    expect(withWorkspaceId('//evil.example/x', 1)).toBe('#');
    expect(withWorkspaceId('', 1)).toBe('#');
    expect(withWorkspaceId('/apps/customercheck/today', 0)).toBe('#');
  });
});

describe('listFallbackKeyForPage', () => {
  it('maps entity pages to list routes', () => {
    expect(listFallbackKeyForPage('company-detail')).toBe('companies');
    expect(listFallbackKeyForPage('agreement-form')).toBe('agreements');
    expect(listFallbackKeyForPage('prepare-meeting')).toBeNull();
    expect(listFallbackKeyForPage('pulse')).toBeNull();
  });
});

describe('canOpenWorkspaceMenu', () => {
  it('opens only when more than one workspace exists', () => {
    expect(canOpenWorkspaceMenu(0)).toBe(false);
    expect(canOpenWorkspaceMenu(1)).toBe(false);
    expect(canOpenWorkspaceMenu(2)).toBe(true);
  });
});
