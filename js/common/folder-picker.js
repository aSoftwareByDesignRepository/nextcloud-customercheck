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
      out = out.replace(/%(?:\d+\$)?s/g, function () {
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
    const raw = info.id ?? info.fileid ?? info.fileId;
    const id = parseInt(String(raw ?? ''), 10);
    return Number.isFinite(id) && id > 0 ? id : null;
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
      OC.Files.getClient().getFileInfo(clientPath).then((_status, info) => {
        resolve(info);
      }).fail(() => {
        reject(new Error('get_file_info_failed'));
      });
    });
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
   * @param {string} pickedPath
   * @returns {Promise<{ fileId: number|null, path: string, name?: string }|null>}
   */
  function resolveFileSelection(pickedPath) {
    const normalized = normalizePickerPath(pickedPath);
    if (!normalized || normalized === '/') {
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
      if (!info) return null;
      if (isDirectoryInfo(info)) {
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
      const fileId = fileIdFromInfo(info);
      const name = String((info && (info.name || info.displayname || info.basename)) || rel.split('/').pop() || '');
      return { fileId: fileId, path: normalized, name: name };
    });
  }

  function openModernFilePicker(mimeFilter, title) {
    return import('@nextcloud/dialogs').then((dialogs) => {
      if (!dialogs || typeof dialogs.getFilePickerBuilder !== 'function') {
        return undefined;
      }
      const typeChoose = (dialogs.FilePickerType && dialogs.FilePickerType.Choose) || 1;
      const filter = Array.isArray(mimeFilter) && mimeFilter.length ? mimeFilter : ['*'];
      const picker = dialogs.getFilePickerBuilder(title || t('Select a file'))
        .setMultiSelect(false)
        .setMimeTypeFilter(filter)
        .allowDirectories(false)
        .setType(typeChoose)
        .build();
      return picker.pick().then((pickedPath) => {
        if (!pickedPath) return null;
        return resolveFileSelection(pickedPath);
      });
    }).catch(() => undefined);
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
      const filter = Array.isArray(mimeFilter) && mimeFilter.length ? mimeFilter : null;
      try {
        OC.dialogs.filepicker(
          title || t('Select a file'),
          (path) => {
            resolveFileSelection(path).then((selection) => finish(selection));
          },
          false,
          filter,
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
    const mimeTypes = opts.mimeTypes || ['application/pdf'];
    const title = opts.title || t('Select a file');
    return openModernFilePicker(mimeTypes, title).then((modernResult) => {
      if (modernResult !== undefined) {
        return modernResult === null ? null : (modernResult || { unavailable: true });
      }
      const legacyAvailable = !!(window.OC && OC.dialogs && typeof OC.dialogs.filepicker === 'function');
      if (!legacyAvailable) {
        return { unavailable: true };
      }
      return openLegacyFilePicker(mimeTypes, title).then((selection) => selection);
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
    resetIntroPreference: function () {
      try { localStorage.removeItem(INTRO_SKIP_KEY); } catch (_) { /* ignore */ }
    },
  };
})();
