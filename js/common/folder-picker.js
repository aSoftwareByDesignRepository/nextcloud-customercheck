/**
 * Browse-and-choose Nextcloud Files folder for CustomerCheck documents.
 *
 * Uses the native FilePicker (browse folders like a desktop file dialog).
 * Falls back to OC.dialogs.filepicker, then returns null so the page can use search.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function () {
  'use strict';

  const CANCEL_RE = /FilePicker:\s*No nodes selected/i;
  const INTRO_SKIP_KEY = 'customercheck_skip_folder_intro';
  const PICKER_MIME_FILTER = ['*', 'httpd/unix-directory'];
  const PICKER_SAFETY_MS = 10 * 60 * 1000;

  const t = (msg, args) => {
    const list = Array.isArray(args) ? args : [];
    let out = window.t ? window.t('customercheck', msg, list) : msg;
    // Nextcloud t() sometimes leaves %s / %1$s — fill locally (same as settings.js).
    if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
      let i = 0;
      out = out.replace(/%%|%(?:\d+\$)?s/g, function (m) {
        if (m === '%%') { return '%'; }
        const v = list[i];
        i += 1;
        return v == null ? '' : String(v);
      });
    }
    return out;
  };

  function isPickerCancelled(reason) {
    if (!reason) return false;
    if (typeof reason === 'string') return CANCEL_RE.test(reason);
    return CANCEL_RE.test((reason && reason.message) || '');
  }

  function isDirectoryInfo(info) {
    if (!info) return false;
    if (typeof info.isDirectory === 'function') {
      try {
        if (info.isDirectory()) return true;
      } catch (_) { /* ignore */ }
    }
    if (info.type === 'directory' || info.type === 'dir') return true;
    const mime = String(info.mimetype || info.mime || '').toLowerCase();
    return mime === 'httpd/unix-directory';
  }

  function fileIdFromInfo(info) {
    if (!info) return null;
    const attrs = (info.attributes && typeof info.attributes === 'object') ? info.attributes : null;
    const raw = info.id
      ?? info.fileid
      ?? info.fileId
      ?? (attrs ? (attrs.fileid ?? attrs.fileId ?? attrs.id) : null);
    const id = parseInt(String(raw ?? ''), 10);
    return Number.isFinite(id) && id > 0 ? id : null;
  }

  /**
   * Normalize FilePicker pick() / pickNodes() results (path string, array, or Node-like object).
   * @param {unknown} raw
   * @returns {{ path: string, fileId: number|null, name: string }|null}
   */
  function extractPickerPickResult(raw) {
    if (raw == null || raw === false || raw === '') return null;
    let item = raw;
    if (Array.isArray(raw)) {
      if (!raw.length) return null;
      item = raw[0];
    }
    if (typeof item === 'string' || typeof item === 'number') {
      const path = String(item).trim();
      if (!path) return null;
      const parts = path.split('/').filter(Boolean);
      const name = parts.length ? parts[parts.length - 1] : path;
      return { path: path, fileId: null, name: name };
    }
    if (typeof item !== 'object') return null;
    const fileId = fileIdFromInfo(item);
    let path = '';
    if (typeof item.path === 'string' && item.path.trim() !== '') {
      path = String(item.path).trim();
    } else if (typeof item.dirname === 'string' && typeof item.basename === 'string') {
      const dir = String(item.dirname).replace(/\/+$/, '');
      const base = String(item.basename).replace(/^\/+/, '');
      path = (dir === '/' || dir === '' ? '' : dir) + '/' + base;
    } else if (typeof item.source === 'string' && item.source.trim() !== '') {
      path = String(item.source).trim();
    } else if (typeof item.href === 'string' && item.href.trim() !== '') {
      path = String(item.href).trim();
    }
    const name = String(
      item.basename || item.displayname || item.name || item.filename
        || (item.attributes && (item.attributes.displayname || item.attributes.displayName)) || '',
    ).trim()
      || (path ? path.split('/').filter(Boolean).pop() : '')
      || (fileId ? ('file ' + fileId) : '');
    if (!path && !(fileId && fileId > 0)) return null;
    return { path: path, fileId: fileId, name: name };
  }

  function selectionFromPickerNode(node) {
    if (!node || typeof node !== 'object') return null;
    if (isDirectoryInfo(node)) return null;
    const extracted = extractPickerPickResult(node);
    if (!extracted || !(extracted.fileId > 0)) return null;
    return {
      fileId: extracted.fileId,
      path: extracted.path || '/',
      name: extracted.name || ('file ' + extracted.fileId),
    };
  }

  function currentUserId() {
    try {
      if (window.OC && typeof OC.getCurrentUser === 'function') {
        const user = OC.getCurrentUser();
        if (user && user.uid) return String(user.uid);
      }
    } catch (_) { /* ignore */ }
    return '';
  }

  function normalizePickerPath(rawPath) {
    let path = String(rawPath || '').trim();
    if (!path) return '';
    try {
      if (/^https?:\/\//i.test(path)) {
        path = new URL(path, window.location.origin).pathname;
      }
    } catch (_) { /* keep */ }

    const uid = currentUserId();
    if (uid) {
      ['/remote.php/dav/files/' + uid, '/' + uid + '/files'].forEach((prefix) => {
        if (path.toLowerCase().startsWith(prefix.toLowerCase())) {
          path = path.slice(prefix.length);
        }
      });
    }
    path = path.replace(/^\/+/, '').replace(/\/+$/, '');
    return path ? '/' + path : '/';
  }

  function isLinkableFolderPath(normalizedPath) {
    const path = String(normalizedPath || '').trim();
    return path !== '' && path !== '/';
  }

  function lastPickerPath() {
    try {
      return normalizePickerPath(sessionStorage.getItem('NC.FilePicker.LastPath') || '');
    } catch (_) {
      return '';
    }
  }

  function pathCandidates(rawPath) {
    const out = [];
    const add = (p) => {
      const s = normalizePickerPath(p);
      if (!s || out.indexOf(s) !== -1) return;
      out.push(s);
    };
    let path = normalizePickerPath(rawPath);
    if (!path || path === '/') path = lastPickerPath();
    if (path && path !== '/') add(path);
    const bare = String(rawPath || '').trim().replace(/^\/+/, '');
    if (bare) add('/' + bare);
    return out;
  }

  function getFileInfoAsync(path) {
    return new Promise((resolve, reject) => {
      if (!window.OC || !OC.Files || !OC.Files.getClient) {
        reject(new Error('files_client_unavailable'));
        return;
      }
      const clientPath = path.startsWith('/') ? path : '/' + path;
      try {
        OC.Files.getClient().getFileInfo(clientPath).then((_status, info) => {
          resolve(info);
        }).fail(() => {
          reject(new Error('get_file_info_failed'));
        });
      } catch (err) {
        reject(err || new Error('get_file_info_failed'));
      }
    });
  }

  /**
   * Modern NC often lacks OC.Files.getClient — resolve fileid via WebDAV PROPFIND.
   * @param {string} normalizedPath user-relative path starting with /
   * @returns {Promise<number|null>}
   */
  function resolveFileIdViaDav(normalizedPath) {
    const uid = currentUserId();
    const path = String(normalizedPath || '').trim();
    if (!uid || !path || path === '/') {
      return Promise.resolve(null);
    }
    let base = '';
    try {
      if (window.OC && typeof OC.linkToRemoteBase === 'function') {
        base = String(OC.linkToRemoteBase('dav') || '');
      }
    } catch (_) { /* ignore */ }
    if (!base && window.OC && typeof OC.generateUrl === 'function') {
      base = String(OC.generateUrl('/remote.php/dav') || '');
    }
    if (!base) {
      base = '/remote.php/dav';
    }
    const rel = path.replace(/^\/+/, '');
    const url = base.replace(/\/+$/, '') + '/files/' + encodeURIComponent(uid) + '/'
      + rel.split('/').map(encodeURIComponent).join('/');
    const token = (window.OC && OC.requestToken) ? String(OC.requestToken) : '';
    const body = '<?xml version="1.0"?>'
      + '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
      + '<d:prop><oc:fileid/><d:displayname/></d:prop></d:propfind>';
    return fetch(url, {
      method: 'PROPFIND',
      credentials: 'same-origin',
      headers: {
        Depth: '0',
        'Content-Type': 'application/xml; charset=utf-8',
        ...(token ? { requesttoken: token } : {}),
      },
      body: body,
    }).then(function (res) {
      if (!res || !res.ok) {
        return null;
      }
      return res.text();
    }).then(function (xml) {
      if (!xml) {
        return null;
      }
      const m = String(xml).match(/<(?:[\w-]+:)?fileid[^>]*>\s*(\d+)\s*<\/(?:[\w-]+:)?fileid>/i);
      if (!m) {
        return null;
      }
      const id = parseInt(m[1], 10);
      return Number.isFinite(id) && id > 0 ? id : null;
    }).catch(function () {
      return null;
    });
  }

  function alertCouldNotOpenFile() {
    const C = window.CustomerCheckComponents || window.CrmComponents;
    if (C && typeof C.alertDialog === 'function') {
      return C.alertDialog({
        title: t('Could not open that file'),
        message: t('Pick the file again from Files, or choose another file.'),
        variant: 'warning',
      }).then(function () { return null; });
    }
    return Promise.resolve(null);
  }

  /**
   * Empty / '*' = all files. Drop aliases like image/jpg. Server still enforces allow-list.
   * @param {unknown} mimeFilter
   * @returns {string[]}
   */
  function normalizeMimeTypeFilter(mimeFilter) {
    if (!Array.isArray(mimeFilter) || !mimeFilter.length) {
      return [];
    }
    const out = [];
    const seen = Object.create(null);
    mimeFilter.forEach(function (raw) {
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

  function resolveFolderFileId(rawPath) {
    const candidates = pathCandidates(rawPath);
    let chain = Promise.resolve(null);
    candidates.forEach((candidate) => {
      chain = chain.then((found) => {
        if (found) return found;
        const rel = candidate.replace(/^\/+/, '');
        const tries = [candidate, '/' + rel, rel];
        let inner = Promise.resolve(null);
        tries.forEach((tryPath) => {
          inner = inner.then((id) => {
            if (id) return id;
            return getFileInfoAsync(tryPath).then((info) => {
              if (!isDirectoryInfo(info)) return null;
              return fileIdFromInfo(info);
            }).catch(() => null);
          });
        });
        return inner;
      });
    });
    return chain;
  }

  function shouldShowIntro() {
    try {
      return localStorage.getItem(INTRO_SKIP_KEY) !== '1';
    } catch (_) {
      return true;
    }
  }

  function showFolderPickerIntro() {
    const C = window.CustomerCheckComponents || window.CrmComponents;
    if (!C || typeof C.confirmDialog !== 'function') {
      return Promise.resolve(true);
    }
    return C.confirmDialog({
      title: t('Link a Files folder'),
      message: t('Browse your folders like on your computer. Open the folder you want, then click the Choose button at the bottom of the dialog — not a single file.'),
      confirmLabel: t('Browse folders'),
      cancelLabel: t('Cancel'),
      variant: 'info',
    }).then((ok) => {
      if (ok) {
        try {
          localStorage.setItem(INTRO_SKIP_KEY, '1');
        } catch (_) { /* ignore */ }
      }
      return !!ok;
    });
  }

  /**
   * @param {string} pickedPath
   * @returns {Promise<{ fileId: number|null, path: string }|null>}
   */
  function resolvePickerSelection(pickedPath) {
    const normalized = normalizePickerPath(pickedPath);
    if (!isLinkableFolderPath(normalized)) {
      const C = window.CustomerCheckComponents || window.CrmComponents;
      if (C && typeof C.alertDialog === 'function') {
        return C.alertDialog({
          title: t('Pick a customer folder'),
          message: t('Your whole Files home is too broad. Open a specific folder (for example Contracts or the customer name), then click Choose.'),
          variant: 'warning',
        }).then(() => null);
      }
      return Promise.resolve(null);
    }

    const rel = normalized.replace(/^\/+/, '');
    const tryPaths = [normalized, '/' + rel, rel];
    let infoChain = Promise.resolve(null);
    tryPaths.forEach((tryPath) => {
      infoChain = infoChain.then((found) => {
        if (found) return found;
        return getFileInfoAsync(tryPath).catch(() => null);
      });
    });

    return infoChain.then((info) => {
      if (info && !isDirectoryInfo(info)) {
        const C = window.CustomerCheckComponents || window.CrmComponents;
        if (C && typeof C.alertDialog === 'function') {
          return C.alertDialog({
            title: t('That is a file'),
            message: t('Open the folder that contains it, then click Choose at the bottom.'),
            variant: 'warning',
          }).then(() => null);
        }
        return null;
      }
      return resolveFolderFileId(normalized).then((fileId) => ({
        fileId: fileId,
        path: normalized,
      }));
    });
  }

  function openModernFolderPicker() {
    return import('@nextcloud/dialogs').then((dialogs) => {
      if (!dialogs || typeof dialogs.getFilePickerBuilder !== 'function') {
        return undefined;
      }
      const typeChoose = (dialogs.FilePickerType && dialogs.FilePickerType.Choose) || 1;
      const picker = dialogs.getFilePickerBuilder(t('Select a folder'))
        .setMultiSelect(false)
        .setMimeTypeFilter(PICKER_MIME_FILTER)
        .allowDirectories(true)
        .setType(typeChoose)
        .build();
      return picker.pick().then((pickedPath) => {
        if (!pickedPath) return null;
        return resolvePickerSelection(pickedPath);
      });
    }).catch(() => undefined);
  }

  function openLegacyFolderPicker() {
    return new Promise((resolve) => {
      if (!window.OC || !OC.dialogs || typeof OC.dialogs.filepicker !== 'function') {
        resolve(null);
        return;
      }

      let settled = false;
      const finish = (selection) => {
        if (settled) return;
        settled = true;
        window.removeEventListener('unhandledrejection', onPickerDismiss);
        clearTimeout(safetyTimer);
        resolve(selection);
      };

      const onPickerDismiss = (event) => {
        if (!isPickerCancelled(event.reason)) return;
        event.preventDefault();
        finish(null);
      };
      window.addEventListener('unhandledrejection', onPickerDismiss);
      const safetyTimer = window.setTimeout(() => finish(null), PICKER_SAFETY_MS);

      const typeChoose = OC.dialogs.FILEPICKER_TYPE_CHOOSE || 1;
      try {
        OC.dialogs.filepicker(
          t('Select a folder'),
          (path) => {
            resolvePickerSelection(path).then((selection) => finish(selection));
          },
          false,
          PICKER_MIME_FILTER,
          true,
          typeChoose,
          '',
          { allowDirectoryChooser: true },
        );
      } catch (err) {
        finish(null);
      }
    });
  }

	function openNativeFolderPicker() {
    return openModernFolderPicker().then((modernResult) => {
      if (modernResult !== undefined) {
        return { ran: true, selection: modernResult };
      }
      const legacyAvailable = !!(window.OC && OC.dialogs && typeof OC.dialogs.filepicker === 'function');
      if (!legacyAvailable) {
        return { ran: false, selection: null };
      }
      return openLegacyFolderPicker().then((selection) => ({
        ran: true,
        selection: selection,
      }));
    });
  }

  /**
   * @param {{ skipIntro?: boolean }} [options]
   * @returns {Promise<{ fileId: number|null, path: string }|{ unavailable: true }|null>}
   *   selection object on success, null on cancel, { unavailable: true } when no picker UI exists
   */
  function pickFolder(options) {
    const opts = options || {};
    const runIntro = !opts.skipIntro && shouldShowIntro();
    return (runIntro ? showFolderPickerIntro() : Promise.resolve(true))
      .then((proceed) => {
        if (!proceed) {
          return null;
        }
        return openNativeFolderPicker().then((result) => {
          if (!result || !result.ran) {
            return { unavailable: true };
          }
          return result.selection;
        });
      });
  }

  /**
   * @param {unknown} pickedRaw
   * @returns {Promise<{ fileId: number|null, path: string, name?: string }|null>}
   */
  function resolveFileSelection(pickedRaw) {
    const fromNode = selectionFromPickerNode(pickedRaw);
    if (fromNode) {
      return Promise.resolve(fromNode);
    }
    const extracted = extractPickerPickResult(pickedRaw);
    if (!extracted) {
      return Promise.resolve(null);
    }
    // Modern FilePicker Node may already carry fileid (pickNodes / Node-like pick).
    if (extracted.fileId && extracted.fileId > 0 && !isDirectoryInfo(pickedRaw)) {
      const path = extracted.path ? normalizePickerPath(extracted.path) : '';
      return Promise.resolve({
        fileId: extracted.fileId,
        path: path || '/',
        name: extracted.name || ('file ' + extracted.fileId),
      });
    }
    const normalized = normalizePickerPath(extracted.path);
    if (!normalized || normalized === '/') {
      return alertCouldNotOpenFile();
    }
    const rel = normalized.replace(/^\/+/, '');
    const tryPaths = [normalized, '/' + rel, rel];
    let infoChain = Promise.resolve(null);
    tryPaths.forEach((tryPath) => {
      infoChain = infoChain.then((found) => {
        if (found) return found;
        return getFileInfoAsync(tryPath).catch(() => null);
      });
    });

    return infoChain.then((info) => {
      if (info && isDirectoryInfo(info)) {
        const C = window.CustomerCheckComponents || window.CrmComponents;
        if (C && typeof C.alertDialog === 'function') {
          return C.alertDialog({
            title: t('That is a folder'),
            message: t('Pick a file (for example a PDF), not a folder.'),
            variant: 'warning',
          }).then(() => null);
        }
        return null;
      }
      const nameFromInfo = info
        ? String(info.name || info.displayname || info.basename || '')
        : '';
      const finish = function (id) {
        if (!(id > 0)) {
          return alertCouldNotOpenFile();
        }
        return {
          fileId: id,
          path: normalized,
          name: nameFromInfo || extracted.name || rel.split('/').pop() || ('file ' + id),
        };
      };
      const fileId = fileIdFromInfo(info);
      if (fileId > 0) {
        return finish(fileId);
      }
      // Path-only pick() on modern NC: no OC.Files.getClient — resolve via DAV.
      return resolveFileIdViaDav(normalized).then(finish);
    });
  }

  /**
   * @returns {Promise<null|{ fileId: number, path: string, name?: string }|{ __modernUnavailable: true }>}
   */
  function openModernFilePicker(mimeFilter, title) {
    return import('@nextcloud/dialogs').then((dialogs) => {
      if (!dialogs || typeof dialogs.getFilePickerBuilder !== 'function') {
        return { __modernUnavailable: true };
      }
      const typeChoose = (dialogs.FilePickerType && dialogs.FilePickerType.Choose) || 1;
      // Empty filter = all files (server still enforces mail attach MIME allow-list).
      const filter = normalizeMimeTypeFilter(mimeFilter);
      const picker = dialogs.getFilePickerBuilder(title || t('Select a file'))
        .setMultiSelect(false)
        .setMimeTypeFilter(filter)
        .allowDirectories(false)
        .setType(typeChoose)
        .build();

      // Prefer pickNodes() — Nodes carry fileid. pick() only returns paths and needs a
      // secondary lookup that often fails on modern Nextcloud (no OC.Files.getClient).
      const run = (typeof picker.pickNodes === 'function')
        ? picker.pickNodes().then(function (nodes) {
          if (!Array.isArray(nodes) || !nodes.length) {
            return null;
          }
          return resolveFileSelection(nodes[0]);
        })
        : picker.pick().then(function (pickedPath) {
          if (!pickedPath) {
            return null;
          }
          return resolveFileSelection(pickedPath);
        });

      return run.catch(function (err) {
        // Cancel must NOT fall through to the legacy picker (double-dialog hell).
        if (isPickerCancelled(err)) {
          return null;
        }
        return null;
      });
    }).catch(() => ({ __modernUnavailable: true }));
  }

  function openLegacyFilePicker(mimeFilter, title) {
    return new Promise((resolve) => {
      if (!window.OC || !OC.dialogs || typeof OC.dialogs.filepicker !== 'function') {
        resolve(null);
        return;
      }

      let settled = false;
      const finish = (selection) => {
        if (settled) return;
        settled = true;
        window.removeEventListener('unhandledrejection', onPickerDismiss);
        clearTimeout(safetyTimer);
        resolve(selection);
      };

      const onPickerDismiss = (event) => {
        if (!isPickerCancelled(event.reason)) return;
        event.preventDefault();
        finish(null);
      };
      window.addEventListener('unhandledrejection', onPickerDismiss);
      const safetyTimer = window.setTimeout(() => finish(null), PICKER_SAFETY_MS);

      const typeChoose = OC.dialogs.FILEPICKER_TYPE_CHOOSE || 1;
      const filter = normalizeMimeTypeFilter(mimeFilter);
      try {
        OC.dialogs.filepicker(
          title || t('Select a file'),
          (path) => {
            resolveFileSelection(path).then((selection) => finish(selection));
          },
          false,
          filter.length ? filter : null,
          true,
          typeChoose,
        );
      } catch (err) {
        finish(null);
      }
    });
  }

  /**
   * Pick a single file (PDF / attachment) — never a folder.
   *
   * @param {{ mimeTypes?: string[], title?: string }} [options]
   * @returns {Promise<{ fileId: number|null, path: string, name?: string }|{ unavailable: true }|null>}
   */
  function pickFile(options) {
    const opts = options || {};
    // Empty mimeTypes = show all files; callers that need PDF-only pass ['application/pdf'].
    // Mail attach UIs pass [] and rely on server MAIL_ATTACH_ALLOWED_MIMES.
    const mimeTypes = Array.isArray(opts.mimeTypes) ? opts.mimeTypes : ['application/pdf'];
    const title = opts.title || t('Select a file');
    return openModernFilePicker(mimeTypes, title).then((modernResult) => {
      if (modernResult && modernResult.__modernUnavailable) {
        const legacyAvailable = !!(window.OC && OC.dialogs && typeof OC.dialogs.filepicker === 'function');
        if (!legacyAvailable) {
          return { unavailable: true };
        }
        return openLegacyFilePicker(mimeTypes, title);
      }
      // null = cancel / failed resolve; object = selection. Never reopen another picker.
      return modernResult;
    });
  }

  window.CustomerCheckFolderPicker = {
    pickFolder: pickFolder,
    pickFile: pickFile,
    openNativeFolderPicker: openNativeFolderPicker,
    resolveFolderFileId: resolveFolderFileId,
    normalizePickerPath: normalizePickerPath,
    isLinkableFolderPath: isLinkableFolderPath,
    isPickerCancelled: isPickerCancelled,
    extractPickerPickResult: extractPickerPickResult,
    selectionFromPickerNode: selectionFromPickerNode,
    normalizeMimeTypeFilter: normalizeMimeTypeFilter,
    resetIntroPreference: function () {
      try { localStorage.removeItem(INTRO_SKIP_KEY); } catch (_) { /* ignore */ }
    },
  };
})();
