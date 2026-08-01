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
  const raw = node.id ?? node.fileid ?? node.fileId;
  const id = parseInt(String(raw ?? ''), 10);
  return Number.isFinite(id) && id > 0 ? id : null;
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
