/**
 * Shared-mail sync status + “Sync now” for Assign and Company Talk.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  var BACKGROUND_MINUTES = 15;

  function formatSyncAt(raw) {
    var value = String(raw || '').trim();
    if (!value) {
      return '';
    }
    var d = new Date(value);
    if (Number.isNaN(d.getTime())) {
      return value;
    }
    try {
      var locale = (document.documentElement && document.documentElement.lang)
        || (typeof navigator !== 'undefined' && navigator.language)
        || undefined;
      return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(d);
    } catch (e) {
      return value;
    }
  }

  function statusLine(t, summary) {
    if (!summary || !summary.mailboxCount) {
      return t('No shared mailbox is connected for this workspace.');
    }
    var mins = summary.backgroundIntervalMinutes || BACKGROUND_MINUTES;
    var parts = [];
    if (summary.mailboxCount > 1) {
      parts.push(t('%s shared mailboxes connected.', [String(summary.mailboxCount)]));
    }
    parts.push(t('Shared mail refreshes about every %s minutes in the background.', [String(mins)]));
    if (summary.lastSyncAt) {
      parts.push(t('Last sync: %s', [formatSyncAt(summary.lastSyncAt)]));
    } else {
      parts.push(t('Not synced yet.'));
    }
    return parts.join(' ');
  }

  /**
   * @param {HTMLElement} host
   * @param {{
   *   t: function(string, ?Array): string,
   *   summary: ?{mailboxCount:number,lastSyncAt:?string,backgroundIntervalMinutes:number},
   *   canSync: boolean,
   *   apiPost: function(string, object): Promise<any>,
   *   workspaceId: number,
   *   onSynced?: function(?object): void|Promise<void>,
   *   announce?: function(string): void,
   *   syncMessage?: string
   * }} opts
   */
  function mountBar(host, opts) {
    if (!host || !opts || !opts.summary || !opts.summary.mailboxCount) {
      if (host) {
        host.innerHTML = '';
        host.hidden = true;
      }
      return null;
    }
    host.hidden = false;
    host.className = 'crm-mail-sync-bar';
    host.setAttribute('role', 'region');
    host.setAttribute('aria-label', opts.t('Shared mailbox sync'));

    var status = document.createElement('p');
    status.className = 'crm-mail-sync-bar__status crm-muted';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    status.textContent = (opts.syncMessage && String(opts.syncMessage).trim() !== '')
      ? String(opts.syncMessage)
      : statusLine(opts.t, opts.summary);

    var actions = document.createElement('div');
    actions.className = 'crm-mail-sync-bar__actions';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'crm-btn crm-btn--secondary';
    btn.textContent = opts.t('Sync now');
    btn.setAttribute('aria-describedby', status.id || '');
    if (!status.id) {
      status.id = 'crm-mail-sync-status-' + String(Math.random()).slice(2, 8);
      btn.setAttribute('aria-describedby', status.id);
    }

    var syncing = false;

    if (!opts.canSync) {
      btn.disabled = true;
      btn.setAttribute('aria-disabled', 'true');
      btn.title = opts.t('You need permission to refresh shared mail.');
    } else {
      btn.addEventListener('click', function () {
        if (btn.disabled || syncing) {
          return;
        }
        syncing = true;
        btn.disabled = true;
        host.setAttribute('aria-busy', 'true');
        status.textContent = opts.t('Syncing shared mail…');
        opts.apiPost('/workspaces/' + String(opts.workspaceId) + '/mailboxes/sync-all', {})
          .then(function (data) {
            var payload = (data && data.data) || data || {};
            if (payload.lastSyncAt) {
              opts.summary.lastSyncAt = payload.lastSyncAt;
            }
            var summaryText = String(payload.message || statusLine(opts.t, opts.summary));
            status.textContent = summaryText;
            opts.syncMessage = summaryText;
            if (opts.announce && payload.message) {
              opts.announce(String(payload.message));
            }
            if (typeof opts.onSynced === 'function') {
              return opts.onSynced(payload);
            }
            return null;
          })
          .catch(function (err) {
            var errMsg = (err && err.message) ? String(err.message) : opts.t('Sync failed.');
            status.textContent = errMsg + ' ' + statusLine(opts.t, opts.summary);
            if (opts.announce) {
              opts.announce(errMsg);
            }
          })
          .finally(function () {
            syncing = false;
            btn.disabled = false;
            host.removeAttribute('aria-busy');
          });
      });
    }

    actions.appendChild(btn);
    host.replaceChildren(status, actions);
    return { statusEl: status, buttonEl: btn };
  }

  global.CustomerCheckMailSync = {
    BACKGROUND_MINUTES: BACKGROUND_MINUTES,
    formatSyncAt: formatSyncAt,
    statusLine: statusLine,
    mountBar: mountBar,
  };
}(window));
