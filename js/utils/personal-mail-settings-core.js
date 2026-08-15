/**
 * Pure helpers for My email settings (connect / reconnect / update connection).
 * Browser mirror: js/common/personal-mail-settings-core.js
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/**
 * @param {unknown} box
 * @returns {boolean}
 */
export function needsPersonalMailReconnect(box) {
  if (!box || typeof box !== 'object') {
    return false;
  }
  const row = /** @type {Record<string, unknown>} */ (box);
  const st = String(row.status || 'ok').toLowerCase();
  if (st === 'error' || st === 'auth' || st === 'auth_error') {
    return true;
  }
  const fails = parseInt(String(row.authFailCount ?? row.auth_fail_count ?? 0), 10);
  return Number.isFinite(fails) && fails >= 3;
}

/**
 * @param {unknown} box
 * @returns {boolean}
 */
export function isPersonalMailOAuth(box) {
  if (!box || typeof box !== 'object') {
    return false;
  }
  const row = /** @type {Record<string, unknown>} */ (box);
  const authKind = String(row.authKind ?? row.auth_kind ?? 'imap').toLowerCase();
  return authKind === 'google' || authKind === 'microsoft' || authKind.indexOf('oauth') >= 0;
}

/**
 * Show IMAP/OAuth connect strip: first connect, auth quarantine, or voluntary edit.
 *
 * @param {{ box?: unknown, editConnection?: boolean }} input
 * @returns {boolean}
 */
export function showPersonalMailConnectForm(input) {
  const box = input && input.box;
  if (!box) {
    return true;
  }
  if (needsPersonalMailReconnect(box)) {
    return true;
  }
  return !!input.editConnection;
}

/**
 * Quarantine hides folders/signature/Sync. Voluntary “update connection” keeps them.
 *
 * @param {unknown} box
 * @returns {boolean}
 */
export function hidePersonalMailOpsForReconnect(box) {
  return needsPersonalMailReconnect(box);
}

/**
 * Heal in place only when form email matches the existing personal box.
 *
 * @param {unknown} box
 * @param {string} formEmail
 * @returns {number} mailbox id or 0
 */
export function personalMailHealId(box, formEmail) {
  if (!box || typeof box !== 'object') {
    return 0;
  }
  const row = /** @type {Record<string, unknown>} */ (box);
  const id = parseInt(String(row.id || 0), 10);
  if (!Number.isFinite(id) || id < 1) {
    return 0;
  }
  const boxEmail = String(row.emailAddress || row.email || '').trim().toLowerCase();
  const form = String(formEmail || '').trim().toLowerCase();
  if (boxEmail === '' || form === '' || boxEmail !== form) {
    return 0;
  }
  return id;
}

/**
 * @param {{ box?: unknown, editConnection?: boolean, needsReconnect?: boolean }} input
 * @param {(msg: string) => string} t
 * @returns {string}
 */
export function personalConnectSubmitLabel(input, t) {
  const translate = typeof t === 'function' ? t : (m) => m;
  if (input && input.needsReconnect) {
    return translate('Save and reconnect');
  }
  if (input && input.box && input.editConnection) {
    return translate('Save connection');
  }
  return translate('Connect my email');
}

/**
 * @param {unknown} box
 * @param {(msg: string) => string} t
 * @returns {string}
 */
export function personalEditConnectionButtonLabel(box, t) {
  const translate = typeof t === 'function' ? t : (m) => m;
  if (isPersonalMailOAuth(box)) {
    return translate('Reconnect account');
  }
  return translate('Update password or servers');
}
