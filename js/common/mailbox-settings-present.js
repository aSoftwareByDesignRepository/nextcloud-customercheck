/**
 * Browser bootstrap for mailbox-settings-present.
 * Keep aligned with js/utils/mailbox-settings-present.js (MailboxSettingsUxContractTest).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

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

  function buildMailboxListRows(items, t) {
    const list = Array.isArray(items) ? items : [];
    return list.map(function (raw) {
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
  function renderMailboxList(host, rows, t, onEdit) {
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

      if (typeof onEdit === 'function') {
        const actions = document.createElement('div');
        actions.className = 'crm-inline-actions';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'crm-btn crm-btn--secondary crm-btn--sm';
        btn.textContent = t('Edit');
        btn.addEventListener('click', function () {
          onEdit(row.id);
        });
        actions.appendChild(btn);
        li.appendChild(actions);
      }
      ul.appendChild(li);
    });
    host.appendChild(ul);
  }

  global.CustomerCheckMailboxSettingsPresent = {
    mailboxStatusTone: mailboxStatusTone,
    mailboxStatusLabel: mailboxStatusLabel,
    buildMailboxListRows: buildMailboxListRows,
    renderMailboxList: renderMailboxList,
  };
}(typeof window !== 'undefined' ? window : globalThis));
