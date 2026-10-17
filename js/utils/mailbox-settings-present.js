/**
 * Pure mailbox settings presenters (vitest + settings.js bootstrap).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * @param {unknown} status
 * @returns {'success'|'warning'|'danger'|'neutral'}
 */
export function mailboxStatusTone(status) {
  switch (String(status || '')) {
    case 'ok':
      return 'success';
    case 'warn':
    case 'warning':
      return 'warning';
    case 'error':
      return 'danger';
    default:
      return 'neutral';
  }
}

/**
 * @param {unknown} status
 * @param {(msg: string, args?: string[]) => string} t
 * @returns {string}
 */
export function mailboxStatusLabel(status, t) {
  switch (String(status || '')) {
    case 'ok':
      return t('Connected');
    case 'warn':
    case 'warning':
      return t('Needs attention');
    case 'error':
      return t('Connection error');
    default:
      return t('Unknown');
  }
}

/**
 * @param {unknown} raw
 * @returns {string}
 */
function formatMailboxSyncAt(raw) {
  const value = String(raw || '').trim();
  if (!value) {
    return '';
  }
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) {
    return value;
  }
  try {
    const locale = (typeof document !== 'undefined' && document.documentElement && document.documentElement.lang)
      || (typeof navigator !== 'undefined' && navigator.language)
      || undefined;
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(d);
  } catch (e) {
    return value;
  }
}

/**
 * Build list-row models for connected mailboxes (no secrets).
 *
 * @param {unknown} items
 * @param {(msg: string, args?: string[]) => string} t
 * @returns {Array<{
 *   id: number,
 *   label: string,
 *   email: string,
 *   status: string,
 *   statusLabel: string,
 *   tone: string,
 *   meta: string,
 *   lastError: string,
 * }>}
 */
export function buildMailboxListRows(items, t) {
  const list = Array.isArray(items) ? items : [];
  return list.map((raw) => {
    const m = raw && typeof raw === 'object' ? raw : {};
    const status = String(m.status || '');
    const lastError = String(m.lastError || '').trim();
    const lastSync = m.lastSyncAt ? formatMailboxSyncAt(m.lastSyncAt) : '';
    const metaParts = [];
    if (lastSync) {
      metaParts.push(t('Last sync: %s', [lastSync]));
    }
    if (Number(m.authFailCount || 0) > 0) {
      metaParts.push(t('%s failed logins', [String(m.authFailCount)]));
    }
    return {
      id: Math.max(0, parseInt(String(m.id || 0), 10) || 0),
      label: String(m.label || '').trim() || t('Shared mailbox'),
      email: String(m.emailAddress || m.email || '').trim(),
      status,
      statusLabel: mailboxStatusLabel(status, t),
      tone: mailboxStatusTone(status),
      meta: metaParts.join(' · '),
      lastError,
    };
  }).filter((row) => row.id > 0);
}

/**
 * Merge remote folder options with previously selected paths.
 *
 * @param {unknown} remoteFolders
 * @param {unknown} selectedFolders
 * @param {number} [max=20]
 * @returns {Array<{ path: string, checked: boolean }>}
 */
export function buildFolderPickerOptions(remoteFolders, selectedFolders, max = 20) {
  const remote = Array.isArray(remoteFolders)
    ? remoteFolders.map((f) => String(f || '').trim()).filter(Boolean)
    : [];
  const selected = Array.isArray(selectedFolders)
    ? selectedFolders.map((f) => String(f || '').trim()).filter(Boolean)
    : [];
  const selectedSet = new Set(selected);
  const seen = new Set();
  /** @type {Array<{ path: string, checked: boolean }>} */
  const out = [];
  const push = (path, checked) => {
    if (!path || seen.has(path) || out.length >= max) return;
    seen.add(path);
    out.push({ path, checked: !!checked });
  };
  // Prefer remote order; keep previously selected even if missing from remote (edit before re-test).
  remote.forEach((path) => push(path, selectedSet.size ? selectedSet.has(path) : path === 'INBOX'));
  selected.forEach((path) => push(path, true));
  if (out.length === 0) {
    push('INBOX', true);
  } else if (!out.some((o) => o.checked)) {
    const inbox = out.find((o) => o.path === 'INBOX');
    if (inbox) inbox.checked = true;
    else out[0].checked = true;
  }
  return out;
}

/**
 * Empty-state visibility: only a successful load with zero rows may show the
 * "No shared mailbox yet" panel — a failed load keeps error chrome only
 * (showing the empty panel on failure would fabricate an empty state).
 * @param {unknown} rowCount
 * @param {unknown} loadFailed
 * @returns {boolean}
 */
export function mailboxEmptyVisible(rowCount, loadFailed) {
  if (loadFailed) return false;
  return (parseInt(String(rowCount || 0), 10) || 0) <= 0;
}

/**
 * @param {ParentNode|null|undefined} root
 * @returns {string[]}
 */
export function readCheckedFolderPaths(root) {
  if (!root || typeof root.querySelectorAll !== 'function') {
    return [];
  }
  return Array.from(root.querySelectorAll('input[type="checkbox"][name="folders"]:checked'))
    .map((el) => String(/** @type {HTMLInputElement} */ (el).value || '').trim())
    .filter(Boolean);
}

