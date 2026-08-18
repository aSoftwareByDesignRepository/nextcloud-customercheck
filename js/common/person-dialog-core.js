/**
 * Pure helpers for the company-360 Add / Change person native dialog.
 * No DOM bootstrap — vitest + mutation friendly. Classic script for Util::addScript.
 *
 * Security: never trust caller strings as HTML; callers must use textContent / value.
 * Race: save-busy must block dismiss; Contacts confirm must not treat a closed dialog as open.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const PHONE_RE = /^[0-9+()\/.\s-]{4,64}$/;
  /** openModal focuses after 50ms — wait past that so Name, not Close, gets focus. */
  const FOCUS_AFTER_OPEN_MS = 80;
  const DIALOG_ID = 'crm-person-dialog';
  const FORM_ID = 'crm-person-form';

  /**
   * @param {unknown} v
   * @returns {string}
   */
  function trimStr(v) {
    return typeof v === 'string' ? v.trim() : '';
  }

  /**
   * @param {{ name?: unknown, email?: unknown, phone?: unknown }} fields
   * @returns {{ ok: boolean, code: null|'name'|'email'|'phone', name: string, email: string, phone: string }}
   */
  function validatePersonFields(fields) {
    const input = fields && typeof fields === 'object' ? fields : {};
    const name = trimStr(input.name);
    const email = trimStr(input.email);
    const phone = trimStr(input.phone);
    if (name === '') {
      return { ok: false, code: 'name', name: name, email: email, phone: phone };
    }
    if (email !== '' && !EMAIL_RE.test(email)) {
      return { ok: false, code: 'email', name: name, email: email, phone: phone };
    }
    if (phone !== '' && !PHONE_RE.test(phone)) {
      return { ok: false, code: 'phone', name: name, email: email, phone: phone };
    }
    return { ok: true, code: null, name: name, email: email, phone: phone };
  }

  /**
   * @param {unknown} role
   * @param {unknown} phone
   * @returns {boolean}
   */
  function shouldOpenAdvanced(role, phone) {
    return trimStr(role) !== '' || trimStr(phone) !== '';
  }

  /**
   * @param {{ name: string, email: string, phone: string, role?: unknown }} fields
   * @param {unknown} contactsUid
   * @returns {Record<string, unknown>}
   */
  function buildCreatePayload(fields, contactsUid) {
    const payload = {
      name: fields && fields.name ? String(fields.name) : '',
      email: fields && fields.email ? String(fields.email) : '',
      phone: fields && fields.phone ? String(fields.phone) : '',
      role: trimStr(fields && fields.role),
      isPrimary: false,
    };
    const uid = trimStr(contactsUid);
    if (uid !== '') {
      payload.contactsUid = uid;
    }
    return payload;
  }

  /**
   * @param {{ name: string, email: string, phone: string, role?: unknown }} fields
   * @param {unknown} updatedAt
   * @returns {Record<string, unknown>}
   */
  function buildUpdatePayload(fields, updatedAt) {
    return {
      name: fields && fields.name ? String(fields.name) : '',
      email: fields && fields.email ? String(fields.email) : '',
      phone: fields && fields.phone ? String(fields.phone) : '',
      role: trimStr(fields && fields.role),
      updatedAt: updatedAt || null,
    };
  }

  /**
   * Native <dialog>.open is authoritative; the [open] attribute is a fallback.
   *
   * @param {unknown} dialog
   * @returns {boolean}
   */
  function isNativeDialogOpen(dialog) {
    if (!dialog || typeof dialog !== 'object') {
      return false;
    }
    if (dialog.open === true) {
      return true;
    }
    if (typeof dialog.hasAttribute === 'function' && dialog.hasAttribute('open')) {
      return true;
    }
    return false;
  }

  /**
   * Contacts auto-fill must not run on Change person, while saving, or after close.
   *
   * @param {unknown} mode
   * @param {unknown} saveBusy
   * @param {unknown} dialogOpen
   * @returns {boolean}
   */
  function shouldSuggestContacts(mode, saveBusy, dialogOpen) {
    return mode === 'add' && saveBusy !== true && dialogOpen === true;
  }

  /**
   * @param {unknown} saveBusy
   * @returns {boolean}
   */
  function shouldBlockDialogDismiss(saveBusy) {
    return saveBusy === true;
  }

  /**
   * Move the dialog under body so page `inert` / hidden People tab cannot trap it.
   *
   * @param {unknown} dialog
   * @param {unknown} body
   * @returns {boolean} true when moved
   */
  function hostDialogOnBody(dialog, body) {
    if (!dialog || !body || typeof body.appendChild !== 'function') {
      return false;
    }
    if (dialog.parentElement === body) {
      return false;
    }
    body.appendChild(dialog);
    return true;
  }

  /**
   * Persist so Contacts confirmDialog does not tear down this form.
   *
   * @param {unknown} dialog
   * @returns {boolean}
   */
  function markPersist(dialog) {
    if (!dialog || !dialog.dataset) {
      return false;
    }
    dialog.dataset.azcModalPersist = 'true';
    dialog.dataset.azcFocusTrapStandalone = '1';
    return true;
  }

  /**
   * @param {unknown} dialog
   * @param {boolean} allow
   */
  function setDismissible(dialog, allow) {
    if (!dialog || !dialog.dataset) {
      return;
    }
    if (allow) {
      delete dialog.dataset.azcModalDismiss;
    } else {
      dialog.dataset.azcModalDismiss = 'false';
    }
  }

  const api = {
    EMAIL_RE: EMAIL_RE,
    PHONE_RE: PHONE_RE,
    FOCUS_AFTER_OPEN_MS: FOCUS_AFTER_OPEN_MS,
    DIALOG_ID: DIALOG_ID,
    FORM_ID: FORM_ID,
    trimStr: trimStr,
    validatePersonFields: validatePersonFields,
    shouldOpenAdvanced: shouldOpenAdvanced,
    buildCreatePayload: buildCreatePayload,
    buildUpdatePayload: buildUpdatePayload,
    isNativeDialogOpen: isNativeDialogOpen,
    shouldSuggestContacts: shouldSuggestContacts,
    shouldBlockDialogDismiss: shouldBlockDialogDismiss,
    hostDialogOnBody: hostDialogOnBody,
    markPersist: markPersist,
    setDismissible: setDismissible,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckPersonDialogCore = api;
})(typeof window !== 'undefined' ? window : globalThis);
