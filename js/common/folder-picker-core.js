/**
 * Pure helpers for the CustomerCheck Files folder picker (testable without DOM).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

export const CANCEL_RE = /FilePicker:\s*No nodes selected/i;
export const PICKER_MIME_FILTER = ['*', 'httpd/unix-directory'];

/**
 * @param {unknown} reason
 * @returns {boolean}
 */
export function isPickerCancelled(reason) {
  if (!reason) {
    return false;
  }
  if (typeof reason === 'string') {
    return CANCEL_RE.test(reason);
  }
  if (typeof reason === 'object' && reason !== null && 'message' in reason) {
    return CANCEL_RE.test(String(reason.message || ''));
  }
  return false;
}

/**
 * @param {unknown} info
 * @returns {boolean}
 */
export function isDirectoryInfo(info) {
  if (!info || typeof info !== 'object') {
    return false;
  }
  const node = /** @type {Record<string, unknown>} */ (info);
  if (typeof node.isDirectory === 'function') {
    try {
      if (node.isDirectory()) {
        return true;
      }
    } catch (_) {
      /* fall through */
    }
  }
  if (node.type === 'directory' || node.type === 'dir') {
    return true;
  }
  const mime = String(node.mimetype || node.mime || '').toLowerCase();
  return mime === 'httpd/unix-directory';
}

/**
 * @param {unknown} info
 * @returns {number|null}
 */
export function fileIdFromInfo(info) {
  if (!info || typeof info !== 'object') {
    return null;
  }
  const node = /** @type {Record<string, unknown>} */ (info);
  const attrs = (node.attributes && typeof node.attributes === 'object')
    ? /** @type {Record<string, unknown>} */ (node.attributes)
    : null;
  const raw = node.id
    ?? node.fileid
    ?? node.fileId
    ?? (attrs ? (attrs.fileid ?? attrs.fileId ?? attrs.id) : null);
  const id = parseInt(String(raw ?? ''), 10);
  return Number.isFinite(id) && id > 0 ? id : null;
}

/**
 * Build a mail/Files attachment selection from a picker Node or path-like object
 * when fileid is already known (no DAV round-trip).
 *
 * @param {unknown} node
 * @returns {{ fileId: number, path: string, name: string }|null}
 */
export function selectionFromPickerNode(node) {
  if (!node || typeof node !== 'object') {
    return null;
  }
  if (isDirectoryInfo(node)) {
    return null;
  }
  const extracted = extractPickerPickResult(node);
  if (!extracted || !(extracted.fileId > 0)) {
    return null;
  }
  return {
    fileId: extracted.fileId,
    path: extracted.path || '/',
    name: extracted.name || ('file ' + extracted.fileId),
  };
}

/**
 * Normalize mime filters for @nextcloud/dialogs FilePicker.
 * Empty / '*' means all files (server still enforces MAIL_ATTACH_ALLOWED_MIMES on send).
 * Drops bogus aliases like image/jpg so the picker does not over-filter.
 *
 * @param {unknown} mimeFilter
 * @returns {string[]}
 */
export function normalizeMimeTypeFilter(mimeFilter) {
  if (!Array.isArray(mimeFilter) || !mimeFilter.length) {
    return [];
  }
  const out = [];
  const seen = Object.create(null);
  mimeFilter.forEach((raw) => {
    let m = String(raw || '').trim().toLowerCase();
    if (!m || m === '*') {
      return;
    }
    if (m === 'image/jpg') {
      m = 'image/jpeg';
    }
    if (seen[m]) {
      return;
    }
    seen[m] = true;
    out.push(m);
  });
  return out;
}

/**
 * Parse oc:fileid from a Depth:0 PROPFIND response body.
 *
 * @param {string} xml
 * @returns {number|null}
 */
export function parseDavFileIdFromPropfindXml(xml) {
  if (!xml || typeof xml !== 'string') {
    return null;
  }
  const m = xml.match(/<(?:[\w-]+:)?fileid[^>]*>\s*(\d+)\s*<\/(?:[\w-]+:)?fileid>/i);
  if (!m) {
    return null;
  }
  const id = parseInt(m[1], 10);
  return Number.isFinite(id) && id > 0 ? id : null;
}

/**
 * Build WebDAV PROPFIND URL for a user-relative Files path.
 *
 * @param {string} normalizedPath path starting with /
 * @param {string} userId
 * @param {string} [davBase] e.g. /remote.php/dav
 * @returns {string}
 */
