import { describe, expect, it } from 'vitest';
import {
  fileIdFromInfo,
  isDirectoryInfo,
  isLinkableFolderPath,
  isPickerCancelled,
  normalizePickerPath,
  pathCandidates,
} from '../../js/common/folder-picker-core.js';

describe('normalizePickerPath', () => {
  it('normalizes relative and absolute user paths', () => {
    expect(normalizePickerPath('Contracts/Acme', 'alice')).toBe('/Contracts/Acme');
    expect(normalizePickerPath('/Contracts/Acme/', 'alice')).toBe('/Contracts/Acme');
    expect(normalizePickerPath('', 'alice')).toBe('');
  });

  it('strips DAV prefixes for the current user', () => {
    expect(normalizePickerPath('/remote.php/dav/files/alice/Contracts', 'alice')).toBe('/Contracts');
    expect(normalizePickerPath('/alice/files/Contracts', 'alice')).toBe('/Contracts');
  });

  it('maps empty after strip to home root', () => {
    expect(normalizePickerPath('/remote.php/dav/files/alice', 'alice')).toBe('/');
  });
});

describe('isLinkableFolderPath', () => {
  it('rejects empty and home root', () => {
    expect(isLinkableFolderPath('')).toBe(false);
    expect(isLinkableFolderPath('/')).toBe(false);
    expect(isLinkableFolderPath('/Contracts')).toBe(true);
  });
});

describe('isDirectoryInfo / fileIdFromInfo', () => {
  it('detects directories by mime and type', () => {
    expect(isDirectoryInfo({ mimetype: 'httpd/unix-directory' })).toBe(true);
    expect(isDirectoryInfo({ type: 'directory' })).toBe(true);
    expect(isDirectoryInfo({ mimetype: 'text/plain' })).toBe(false);
    expect(isDirectoryInfo(null)).toBe(false);
  });

  it('reads positive file ids only', () => {
    expect(fileIdFromInfo({ id: 12 })).toBe(12);
    expect(fileIdFromInfo({ fileid: '99' })).toBe(99);
    expect(fileIdFromInfo({ fileId: 0 })).toBeNull();
    expect(fileIdFromInfo({})).toBeNull();
  });
});

describe('isPickerCancelled', () => {
  it('matches FilePicker cancel messages', () => {
    expect(isPickerCancelled('FilePicker: No nodes selected')).toBe(true);
    expect(isPickerCancelled({ message: 'FilePicker: No nodes selected' })).toBe(true);
    expect(isPickerCancelled('other')).toBe(false);
    expect(isPickerCancelled(null)).toBe(false);
  });
});

describe('pathCandidates', () => {
  it('dedupes and prefers concrete folders over home', () => {
    expect(pathCandidates('/Contracts/Acme', 'alice')).toEqual(['/Contracts/Acme']);
    expect(pathCandidates('/', 'alice', '/Contracts')).toEqual(['/Contracts']);
  });
});
