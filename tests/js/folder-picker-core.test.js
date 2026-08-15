import { describe, expect, it } from 'vitest';
import {
  buildDavFilesPropfindUrl,
  extractPickerPickResult,
  fileIdFromInfo,
  isDirectoryInfo,
  isLinkableFolderPath,
  isPickerCancelled,
  normalizeMimeTypeFilter,
  normalizePickerPath,
  parseDavFileIdFromPropfindXml,
  pathCandidates,
  selectionFromPickerNode,
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

  it('reads fileid from Node attributes (modern Files API)', () => {
    expect(fileIdFromInfo({ attributes: { fileid: 777 }, basename: 'a.pdf' })).toBe(777);
    expect(fileIdFromInfo({ attributes: { fileId: '88' } })).toBe(88);
    expect(fileIdFromInfo({ attributes: { id: 0 } })).toBeNull();
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

describe('extractPickerPickResult', () => {
  it('reads path strings and arrays', () => {
    expect(extractPickerPickResult('/Docs/a.pdf')).toEqual({
      path: '/Docs/a.pdf',
      fileId: null,
      name: 'a.pdf',
    });
    expect(extractPickerPickResult(['/Docs/b.png'])?.name).toBe('b.png');
    expect(extractPickerPickResult([])).toBeNull();
    expect(extractPickerPickResult(null)).toBeNull();
  });

  it('reads Node-like objects with fileid', () => {
    expect(extractPickerPickResult({
      path: '/Docs/c.pdf',
      fileid: 42,
      basename: 'c.pdf',
    })).toEqual({
      path: '/Docs/c.pdf',
      fileId: 42,
      name: 'c.pdf',
    });
  });

  it('builds path from dirname + basename when path missing', () => {
    expect(extractPickerPickResult({
      dirname: '/Docs',
      basename: 'quote.pdf',
      fileid: 9,
    })).toEqual({
      path: '/Docs/quote.pdf',
      fileId: 9,
      name: 'quote.pdf',
    });
  });

  it('accepts fileid-only Node without path (pickNodes edge)', () => {
    expect(extractPickerPickResult({ fileid: 55, basename: 'x.txt' })).toEqual({
      path: '',
      fileId: 55,
      name: 'x.txt',
    });
  });
});

describe('selectionFromPickerNode', () => {
  it('returns selection when Node has fileid', () => {
    expect(selectionFromPickerNode({
      fileid: 101,
      path: '/Quotes/a.pdf',
      basename: 'a.pdf',
    })).toEqual({
      fileId: 101,
      path: '/Quotes/a.pdf',
      name: 'a.pdf',
    });
  });

  it('rejects directories and path-only picks', () => {
    expect(selectionFromPickerNode({
      fileid: 1,
      mimetype: 'httpd/unix-directory',
      path: '/Quotes',
    })).toBeNull();
    expect(selectionFromPickerNode('/Quotes/a.pdf')).toBeNull();
    expect(selectionFromPickerNode(null)).toBeNull();
    expect(selectionFromPickerNode({ path: '/Quotes/a.pdf', basename: 'a.pdf' })).toBeNull();
  });
});

describe('normalizeMimeTypeFilter', () => {
  it('treats empty and star as all files', () => {
    expect(normalizeMimeTypeFilter([])).toEqual([]);
    expect(normalizeMimeTypeFilter(['*'])).toEqual([]);
    expect(normalizeMimeTypeFilter(null)).toEqual([]);
  });

  it('maps image/jpg → image/jpeg and dedupes', () => {
    expect(normalizeMimeTypeFilter([
      'application/pdf',
      'image/jpg',
      'image/jpeg',
      'IMAGE/PNG',
      '',
    ])).toEqual(['application/pdf', 'image/jpeg', 'image/png']);
  });
});

describe('parseDavFileIdFromPropfindXml', () => {
  it('parses oc:fileid with and without prefixes', () => {
    const prefixed = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
      + '<d:response><d:propstat><d:prop><oc:fileid>4242</oc:fileid></d:prop></d:propstat></d:response>'
      + '</d:multistatus>';
    expect(parseDavFileIdFromPropfindXml(prefixed)).toBe(4242);
    expect(parseDavFileIdFromPropfindXml('<fileid>7</fileid>')).toBe(7);
    expect(parseDavFileIdFromPropfindXml('<oc:fileid>0</oc:fileid>')).toBeNull();
    expect(parseDavFileIdFromPropfindXml('')).toBeNull();
    expect(parseDavFileIdFromPropfindXml('<d:displayname>x</d:displayname>')).toBeNull();
  });
});

describe('buildDavFilesPropfindUrl', () => {
  it('encodes user and path segments', () => {
    expect(buildDavFilesPropfindUrl('/Docs/My Quote.pdf', 'alice', '/remote.php/dav'))
      .toBe('/remote.php/dav/files/alice/Docs/My%20Quote.pdf');
    expect(buildDavFilesPropfindUrl('/', 'alice', '/remote.php/dav')).toBe('');
    expect(buildDavFilesPropfindUrl('/a.pdf', '', '/remote.php/dav')).toBe('');
  });
});