export function buildDavFilesPropfindUrl(normalizedPath, userId, davBase) {
  const uid = String(userId || '').trim();
  const path = String(normalizedPath || '').trim();
  if (!uid || !path || path === '/') {
    return '';
  }
  let base = String(davBase || '/remote.php/dav').trim();
  if (!base) {
    base = '/remote.php/dav';
  }
  const rel = path.replace(/^\/+/, '');
  return base.replace(/\/+$/, '')
    + '/files/'
    + encodeURIComponent(uid)
    + '/'
    + rel.split('/').map(encodeURIComponent).join('/');
}

/**
 * Strip DAV / remote prefixes so OC.Files.getClient paths stay user-relative.
 *
 * @param {string} rawPath
 * @param {string} [userId]
 * @returns {string} Absolute-looking user path ("/" or "/Foo/Bar")
 */
export function normalizePickerPath(rawPath, userId) {
  let path = String(rawPath || '').trim();
  if (!path) {
    return '';
  }
  try {
    if (/^https?:\/\//i.test(path)) {
      path = new URL(path, 'https://example.invalid').pathname;
    }
  } catch (_) {
    /* keep path */
  }

  const uid = String(userId || '').trim();
  if (uid) {
    const prefixes = [
      '/remote.php/dav/files/' + uid,
      '/' + uid + '/files',
    ];
    prefixes.forEach((prefix) => {
      if (path.toLowerCase().startsWith(prefix.toLowerCase())) {
        path = path.slice(prefix.length);
      }
    });
  }

  path = path.replace(/^\/+/, '').replace(/\/+$/, '');
  return path ? '/' + path : '/';
}

/**
 * Home root is not a sensible customer documents folder.
 *
 * @param {string} normalizedPath
 * @returns {boolean}
 */
export function isLinkableFolderPath(normalizedPath) {
  const path = String(normalizedPath || '').trim();
  return path !== '' && path !== '/';
}

/**
 * Normalize FilePicker pick() results across Nextcloud versions
 * (path string, array of paths, or Files Node-like objects).
 *
 * @param {unknown} raw
 * @returns {{ path: string, fileId: number|null, name: string }|null}
 */
export function extractPickerPickResult(raw) {
  if (raw == null || raw === false || raw === '') {
    return null;
  }
  let item = raw;
  if (Array.isArray(raw)) {
    if (!raw.length) {
      return null;
    }
    item = raw[0];
  }
  if (typeof item === 'string' || typeof item === 'number') {
    const path = String(item).trim();
    if (!path) {
      return null;
    }
    const parts = path.split('/').filter(Boolean);
    const name = parts.length ? parts[parts.length - 1] : path;
    return { path: path, fileId: null, name: name };
  }
  if (typeof item !== 'object') {
    return null;
  }
  const node = /** @type {Record<string, unknown>} */ (item);
  const fileId = fileIdFromInfo(node);
  let path = '';
  if (typeof node.path === 'string' && node.path.trim() !== '') {
    path = String(node.path).trim();
  } else if (typeof node.dirname === 'string' && typeof node.basename === 'string') {
    const dir = String(node.dirname).replace(/\/+$/, '');
    const base = String(node.basename).replace(/^\/+/, '');
    path = (dir === '/' || dir === '' ? '' : dir) + '/' + base;
  } else if (typeof node.source === 'string' && node.source.trim() !== '') {
    path = String(node.source).trim();
  } else if (typeof node.href === 'string' && node.href.trim() !== '') {
    path = String(node.href).trim();
  }
  const name = String(
    node.basename || node.displayname || node.name || node.filename || '',
  ).trim()
    || (path ? path.split('/').filter(Boolean).pop() : '')
    || (fileId ? ('file ' + fileId) : '');
  if (!path && !(fileId && fileId > 0)) {
    return null;
  }
  return { path: path, fileId: fileId, name: name };
}

/**
 * Ordered path variants to try with the Files client.
 *
 * @param {string} rawPath
 * @param {string} [userId]
 * @param {string} [lastPickerPath]
 * @returns {string[]}
 */
export function pathCandidates(rawPath, userId, lastPickerPath) {
  const out = [];
  const add = (p) => {
    const s = normalizePickerPath(p, userId);
    if (!s || out.includes(s)) {
      return;
    }
    out.push(s);
  };

  let path = normalizePickerPath(rawPath, userId);
  if (!path || path === '/') {
    path = normalizePickerPath(lastPickerPath || '', userId);
  }
  if (path && path !== '/') {
    add(path);
  }
  const bare = String(rawPath || '').trim().replace(/^\/+/, '');
  if (bare) {
    add('/' + bare);
  }
  return out;
}
