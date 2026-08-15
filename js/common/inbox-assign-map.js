/**
 * Inbox Unassigned — pure helpers (mail list ↔ Assign API).
 * Dual IDs: list/preview/reply use mailMsgId; assign writes use reviewItemId.
 */
(function (global) {
  'use strict';

  function isMailSourceKind(code) {
    return code === 'shared_mail' || code === 'personal_mail_shared_unmatched';
  }

  function normalizeAssignKind(raw) {
    const k = String(raw || '').trim();
    if (k === 'shared_mail' || k === 'personal_mail_shared_unmatched') {
      return 'mail';
    }
    return k || 'mail';
  }

  /**
   * @param {Record<string, mixed>} item Review API row
   * @param {{ personalShared?: string, teamMail?: string }} [labels]
   * @returns {Record<string, mixed>}
   */
  function mapReviewItemToRow(item, labels) {
    const L = labels && typeof labels === 'object' ? labels : {};
    const reviewItemId = parseInt(String(item && item.id || 0), 10);
    const ref = (item && item.sourceRef && typeof item.sourceRef === 'object') ? item.sourceRef : {};
    const mailMsgId = parseInt(String(ref.mailMsgId || 0), 10);
    const from = String(ref.from || '').trim();
    const kind = String(item && item.sourceKind || '');
    const suggestions = Array.isArray(item && item.suggestions) ? item.suggestions : [];
    const hasMail = mailMsgId > 0 && isMailSourceKind(kind);
    let mailboxLabel = '';
    if (isMailSourceKind(kind)) {
      mailboxLabel = kind === 'personal_mail_shared_unmatched'
        ? String(L.personalShared || 'Personal shared')
        : String(L.teamMail || 'Team mail');
    }
    return {
      id: mailMsgId > 0 ? mailMsgId : 0,
      reviewItemId: reviewItemId,
      from: from,
      subject: String(item && item.subject || ''),
      snippet: String(item && item.snippet || ''),
      sentAt: item && item.sentAt ? item.sentAt : (item && item.createdAt ? item.createdAt : null),
      sourceKind: kind,
      suggestions: suggestions,
      redacted: false,
      private: false,
      visibility: 'shared',
      mailboxLabel: mailboxLabel,
      hasMail: hasMail,
    };
  }

  /**
   * Only mail rows with both ids are safe for Inbox preview + Assign writes.
   * @param {Record<string, mixed>} row
   */
  function isUnifiedMailAssignRow(row) {
    return !!(row && row.hasMail && parseInt(String(row.id || 0), 10) > 0
      && parseInt(String(row.reviewItemId || 0), 10) > 0);
  }

  /** Kind param for GET …/review/items (mail = both shared kinds). */
  function reviewApiKind(raw) {
    const k = String(raw || '').trim();
    if (k === 'shared_mail' || k === 'personal_mail_shared_unmatched' || k === 'mail') {
      return k;
    }
    return 'mail';
  }

  /**
   * @param {Array<Record<string, mixed>>} rows
   * @param {'sent'|'from'|'subject'} key
   * @param {'asc'|'desc'} dir
   * @returns {Array<Record<string, mixed>>}
   */
  function sortMailRows(rows, key, dir) {
    const list = Array.isArray(rows) ? rows.slice() : [];
    const k = (key === 'from' || key === 'subject') ? key : 'sent';
    const asc = dir === 'asc';
    list.sort(function (a, b) {
      let cmp = 0;
      if (k === 'from') {
        cmp = String(a && a.from || '').localeCompare(String(b && b.from || ''), undefined, { sensitivity: 'base' });
      } else if (k === 'subject') {
        cmp = String(a && a.subject || '').localeCompare(String(b && b.subject || ''), undefined, { sensitivity: 'base' });
      } else {
        const ak = String((a && (a.sentAt || a.createdAt)) || '');
        const bk = String((b && (b.sentAt || b.createdAt)) || '');
        cmp = ak.localeCompare(bk);
      }
      if (cmp === 0) {
        cmp = (parseInt(String(b && b.id || 0), 10) || 0) - (parseInt(String(a && a.id || 0), 10) || 0);
      }
      return asc ? cmp : -cmp;
    });
    return list;
  }

  /**
   * Client-side needle match (Personal/Team tabs; Unassigned also uses server q).
   * @param {Record<string, mixed>} row
   * @param {string} q
   */
  function matchMailQuery(row, q) {
    const needle = String(q || '').trim().toLowerCase();
    if (!needle) {
      return true;
    }
    const hay = [
      row && row.from,
      row && row.subject,
      row && row.snippet,
      row && row.mailboxLabel,
      row && row.mailboxEmail,
    ].map(function (v) { return String(v || '').toLowerCase(); }).join('\n');
    return hay.indexOf(needle) !== -1;
  }

  /**
   * Next sort direction when clicking a sort column.
   * @param {string} curKey
   * @param {string} curDir
   * @param {string} nextKey
   * @returns {{ key: string, dir: 'asc'|'desc' }}
   */
  function nextSortState(curKey, curDir, nextKey) {
    const key = (nextKey === 'from' || nextKey === 'subject') ? nextKey : 'sent';
    if (String(curKey || '') === key) {
      return { key: key, dir: curDir === 'asc' ? 'desc' : 'asc' };
    }
    return { key: key, dir: key === 'sent' ? 'desc' : 'asc' };
  }

  /**
   * Accounts that belong in the Personal / Team toolbar filter.
   * The tab already scopes kind — never mix team boxes into Personal (and vice versa).
   *
   * @param {Array<Record<string, mixed>>} accounts
   * @param {string} tab 'mine' | 'team' | 'unmatched'
   * @returns {Array<Record<string, mixed>>}
   */
  function accountsForInboxTab(accounts, tab) {
    const list = Array.isArray(accounts) ? accounts : [];
    if (tab === 'mine') {
      return list.filter(function (a) {
        return String((a && a.kind) || '') === 'personal'
          && parseInt(String((a && a.id) || 0), 10) > 0;
      });
    }
    if (tab === 'team') {
      return list.filter(function (a) {
        return String((a && a.kind) || '') !== 'personal'
          && parseInt(String((a && a.id) || 0), 10) > 0;
      });
    }
    return [];
  }

  /**
   * Bachus: hide the account dropdown unless the active tab has 2+ accounts to choose.
   * @param {Array<Record<string, mixed>>} accounts
   * @param {string} tab
   * @returns {boolean}
   */
  function accountFilterVisible(accounts, tab) {
    if (tab === 'unmatched') {
      return false;
    }
    return accountsForInboxTab(accounts, tab).length > 1;
  }

  /**
   * Pick the send-account used for signature checkbox / preview.
   * Prefer the message's mailboxId; never fall back to Team-first list order for Personal mail.
   *
   * @param {Array<Record<string, mixed>>} accounts
   * @param {Record<string, mixed>|null|undefined} mailItem
   * @param {string} [tab] 'mine' | 'team' | 'unmatched'
   * @returns {Record<string, mixed>|null}
   */
  function pickComposeSendAccount(accounts, mailItem, tab) {
    const list = Array.isArray(accounts) ? accounts : [];
    if (!list.length) {
      return null;
    }
    const mid = parseInt(String((mailItem && mailItem.mailboxId) || 0), 10);
    if (mid > 0) {
      for (let i = 0; i < list.length; i++) {
        if (parseInt(String(list[i].id || 0), 10) === mid) {
          return list[i];
        }
      }
    }
    const kind = String((mailItem && (mailItem.mailboxKind || mailItem.kind)) || '').toLowerCase();
    const wantPersonal = kind === 'personal' || tab === 'mine';
    const wantTeam = (!wantPersonal && (kind === 'team' || kind === 'shared' || tab === 'team'));
    if (wantPersonal) {
      for (let i = 0; i < list.length; i++) {
        if (String(list[i].kind || '') === 'personal') {
          return list[i];
        }
      }
    }
    if (wantTeam) {
      for (let i = 0; i < list.length; i++) {
        if (String(list[i].kind || '') !== 'personal') {
          return list[i];
        }
      }
    }
    return list[0] || null;
  }

  /**
   * When to show Delete / Mark as spam on Inbox action bar.
   * Unassigned → Assign APIs; Personal/Team → mail/{id}/delete|mark-spam.
   *
   * @param {{
   *   tab: string,
   *   hasRow: boolean,
   *   hasReview: boolean,
   *   canWrite: boolean,
   *   sourceKind?: string
   * }} opts
   * @returns {{
   *   discard: boolean,
   *   spam: boolean,
   *   mode: 'assign'|'inbox'|'none',
   *   discardIsMail: boolean
   * }}
   */
  function mutateActionVisibility(opts) {
    const tab = String(opts && opts.tab || '');
    const hasRow = !!(opts && opts.hasRow);
    const hasReview = !!(opts && opts.hasReview);
    const canWrite = !!(opts && opts.canWrite);
    const kind = String(opts && opts.sourceKind || '');
    if (tab === 'unmatched') {
      const discard = hasReview && canWrite;
      return {
        discard: discard,
        spam: discard && kind === 'shared_mail',
        mode: discard ? 'assign' : 'none',
        discardIsMail: kind === 'shared_mail',
      };
    }
    if (tab === 'mine' || tab === 'team') {
      const show = hasRow && canWrite;
      return {
        discard: show,
        spam: show,
        mode: show ? 'inbox' : 'none',
        discardIsMail: true,
      };
    }
    return { discard: false, spam: false, mode: 'none', discardIsMail: false };
  }

  const api = {
    isMailSourceKind: isMailSourceKind,
    normalizeAssignKind: normalizeAssignKind,
    mapReviewItemToRow: mapReviewItemToRow,
    isUnifiedMailAssignRow: isUnifiedMailAssignRow,
    reviewApiKind: reviewApiKind,
    sortMailRows: sortMailRows,
    matchMailQuery: matchMailQuery,
    nextSortState: nextSortState,
    accountsForInboxTab: accountsForInboxTab,
    accountFilterVisible: accountFilterVisible,
    pickComposeSendAccount: pickComposeSendAccount,
    mutateActionVisibility: mutateActionVisibility,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckInboxAssignMap = api;
})(typeof window !== 'undefined' ? window : globalThis);
