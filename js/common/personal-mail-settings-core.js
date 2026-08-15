/**
 * Browser bootstrap for personal-mail-settings-core.
 * Keep aligned with js/utils/personal-mail-settings-core.js
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  function needsPersonalMailReconnect(box) {
    if (!box || typeof box !== 'object') {
      return false;
    }
    const st = String(box.status || 'ok').toLowerCase();
    if (st === 'error' || st === 'auth' || st === 'auth_error') {
      return true;
    }
    const fails = parseInt(String(box.authFailCount != null ? box.authFailCount : box.auth_fail_count || 0), 10);
    return Number.isFinite(fails) && fails >= 3;
  }

  function isPersonalMailOAuth(box) {
    if (!box || typeof box !== 'object') {
      return false;
    }
    const authKind = String(box.authKind != null ? box.authKind : box.auth_kind || 'imap').toLowerCase();
    return authKind === 'google' || authKind === 'microsoft' || authKind.indexOf('oauth') >= 0;
  }

  function showPersonalMailConnectForm(input) {
    const box = input && input.box;
    if (!box) {
      return true;
    }
    if (needsPersonalMailReconnect(box)) {
      return true;
    }
    return !!(input && input.editConnection);
  }

  function hidePersonalMailOpsForReconnect(box) {
    return needsPersonalMailReconnect(box);
  }

  function personalMailHealId(box, formEmail) {
    if (!box || typeof box !== 'object') {
      return 0;
    }
    const id = parseInt(String(box.id || 0), 10);
    if (!Number.isFinite(id) || id < 1) {
      return 0;
    }
    const boxEmail = String(box.emailAddress || box.email || '').trim().toLowerCase();
    const form = String(formEmail || '').trim().toLowerCase();
    if (boxEmail === '' || form === '' || boxEmail !== form) {
      return 0;
    }
    return id;
  }

  function personalConnectSubmitLabel(input, t) {
    const translate = typeof t === 'function' ? t : function (m) { return m; };
    if (input && input.needsReconnect) {
      return translate('Save and reconnect');
    }
    if (input && input.box && input.editConnection) {
      return translate('Save connection');
    }
    return translate('Connect my email');
  }

  function personalEditConnectionButtonLabel(box, t) {
    const translate = typeof t === 'function' ? t : function (m) { return m; };
    if (isPersonalMailOAuth(box)) {
      return translate('Reconnect account');
    }
    return translate('Update password or servers');
  }

  global.CustomerCheckPersonalMailSettingsCore = {
    needsPersonalMailReconnect: needsPersonalMailReconnect,
    isPersonalMailOAuth: isPersonalMailOAuth,
    showPersonalMailConnectForm: showPersonalMailConnectForm,
    hidePersonalMailOpsForReconnect: hidePersonalMailOpsForReconnect,
    personalMailHealId: personalMailHealId,
    personalConnectSubmitLabel: personalConnectSubmitLabel,
    personalEditConnectionButtonLabel: personalEditConnectionButtonLabel,
  };
}(typeof window !== 'undefined' ? window : globalThis));
