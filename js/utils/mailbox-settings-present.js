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
    const lastSync = m.lastSyncAt ? String(m.lastSyncAt) : '';
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
