/**
 * Browser bootstrap for mailbox-settings-present.
 * Keep aligned with js/utils/mailbox-settings-present.js (MailboxSettingsUxContractTest).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  /**
   * window.t / OC.L10N.translate fills {name} tokens only — %s/%d pass
   * through raw. Wrap an injected translator so leftover sprintf
   * placeholders are always filled (idempotent when already filled).
   * @param {unknown} base
   * @returns {(msg: string, args?: string[]) => string}
   */
  function sprintfT(base) {
    const baseT = typeof base === 'function' ? base : function (m) { return m; };
    return function (msg, args) {
      let out = baseT(msg, args);
      const list = Array.isArray(args) ? args : [];
      if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
        let i = 0;
        out = out.replace(/%%|%(?:\d+\$)?[sd]/g, function (m) {
          if (m === '%%') { return '%'; }
          const v = list[i];
          i += 1;
          return v == null ? '' : String(v);
        });
      }
      return out;
    };
  }

  function mailboxStatusTone(status) {
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

  function mailboxStatusLabel(status, t) {
    t = sprintfT(t);
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

function buildMailboxListRows(items, t) {
    t = sprintfT(t);
  const list = Array.isArray(items) ? items : [];
  return list.map(function (raw) {
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
        status: status,
        statusLabel: mailboxStatusLabel(status, t),
        tone: mailboxStatusTone(status),
        meta: metaParts.join(' · '),
        lastError: lastError,
      };
    }).filter(function (row) {
      return row.id > 0;
    });
  }

  /**
   * XSS-safe list into #crm-mailbox-list (textContent only).
   * @param {HTMLElement|null} host
   * @param {ReturnType<typeof buildMailboxListRows>} rows
   * @param {(msg: string) => string} t
   * @param {(id: number) => void} onEdit
   */
  /**
   * @param {HTMLElement|null} host
   * @param {Array} rows
   * @param {(msg: string, args?: string[]) => string} t
   * @param {(id: number) => void} [onEdit]
   * @param {(id: number, btn: HTMLButtonElement) => void} [onSync]
   * @param {(id: number, label: string, btn: HTMLButtonElement) => void} [onDelete]
   */
  function renderMailboxList(host, rows, t, onEdit, onSync, onDelete) {
    t = sprintfT(t);
    if (!host) {
      return;
    }
    while (host.firstChild) {
      host.removeChild(host.firstChild);
    }
    if (!rows || !rows.length) {
      return;
    }
    const ul = document.createElement('ul');
    ul.className = 'crm-mailbox-list';
    ul.setAttribute('aria-label', t('Connected mailboxes'));
    rows.forEach(function (row) {
      const li = document.createElement('li');
      li.className = 'crm-mailbox-list__item';
      li.setAttribute('data-mailbox-id', String(row.id));

      const head = document.createElement('div');
      head.className = 'crm-mailbox-list__head';
      const title = document.createElement('strong');
      title.className = 'crm-mailbox-list__title';
      title.textContent = row.label;
      const pill = document.createElement('span');
      if (row.tone === 'success') {
        pill.className = 'crm-status-pill crm-status-pill--on';
      } else if (row.tone === 'danger') {
        pill.className = 'crm-status-pill crm-status-pill--off';
      } else {
        pill.className = 'crm-badge crm-badge--' + (row.tone === 'warning' ? 'warning' : 'neutral');
      }
      pill.textContent = row.statusLabel;
      head.appendChild(title);
      head.appendChild(pill);
      li.appendChild(head);

      if (row.email) {
        const email = document.createElement('p');
        email.className = 'crm-muted crm-mailbox-list__email';
        email.textContent = row.email;
        li.appendChild(email);
      }
      if (row.meta) {
        const meta = document.createElement('p');
        meta.className = 'crm-muted crm-mailbox-list__meta';
        meta.textContent = row.meta;
        li.appendChild(meta);
      }
      if (row.lastError) {
        const err = document.createElement('p');
        err.className = 'form-error';
        err.setAttribute('role', 'status');
        err.textContent = row.lastError;
        li.appendChild(err);
      }

      if (typeof onEdit === 'function' || typeof onSync === 'function' || typeof onDelete === 'function') {
        const actions = document.createElement('div');
        actions.className = 'crm-inline-actions';
        if (typeof onEdit === 'function') {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'crm-btn crm-btn--secondary crm-btn--sm';
          btn.textContent = t('Edit');
          btn.setAttribute('aria-label', t('Edit mailbox: %s', [row.label || String(row.id)]));
          btn.addEventListener('click', function () {
            onEdit(row.id);
          });
          actions.appendChild(btn);
        }
        if (typeof onSync === 'function') {
          const syncBtn = document.createElement('button');
          syncBtn.type = 'button';
          syncBtn.className = 'crm-btn crm-btn--primary crm-btn--sm';
          syncBtn.textContent = t('Sync now');
          syncBtn.setAttribute('aria-label', t('Sync mailbox now: %s', [row.label || String(row.id)]));
          syncBtn.addEventListener('click', function () {
            onSync(row.id, syncBtn);
          });
          actions.appendChild(syncBtn);
        }
        if (typeof onDelete === 'function') {
          const delBtn = document.createElement('button');
          delBtn.type = 'button';
          delBtn.className = 'crm-btn crm-btn--danger crm-btn--sm';
          delBtn.textContent = t('Remove');
          delBtn.setAttribute('aria-label', t('Remove mailbox: %s', [row.label || String(row.id)]));
          delBtn.addEventListener('click', function () {
            onDelete(row.id, row.label || String(row.id), delBtn);
          });
          actions.appendChild(delBtn);
        }
        li.appendChild(actions);
      }
      ul.appendChild(li);
    });
    host.appendChild(ul);
  }

  function buildFolderPickerOptions(remoteFolders, selectedFolders, max) {
    const limit = typeof max === 'number' && max > 0 ? max : 20;
    const remote = Array.isArray(remoteFolders)
      ? remoteFolders.map(function (f) { return String(f || '').trim(); }).filter(Boolean)
      : [];
    const selected = Array.isArray(selectedFolders)
      ? selectedFolders.map(function (f) { return String(f || '').trim(); }).filter(Boolean)
      : [];
    const selectedSet = {};
    selected.forEach(function (p) { selectedSet[p] = true; });
    const seen = {};
    const out = [];
    function push(path, checked) {
      if (!path || seen[path] || out.length >= limit) return;
      seen[path] = true;
      out.push({ path: path, checked: !!checked });
    }
    remote.forEach(function (path) {
      push(path, selected.length ? !!selectedSet[path] : path === 'INBOX');
    });
    selected.forEach(function (path) { push(path, true); });
    if (out.length === 0) {
      push('INBOX', true);
    } else if (!out.some(function (o) { return o.checked; })) {
      var inbox = null;
      for (var i = 0; i < out.length; i++) {
        if (out[i].path === 'INBOX') { inbox = out[i]; break; }
      }
      if (inbox) inbox.checked = true;
      else out[0].checked = true;
    }
    return out;
  }

  function readCheckedFolderPaths(root) {
    if (!root || typeof root.querySelectorAll !== 'function') return [];
    return Array.prototype.slice.call(root.querySelectorAll('input[type="checkbox"][name="folders"]:checked'))
      .map(function (el) { return String(el.value || '').trim(); })
      .filter(Boolean);
  }

  /**
   * XSS-safe folder checkbox list (textContent only).
   * @param {HTMLElement|null} host
   * @param {ReturnType<typeof buildFolderPickerOptions>} options
   */
  function renderFolderPicker(host, options) {
    if (!host) return;
    host.textContent = '';
    const list = Array.isArray(options) ? options : [];
    list.forEach(function (opt) {
      const label = document.createElement('label');
      label.className = 'crm-check';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.name = 'folders';
      input.value = String(opt.path || '');
      input.checked = !!opt.checked;
      const span = document.createElement('span');
      span.textContent = String(opt.path || '');
      label.appendChild(input);
      label.appendChild(span);
      host.appendChild(label);
    });
  }

  /**
   * Empty-state visibility: only a successful load with zero rows may show the
   * "No shared mailbox yet" panel — a failed load keeps error chrome only
   * (showing the empty panel on failure would fabricate an empty state).
   * @param {unknown} rowCount
   * @param {unknown} loadFailed
   * @returns {boolean}
   */
  function mailboxEmptyVisible(rowCount, loadFailed) {
    if (loadFailed) return false;
    return (parseInt(String(rowCount || 0), 10) || 0) <= 0;
  }

  global.CustomerCheckMailboxSettingsPresent = {
    mailboxStatusTone: mailboxStatusTone,
    mailboxEmptyVisible: mailboxEmptyVisible,
    mailboxStatusLabel: mailboxStatusLabel,
    buildMailboxListRows: buildMailboxListRows,
    renderMailboxList: renderMailboxList,
    buildFolderPickerOptions: buildFolderPickerOptions,
    readCheckedFolderPaths: readCheckedFolderPaths,
    renderFolderPicker: renderFolderPicker,
  };
}(typeof window !== 'undefined' ? window : globalThis));
