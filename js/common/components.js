/**
 * Reusable JavaScript Components for ArbeitszeitCheck App
 * Provides modal, toast, and other interactive component functionality
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

const CustomerCheckComponents = {
  _modalLockDepth: 0,
  _modalEscHandlerBound: false,
  /** @type {null|((result: mixed) => void)} */
  _activeConfirmCleanup: null,

  /**
   * Lock nav/main for inline overlays (e.g. calendar day panel). Live regions stay active.
   */
  lockBackground() {
    this._lockPageBehindModal();
  },

  unlockBackground() {
    this._unlockPageBehindModal();
  },

  /**
   * Initialize all components
   */
  init() {
    this.initModals();
    this.initToasts();
    this.relocatePageActions();
    this.wireAllDismissibleHints();
    // Focus rescue across container rebuilds lives in the shared canonical
    // module — apps/_shared/focus-preservation/focus-preservation.js, synced
    // verbatim to js/common/focus-preservation.js (drift-checked). Do not fork.
    window.CheckFocusPreservation.install({ prefix: 'crm' });
  },


  /**
   * DutyCheck-style dismissible Quick start cards (localStorage per user).
   */
  wireDismissibleHint(button) {
    if (!button || button.dataset.crmHintWired === '1') return;
    button.dataset.crmHintWired = '1';
    const key = button.getAttribute('data-crm-dismiss-hint');
    if (!key) return;
    const card = button.closest('.crm-quickstart, .crm-card');
    if (!card) return;
    const uid = this._currentUserId();
    const storageKey = 'crm:hint:' + (uid || 'anon') + ':' + key;
    let dismissed = false;
    try {
      dismissed = window.localStorage.getItem(storageKey) === '1';
    } catch (_) { /* private mode */ }
    if (dismissed) {
      card.hidden = true;
      return;
    }
    card.hidden = false;
    button.addEventListener('click', () => {
      try {
        window.localStorage.setItem(storageKey, '1');
      } catch (_) { /* ignore */ }
      card.hidden = true;
      const live = document.getElementById('crm-live-region');
      if (live) {
        live.textContent = (window.t
          ? window.t('customercheck', 'Tips hidden for this page.')
          : 'Tips hidden for this page.');
      }
    });
  },

  wireAllDismissibleHints(root) {
    const scope = root || document;
    scope.querySelectorAll('[data-crm-dismiss-hint]').forEach((btn) => this.wireDismissibleHint(btn));
  },

  _currentUserId() {
    try {
      if (window.OC && typeof OC.getCurrentUser === 'function') {
        const user = OC.getCurrentUser();
        if (user && user.uid) return String(user.uid);
      }
    } catch (_) { /* ignore */ }
    return '';
  },

  // ===== MODAL COMPONENTS =====

  _getAppContent() {
    return document.getElementById('app-content');
  },

  _getModalInertTargets() {
    const targets = [];
    const header = document.getElementById('header');
    const nav = document.getElementById('app-navigation');
    const main = document.getElementById('crm-main-content');
    const shell = document.getElementById('app-content-wrapper');
    if (header) {
      targets.push(header);
    }
    if (nav) {
      targets.push(nav);
    }
    if (main) {
      targets.push(main);
    } else if (shell) {
      targets.push(shell);
    }
    return targets;
  },

  _lockPageBehindModal() {
    if (++this._modalLockDepth === 1) {
      document.body.style.overflow = 'hidden';
      this._getModalInertTargets().forEach((el) => {
        el.setAttribute('inert', '');
      });
    }
  },

  _unlockPageBehindModal() {
    if (this._modalLockDepth <= 0) {
      this._modalLockDepth = 0;
      return;
    }
    if (--this._modalLockDepth === 0) {
      document.body.style.overflow = '';
      this._getModalInertTargets().forEach((el) => {
        el.removeAttribute('inert');
      });
    }
  },

  // Lock accounting is per-dialog, not per-call: openModal on an
  // already-open dialog must not double-count (its closeModal would
  // release only once, stranding depth>0 — page inert forever), and
  // closeModal on a dialog that never took a lock must not release a
  // lock held by another modal or a lockBackground() overlay.
  _acquireModalLock(modal) {
    if (!modal._crmLockHeld) {
      modal._crmLockHeld = true;
      this._lockPageBehindModal();
    }
  },

  _releaseModalLock(modal) {
    if (modal && modal._crmLockHeld) {
      modal._crmLockHeld = false;
      this._unlockPageBehindModal();
    }
  },

  _getVisibleModalBackdrops() {
    return Array.from(document.querySelectorAll('.modal-backdrop')).filter((backdrop) => {
      if (backdrop.style.display === 'flex') {
        return true;
      }
      const modal = backdrop.querySelector('.modal');
      if (modal && modal.getAttribute('aria-hidden') === 'false') {
        return true;
      }
      const style = window.getComputedStyle(backdrop);
      return style.display !== 'none' && style.visibility !== 'hidden';
    });
  },

  _getOpenNativeDialogs() {
    return Array.from(document.querySelectorAll('dialog.crm-native-dialog[open], dialog.crm-dialog[open]'));
  },

  _isNativeDialog(el) {
    return !!(el && el.tagName === 'DIALOG');
  },

  _getTopmostBackdropModal() {
    const natives = this._getOpenNativeDialogs();
    if (natives.length > 0) {
      return natives[natives.length - 1];
    }
    const backdrops = this._getVisibleModalBackdrops();
    if (backdrops.length === 0) {
      return null;
    }
    const topBackdrop = backdrops[backdrops.length - 1];
    return topBackdrop.querySelector('.modal');
  },

  _bindFocusTrap(modal) {
    if (!modal || modal.dataset.azcFocusTrapBound === '1') {
      return;
    }
    const handler = (e) => {
      if (e.key !== 'Tab') {
        return;
      }
      const inBackdrop = modal.closest('.modal-backdrop');
      const standalone = modal.dataset.azcFocusTrapStandalone === '1' || this._isNativeDialog(modal);
      if (!inBackdrop && !standalone) {
        return;
      }
      const focusable = Array.from(
        modal.querySelectorAll(
          'button:not([disabled]), [href], input:not([disabled]):not([hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
      ).filter((el) => el.offsetParent !== null || el === document.activeElement);
      if (focusable.length === 0) {
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first || !modal.contains(document.activeElement)) {
          e.preventDefault();
          last.focus();
        }
      } else if (document.activeElement === last || !modal.contains(document.activeElement)) {
        e.preventDefault();
        first.focus();
      }
    };
    modal.addEventListener('keydown', handler);
    modal.dataset.azcFocusTrapBound = '1';
    modal._azcFocusTrapHandler = handler;
  },

  _unbindFocusTrap(modal) {
    if (!modal || modal.dataset.azcFocusTrapBound !== '1' || !modal._azcFocusTrapHandler) {
      return;
    }
    modal.removeEventListener('keydown', modal._azcFocusTrapHandler);
    delete modal._azcFocusTrapHandler;
    delete modal.dataset.azcFocusTrapBound;
  },

  /**
   * Initialize modal functionality
   */
  initModals() {
    const modalTriggers = document.querySelectorAll('[data-modal]');

    // init() runs once on DOMContentLoaded AND once per js/pages/*.js
    // boot — per-trigger wiring must be idempotent or every trigger
    // gets two click handlers (double openModal: showModal() on an
    // already-open dialog throws InvalidStateError).
    modalTriggers.forEach(trigger => {
      if (trigger.dataset.crmModalWired === '1') return;
      trigger.dataset.crmModalWired = '1';
      trigger.addEventListener('click', (e) => {
        e.preventDefault();
        const modalId = trigger.dataset.modal;
        this.openModal(modalId);
      });
    });

    if (this._modalDocHandlersBound) return;
    this._modalDocHandlersBound = true;

    // Legacy backdrop click (topmost only) — native dialogs use dialog click / cancel.
    document.addEventListener('click', (e) => {
      if (!e.target.classList.contains('modal-backdrop')) {
        return;
      }
      const backdrops = this._getVisibleModalBackdrops();
      if (backdrops.length === 0 || e.target !== backdrops[backdrops.length - 1]) {
        return;
      }
      const modal = e.target.querySelector('.modal');
      if (modal && modal.dataset.azcModalDismiss !== 'false') {
        this.closeModal(modal);
      }
    });

    if (!this._modalEscHandlerBound) {
      this._modalEscHandlerBound = true;
      document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') {
          return;
        }
        // Native <dialog> handles Escape via the `cancel` event.
        if (this._getOpenNativeDialogs().length > 0) {
          return;
        }
        const modal = this._getTopmostBackdropModal();
        if (modal && modal.dataset.azcModalDismiss !== 'false') {
          e.preventDefault();
          this.closeModal(modal);
        }
      });
    }

    // Close buttons (including dynamically created modals)
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.modal-close');
      if (!btn) {
        return;
      }
      e.preventDefault();
      const modal = btn.closest('.modal, dialog.crm-native-dialog');
      if (modal) {
        this.closeModal(modal);
      }
    });
  },

  /**
   * AudioCheck-style single-flight for content dialogs: close other open
   * non-confirm, non-persist dialogs so double-clicks cannot stack locks.
   * Confirm/alertdialogs may still sit on top of a content dialog.
   * @param {HTMLElement} except
   */
  _closeOtherContentDialogs(except) {
    this._getOpenNativeDialogs().forEach((d) => {
      if (d === except) {
        return;
      }
      if (d.classList.contains('confirm-dialog')) {
        return;
      }
      if (d.dataset.azcModalPersist === 'true') {
        return;
      }
      this.closeModal(d);
    });
  },

  /**
   * Open modal by ID (native <dialog> preferred; legacy backdrop fallback).
   */
  openModal(modalId) {
    const modal = typeof modalId === 'string' ? document.getElementById(modalId) : modalId;
    if (!modal) {
      console.warn('Modal not found:', modalId);
      return;
    }

    if (!modal._azcReturnFocus && document.activeElement instanceof HTMLElement) {
      modal._azcReturnFocus = document.activeElement;
    }

    if (this._isNativeDialog(modal)) {
      if (!modal.classList.contains('confirm-dialog')) {
        this._closeOtherContentDialogs(modal);
      }
      delete modal.dataset.azcClosed;
      delete modal.dataset.azcClosing;
      delete modal.dataset.azcOnCloseFired;
      modal.dataset.azcFocusTrapStandalone = '1';
      modal.removeAttribute('aria-hidden');
      if (!modal.hasAttribute('aria-modal')) {
        modal.setAttribute('aria-modal', 'true');
      }
      if (typeof modal.showModal === 'function' && !modal.open) {
        try {
          modal.showModal();
        } catch (_err) {
          modal.setAttribute('open', 'open');
        }
      } else if (!modal.open) {
        modal.setAttribute('open', 'open');
      }
      this._acquireModalLock(modal);
      this._bindFocusTrap(modal);
      setTimeout(() => {
        const closeBtn = modal.querySelector('.modal-close');
        this.focusFirstElement(modal, closeBtn ? null : undefined);
      }, 50);
      window.dispatchEvent(new CustomEvent('modal-open', {
        detail: { modalId: modal.id || modalId, modal }
      }));
      return;
    }

    // Design system: only native <dialog> is supported. Legacy backdrop path removed.
    console.error('CustomerCheck openModal: expected a native <dialog>, got', modal && modal.tagName);
  },

  /**
   * Close modal
   */
  closeModal(modal) {
    if (!modal) return;

    const modalElement = typeof modal === 'string' ? document.getElementById(modal) : modal;
    if (!modalElement) {
      console.warn('Modal element not found:', modal);
      return;
    }

    // Idempotent: close button + document delegation (and Esc cancel) can race.
    if (modalElement.dataset.azcClosed === '1' || modalElement.dataset.azcClosing === '1') {
      return;
    }
    modalElement.dataset.azcClosing = '1';

    const returnFocus = modalElement._azcReturnFocus;
    delete modalElement._azcReturnFocus;

    this._unbindFocusTrap(modalElement);

    if (this._isNativeDialog(modalElement)) {
      const persist = modalElement.dataset.azcModalPersist === 'true';
      try {
        if (typeof modalElement.close === 'function' && modalElement.open) {
          modalElement.close();
        } else {
          modalElement.removeAttribute('open');
        }
      } catch (_err) {
        modalElement.removeAttribute('open');
      }
      modalElement.setAttribute('aria-hidden', 'true');
      modalElement.dataset.azcClosed = '1';
      delete modalElement.dataset.azcClosing;
      this._releaseModalLock(modalElement);
      if (!persist) {
        setTimeout(() => {
          if (modalElement.parentNode && !modalElement.open) {
            modalElement.remove();
          }
        }, 0);
      }
      this._restoreModalFocus(returnFocus);
      window.dispatchEvent(new CustomEvent('modal-close', {
        detail: { modalId: modalElement.id, modal: modalElement }
      }));
      return;
    }

    const backdrop = modalElement.closest('.modal-backdrop');
    const persist = modalElement.dataset.azcModalPersist === 'true';
    modalElement.dataset.azcClosed = '1';
    delete modalElement.dataset.azcClosing;

    if (!backdrop) {
      modalElement.style.display = 'none';
      modalElement.setAttribute('aria-hidden', 'true');
      this._releaseModalLock(modalElement);
      this._restoreModalFocus(returnFocus);
      window.dispatchEvent(new CustomEvent('modal-close', {
        detail: { modalId: modalElement.id, modal: modalElement }
      }));
      return;
    }

    modalElement.style.display = 'none';
    modalElement.setAttribute('aria-hidden', 'true');
    backdrop.style.display = 'none';
    this._releaseModalLock(modalElement);

    const removeFromDom = () => {
      if (persist) {
        document.body.appendChild(modalElement);
        return;
      }
      if (backdrop.parentNode) {
        backdrop.remove();
      } else if (modalElement.parentNode) {
        modalElement.parentNode.removeChild(modalElement);
      }
    };

    setTimeout(removeFromDom, 300);

    this._restoreModalFocus(returnFocus);

    window.dispatchEvent(new CustomEvent('modal-close', {
      detail: { modalId: modalElement.id, modal: modalElement }
    }));
  },

  _restoreModalFocus(returnFocus) {
    if (returnFocus && document.body.contains(returnFocus) && typeof returnFocus.focus === 'function') {
      try {
        returnFocus.focus();
        return;
      } catch (e) { /* noop */ }
    }
    const main = document.getElementById('crm-main-content');
    if (main && typeof main.focus === 'function') {
      try {
        main.setAttribute('tabindex', '-1');
        main.focus({ preventScroll: false });
      } catch (e) { /* noop */ }
    }
  },

  /**
   * Unified dialog entry: create (if needed) and open.
   *
   * @param {Object} options - Same as createModal; opens when `open` is not false.
   * @returns {HTMLElement}
   */
  openDialog(options = {}) {
    const { id, open = true, ...rest } = options;
    // Always rebuild when an id is reused so content cannot go stale (delivery pack, etc.).
    let existing = id ? document.getElementById(id) : null;
    if (existing) {
      const wasOpen = !!(existing.open || existing.hasAttribute('open'));
      if (wasOpen) {
        this.closeModal(existing);
      }
      existing = id ? document.getElementById(id) : null;
      if (existing && existing.parentNode) {
        existing.parentNode.removeChild(existing);
      }
    }
    const modal = this.createModal({ id, ...rest });
    if (open !== false) {
      this.openModal(modal);
    }
    return modal;
  },

  /**
   * Create modal dynamically as a native <dialog> (SETTINGS / DS principle: native dialog).
   */
  createModal(options = {}) {
    const {
      id = `modal-${Date.now()}`,
      title = '',
      content = '',
      size = 'md',
      closable = true,
      persist = false,
      dismissOnBackdrop = true,
      onClose = null
    } = options;

    const modal = document.createElement('dialog');
    modal.className = `crm-native-dialog modal modal--${size} crm-dialog crm-dialog--content`;
    modal.id = id;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    if (title) {
      modal.setAttribute('aria-labelledby', `${id}-title`);
    }
    modal.setAttribute('aria-hidden', 'true');
    modal.dataset.azcFocusTrapStandalone = '1';
    if (persist) {
      modal.dataset.azcModalPersist = 'true';
    }
    if (!dismissOnBackdrop) {
      modal.dataset.azcModalDismiss = 'false';
    }

    const closeLabel = (typeof window !== 'undefined' && window.t) 
      ? window.t('customercheck', 'Close') 
      : 'Close';
    
    modal.innerHTML = `
      <div class="modal-header">
        <h2 class="modal-title" id="${id}-title">${this._escapeHtml(title)}</h2>
        ${closable ? `<button type="button" class="modal-close crm-btn crm-btn--ghost" aria-label="${this._escapeHtml(closeLabel)}">&times;</button>` : ''}
      </div>
      <div class="modal-body">
        ${content}
      </div>
    `;

    const runOnCloseOnce = () => {
      if (!onClose || modal.dataset.azcOnCloseFired === '1') {
        return;
      }
      modal.dataset.azcOnCloseFired = '1';
      try {
        onClose();
      } catch (_err) { /* caller errors must not break teardown */ }
    };

    if (closable) {
      const closeBtn = modal.querySelector('.modal-close');
      // Prefer document-level .modal-close delegation; this only runs onClose once.
      closeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        this.closeModal(modal);
        runOnCloseOnce();
      });
    }

    modal.addEventListener('cancel', (e) => {
      if (modal.dataset.azcModalDismiss === 'false') {
        e.preventDefault();
        return;
      }
      // Let the dialog close, then clean up via closeModal once.
      setTimeout(() => {
        this.closeModal(modal);
        runOnCloseOnce();
      }, 0);
    });

    // Nextcloud core apps (e.g. notifications) may preventDefault() the Escape
    // keydown globally, which suppresses the native `cancel` event for every
    // <dialog>. If the dialog is still open after the keydown finished
    // dispatching, close it ourselves — same path as the `cancel` handler.
    modal.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape' || modal.dataset.azcModalDismiss === 'false') {
        return;
      }
      setTimeout(() => {
        if (modal.open) {
          this.closeModal(modal);
          runOnCloseOnce();
        }
      }, 0);
    });

    // Chromium: click on ::backdrop targets the dialog element itself.
    modal.addEventListener('click', (e) => {
      if (e.target !== modal) {
        return;
      }
      if (modal.dataset.azcModalDismiss === 'false') {
        return;
      }
      this.closeModal(modal);
      runOnCloseOnce();
    });

    document.body.appendChild(modal);
    return modal;
  },

  // ===== TOAST COMPONENTS =====

  /**
   * Initialize toast functionality
   */
  initToasts() {
    // Create toast container if it doesn't exist
    if (!document.getElementById('toast-container')) {
      const container = document.createElement('div');
      container.id = 'toast-container';
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
  },

  /**
   * Show toast notification
   */
  showToast(options = {}) {
    const {
      type = 'info',
      message = '',
      title = null
    } = options;
    // Errors linger longer (MC critical = 9s); callers may still override duration.
    const duration = Object.prototype.hasOwnProperty.call(options, 'duration')
      ? options.duration
      : ((type === 'error' || type === 'critical' || type === 'danger') ? 9000 : 5000);

    const container = document.getElementById('toast-container') || document.body;
    // Dedup: an identical toast already showing just gets its timer reset
    // instead of stacking duplicates (rapid retries, repeated API failures).
    const dedupKey = type + '|' + String(title || '') + '|' + String(message || '');
    const dupes = container.querySelectorAll('.toast[data-crm-toast-key]');
    for (const existing of dupes) {
      if (existing.dataset.crmToastKey === dedupKey) {
        if (existing._crmDismissTimer) {
          clearTimeout(existing._crmDismissTimer);
        }
        if (duration > 0) {
          existing._crmDismissTimer = setTimeout(() => {
            this.hideToast(existing);
          }, duration);
        }
        return existing;
      }
    }
    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.dataset.crmToastKey = dedupKey;
    // Errors are assertive alerts; routine success/info/warning are polite status.
    const isAssertive = type === 'error' || type === 'critical' || type === 'danger';
    toast.setAttribute('role', isAssertive ? 'alert' : 'status');
    toast.setAttribute('aria-live', isAssertive ? 'assertive' : 'polite');
    toast.setAttribute('aria-atomic', 'true');

    const icon = this.getToastIcon(options.type || type);
    const closeLabel = (typeof window !== 'undefined' && window.t) 
      ? window.t('customercheck', 'Close') 
      : 'Close';
    
    const toastVariant = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
    const toastWellClass = type === 'error' ? 'danger' : toastVariant;
    toast.innerHTML = `
      <div class="toast-icon crm-notif-icon-well crm-notif-icon-well--${toastWellClass}" aria-hidden="true">${icon}</div>
      <div class="toast-content">
        ${title ? `<div class="toast-title">${this._escapeHtml(title)}</div>` : ''}
        <div class="toast-message">${this._escapeHtml(message)}</div>
      </div>
      <button type="button" class="toast-close" aria-label="${this._escapeHtml(closeLabel)}">&times;</button>
    `;

    container.appendChild(toast);

    // Show toast with animation
    setTimeout(() => {
      toast.classList.add('toast--show');
    }, 10);

    // Close button
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => {
      this.hideToast(toast);
    });

    // Auto-dismiss
    if (duration > 0) {
      toast._crmDismissTimer = setTimeout(() => {
        this.hideToast(toast);
      }, duration);
    }

    return toast;
  },

  /**
   * Hide toast
   */
  hideToast(toast) {
    toast.classList.remove('toast--show');
    setTimeout(() => {
      if (toast.parentNode) {
        toast.remove();
      }
    }, 300);
  },

  /**
   * Get toast icon
   */
  getToastIcon(type) {
    const map = {
      success: 'check',
      error: 'x',
      warning: 'circle-alert',
      info: 'info',
    };
    const name = map[type] || map.info;
    if (typeof window.AzcCatalog !== 'undefined' && typeof window.AzcCatalog.render === 'function') {
      return window.AzcCatalog.render(name, 'toast__icon-svg');
    }
    return '';
  },

  // ===== CONFIRM DIALOG =====

  /**
   * Show an accessible confirmation dialog and return a Promise that resolves
   * to true (confirmed) or false (cancelled/dismissed).
   *
   * Replaces native window.confirm() which has no styling control and limited
   * accessibility support (WCAG 4.1.2, 2.1.1).
   *
   * @param {Object} options
   * @param {string} options.title       - Dialog heading
   * @param {string} options.message     - Dialog body text (plain text, not HTML)
   * @param {string} [options.confirmLabel] - Confirm button label (default: "Confirm")
   * @param {string} [options.cancelLabel]  - Cancel button label (default: "Cancel")
   * @param {string} [options.variant]      - "danger" | "warning" | "info" (default: "info")
   * @returns {Promise<boolean>}
   */
  /**
   * Alias matching sibling check-apps API.
   */
  confirmDialog(options = {}) {
    return this.showConfirmDialog(options);
  },

  /**
   * Single-button informational dialog (WCAG alertdialog).
   *
   * @param {Object} options
   * @returns {Promise<void>}
   */
  alertDialog(options = {}) {
    const gotIt = window.t ? window.t('customercheck', 'Got it') : 'Got it';
    return this.showConfirmDialog({
      ...options,
      alertOnly: true,
      confirmLabel: options.confirmLabel || gotIt,
      variant: options.variant || 'info',
    }).then(() => {});
  },

  showConfirmDialog(options = {}) {
    const {
      title = '',
      message = '',
      confirmLabel = (window.t ? window.t('customercheck', 'Confirm') : 'Confirm'),
      cancelLabel  = (window.t ? window.t('customercheck', 'Cancel') : 'Cancel'),
      variant = 'info',
      alertOnly = false,
      requireCheckbox = false,
      checkboxLabel = (window.t ? window.t('customercheck', 'I understand this action cannot be undone') : 'I understand this action cannot be undone'),
      optionalCheckbox = false,
      optionalCheckboxLabel = '',
      optionalCheckboxChecked = false,
      requireReason = false,
      reasonLabel = (window.t ? window.t('customercheck', 'Reason (required)') : 'Reason (required)'),
      requireTypedConfirm = false,
      typedConfirmPhrase = 'DELETE',
      typedConfirmLabel,
    } = options;

    // Resolve the typed-confirmation label with the *actual* phrase the caller
    // requested. We keep `%s` as the placeholder for the translation system so
    // the substitution happens exactly once and reflects custom phrases too.
    let resolvedTypedConfirmLabel = typedConfirmLabel
      ? String(typedConfirmLabel)
      : (window.t
        ? window.t('customercheck', 'Type %s to confirm', [typedConfirmPhrase])
        : `Type ${typedConfirmPhrase} to confirm`);
    if (resolvedTypedConfirmLabel.includes('%s')) {
      resolvedTypedConfirmLabel = resolvedTypedConfirmLabel.replace('%s', typedConfirmPhrase);
    }

    const isDestructive = variant === 'danger' || variant === 'destructive';
    const needsExplicitAck = requireCheckbox || requireReason || requireTypedConfirm;

    return new Promise((resolve) => {
      // Single-flight confirms: dismiss any prior confirm so double-clicks cannot stack.
      if (typeof this._activeConfirmCleanup === 'function') {
        const prev = this._activeConfirmCleanup;
        this._activeConfirmCleanup = null;
        try {
          prev(false);
        } catch (_err) { /* prior cleanup must not block */ }
      }

      const dialogId = `confirm-dialog-${Date.now()}`;
      const htmlLang = document.querySelector('[data-crm-html-lang]')?.getAttribute('data-crm-html-lang')
        || document.documentElement.lang
        || 'en';

      const iconName = isDestructive ? 'alert-triangle' : (variant === 'warning' ? 'circle-alert' : 'info');
      const iconHtml = (window.AzcCatalog && window.AzcCatalog.render)
        ? window.AzcCatalog.render(iconName, 'confirm-dialog__icon-svg')
        : '';

      let extraFields = '';
      if (requireCheckbox) {
        extraFields += `
          <label class="crm-choice confirm-dialog__checkbox-row" for="${dialogId}-ack">
            <input type="checkbox" id="${dialogId}-ack" class="confirm-dialog__ack">
            <span>${this._escapeHtml(checkboxLabel)}</span>
          </label>`;
      }
      if (optionalCheckbox && optionalCheckboxLabel) {
        extraFields += `
          <label class="crm-choice confirm-dialog__checkbox-row" for="${dialogId}-optional">
            <input type="checkbox" id="${dialogId}-optional" class="confirm-dialog__optional"${optionalCheckboxChecked ? ' checked' : ''}>
            <span>${this._escapeHtml(optionalCheckboxLabel)}</span>
          </label>`;
      }
      if (requireReason) {
        extraFields += `
          <label for="${dialogId}-reason" class="form-label">${this._escapeHtml(reasonLabel)}</label>
          <textarea id="${dialogId}-reason" class="form-textarea confirm-dialog__reason" rows="3" required minlength="3"></textarea>`;
      }
      if (requireTypedConfirm) {
        extraFields += `
          <label for="${dialogId}-typed" class="form-label">${this._escapeHtml(resolvedTypedConfirmLabel)}</label>
          <input type="text" id="${dialogId}-typed" class="form-input confirm-dialog__typed" autocomplete="off" spellcheck="false">`;
      }

      const describedBy = [`${dialogId}-message`];
      if (requireReason) describedBy.push(`${dialogId}-reason`);

      const footerButtons = alertOnly
        ? `<button type="button" class="crm-btn crm-btn--primary confirm-dialog__confirm">${this._escapeHtml(confirmLabel)}</button>`
        : `<button type="button" class="crm-btn crm-btn--secondary confirm-dialog__cancel">${this._escapeHtml(cancelLabel)}</button>
          <button type="button" class="crm-btn crm-btn--${isDestructive ? 'danger' : 'primary'} confirm-dialog__confirm"${isDestructive && needsExplicitAck ? ' disabled' : ''}>${this._escapeHtml(confirmLabel)}</button>`;

      const dialog = document.createElement('dialog');
      dialog.className = 'crm-native-dialog modal modal--sm confirm-dialog crm-dialog';
      dialog.id = dialogId;
      dialog.setAttribute('role', 'alertdialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', `${dialogId}-title`);
      dialog.setAttribute('lang', htmlLang);
      dialog.setAttribute('aria-describedby', describedBy.join(' '));

      dialog.innerHTML = `
        <div class="modal-header">
          <span class="confirm-dialog__icon" aria-hidden="true">${iconHtml}</span>
          <h2 class="modal-title" id="${dialogId}-title">${this._escapeHtml(title)}</h2>
        </div>
        <div class="modal-body">
          <p class="confirm-dialog__message" id="${dialogId}-message">${this._escapeHtml(message)}</p>
          ${extraFields}
        </div>
        <div class="modal-footer${alertOnly ? ' modal-footer--single' : ''}">
          ${footerButtons}
        </div>
      `;

      // Capture the return-focus target BEFORE showModal(): showModal moves
      // focus into the dialog, so reading activeElement afterwards would store
      // an element that is removed on close (focus would fall to <body>).
      const previouslyFocused = document.activeElement;

      document.body.appendChild(dialog);
      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
      } else {
        dialog.setAttribute('open', 'open');
      }

      this._acquireModalLock(dialog);
      this._bindFocusTrap(dialog);

      const confirmBtn = dialog.querySelector('.confirm-dialog__confirm');
      const ackBox = dialog.querySelector('.confirm-dialog__ack');
      const optionalBox = dialog.querySelector('.confirm-dialog__optional');
      const reasonField = dialog.querySelector('.confirm-dialog__reason');
      const typedField = dialog.querySelector('.confirm-dialog__typed');

      const validateConfirmEnabled = () => {
        let ok = true;
        if (requireCheckbox && ackBox && !ackBox.checked) ok = false;
        if (requireReason && reasonField && reasonField.value.trim().length < 3) ok = false;
        if (requireTypedConfirm && typedField
          && typedField.value.trim().toUpperCase() !== String(typedConfirmPhrase).trim().toUpperCase()) {
          ok = false;
        }
        if (confirmBtn) confirmBtn.disabled = !ok;
      };

      ackBox?.addEventListener('change', validateConfirmEnabled);
      reasonField?.addEventListener('input', validateConfirmEnabled);
      typedField?.addEventListener('input', validateConfirmEnabled);
      validateConfirmEnabled();

      setTimeout(() => {
        const focusTarget = alertOnly ? confirmBtn : dialog.querySelector('.confirm-dialog__cancel');
        if (focusTarget) focusTarget.focus();
      }, 50);

      let settled = false;
      const cleanup = (result) => {
        if (settled) {
          return;
        }
        settled = true;
        if (this._activeConfirmCleanup === cleanup) {
          this._activeConfirmCleanup = null;
        }
        this._unbindFocusTrap(dialog);
        try {
          if (typeof dialog.close === 'function' && dialog.open) {
            dialog.close();
          }
        } catch (_err) { /* noop */ }
        dialog.remove();
        this._releaseModalLock(dialog);
        if (previouslyFocused && previouslyFocused.isConnected
          && typeof previouslyFocused.focus === 'function') {
          try {
            previouslyFocused.focus();
          } catch (_err) { /* detached */ }
        } else {
          this._restoreModalFocus(null);
        }
        resolve(result);
      };
      this._activeConfirmCleanup = cleanup;

      confirmBtn.addEventListener('click', () => {
        if (confirmBtn.disabled) return;
        cleanup(alertOnly ? true : {
          confirmed: true,
          reason: reasonField ? reasonField.value.trim() : '',
          optionalChecked: !!(optionalBox && optionalBox.checked),
        });
      });
      const cancelBtn = dialog.querySelector('.confirm-dialog__cancel');
      if (cancelBtn) {
        cancelBtn.addEventListener('click', () => cleanup(false));
      }

      dialog.addEventListener('cancel', (e) => {
        if (isDestructive && !alertOnly) {
          e.preventDefault();
          return;
        }
        cleanup(alertOnly ? true : false);
      });
      // Same Escape-suppression defence as createModal: when another script
      // preventDefaults the keydown, `cancel` never fires — close explicitly.
      // Destructive confirms stay guarded (they require an explicit choice).
      dialog.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || (isDestructive && !alertOnly)) {
          return;
        }
        setTimeout(() => {
          if (dialog.open) {
            cleanup(alertOnly ? true : false);
          }
        }, 0);
      });
      // Focus trap is _bindFocusTrap only — no second Tab handler (double-trap race).
    }).then((result) => {
      if (result && typeof result === 'object' && result.confirmed) {
        return result;
      }
      return result === true;
    });
  },

  /**
   * Escape HTML special characters to prevent XSS when inserting into innerHTML.
   */
  _escapeHtml(str) {
    const Present = typeof window !== 'undefined' ? window.CustomerCheckDialogPresent : null;
    if (Present && typeof Present.escapeHtml === 'function') {
      return Present.escapeHtml(str);
    }
    const div = document.createElement('div');
    div.textContent = String(str == null ? '' : str);
    return div.innerHTML;
  },

  /**
   * Allow only http(s), mailto, or same-origin paths in dialog action hrefs.
   * @param {unknown} href
   * @param {string} [fallback='#']
   * @returns {string}
   */
  safeExternalHref(href, fallback = '#') {
    const Present = typeof window !== 'undefined' ? window.CustomerCheckDialogPresent : null;
    if (Present && typeof Present.safeExternalHref === 'function') {
      return Present.safeExternalHref(href, fallback);
    }
    const raw = String(href == null ? '' : href).trim();
    if (!raw) return fallback;
    const lower = raw.toLowerCase();
    if (lower.startsWith('javascript:') || lower.startsWith('data:') || lower.startsWith('vbscript:')) {
      return fallback;
    }
    if (/^https?:\/\//i.test(raw) || /^mailto:/i.test(raw) || raw.startsWith('/')) {
      return raw;
    }
    return fallback;
  },

  /**
   * XSS-safe mail-pack dialog body (subject / body / Open mail href).
   * @param {object} pack
   * @param {object} [opts]
   * @returns {string}
   */
  buildMailPackDialogHtml(pack, opts) {
    const Present = typeof window !== 'undefined' ? window.CustomerCheckDialogPresent : null;
    if (Present && typeof Present.buildMailPackDialogHtml === 'function') {
      return Present.buildMailPackDialogHtml(pack, opts || {});
    }
    const t = (opts && opts.t) || ((m) => m);
    const subject = this._escapeHtml((pack && pack.subject) || '');
    const body = this._escapeHtml((pack && pack.bodyText) || '');
    const href = this._escapeHtml(this.safeExternalHref((pack && (pack.mailAppUrl || pack.mailtoUrl)) || '#'));
    return '<p><strong>' + subject + '</strong></p><pre class="crm-pre">' + body + '</pre>'
      + '<div class="crm-form-actions"><a class="crm-btn crm-btn--primary" href="' + href
      + '" rel="noopener noreferrer">' + this._escapeHtml(t('Open mail')) + '</a></div>';
  },

  // ===== UTILITY FUNCTIONS =====

  /**
   * Focus first focusable element
   */
  focusFirstElement(container, preferred) {
    if (preferred && typeof preferred.focus === 'function') {
      try {
        preferred.focus();
        return;
      } catch (e) { /* noop */ }
    }
    const focusableElements = container.querySelectorAll(
      'button:not([disabled]), [href], input:not([disabled]):not([hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );

    if (focusableElements.length > 0) {
      focusableElements[0].focus();
    }
  },

  /**
   * Move legacy .header-actions / .crm-page-actions-source buttons into #crm-page-actions.
   */
  relocatePageActions() {
    const target = document.getElementById('crm-page-actions');
    if (!target) {
      return;
    }
    document.querySelectorAll('.crm-page-actions-source, .header-actions').forEach((source) => {
      while (source.firstChild) {
        target.appendChild(source.firstChild);
      }
      source.remove();
    });
  }
};

// Methods delegate via `this`; bind once so detached references stay safe (audit-critical).
CustomerCheckComponents.confirmDialog = CustomerCheckComponents.confirmDialog.bind(CustomerCheckComponents);
CustomerCheckComponents.alertDialog = CustomerCheckComponents.alertDialog.bind(CustomerCheckComponents);
CustomerCheckComponents.showConfirmDialog = CustomerCheckComponents.showConfirmDialog.bind(CustomerCheckComponents);

// Export for use in other modules
if (typeof window !== 'undefined') {
  window.CustomerCheckComponents = CustomerCheckComponents;
  window.CrmComponents = CustomerCheckComponents;
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    CustomerCheckComponents.init();
  });
} else {
  CustomerCheckComponents.init();
}
