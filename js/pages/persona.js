/**
 * Persona OS pages — Pulse / Brief / My Accounts / Team / Strategy / Mail triage.
 * AZC-simple: one card, clear list, text badges, ≥44px targets.
 */
(function () {
  'use strict';

  const t = (msg, args) => {
    const list = Array.isArray(args) ? args : [];
    let out = window.t ? window.t('customercheck', msg, list) : msg;
    // Nextcloud t() sometimes leaves %s / %1$s — fill locally (same as settings.js).
    if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
      let i = 0;
      out = out.replace(/%(?:\d+\$)?s/g, function () {
        const v = list[i];
        i += 1;
        return v == null ? '' : String(v);
      });
    }
    return out;
  };

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  /** Capability flags from PageShellTrait → #app-content data-crm-can-* */
  function shellCan(flag) {
    if (typeof window.CustomerCheckShellCan === 'function') {
      return window.CustomerCheckShellCan(flag);
    }
    const el = document.getElementById('app-content');
    return !!(el && el.getAttribute('data-crm-can-' + flag) === '1');
  }

  /** Monotonic load token — drops stale Assign paints when filters race. */
  let reviewLoadGeneration = 0;
  /** Monotonic Strategy board token — drops stale paints after filter/save races. */
  let strategyLoadGeneration = 0;
  /** Monotonic preview token — drops stale mail-body paints on rapid j/k row changes. */
  let mailPreviewGeneration = 0;
  /** Aborts in-flight Assign preview fetch when the operator moves to another row. */
  let mailPreviewAbort = null;

  /**
   * Bump preview generation and replace AbortController (ARGUS SF-01).
   * @returns {{ gen: number, signal: AbortSignal|null }}
   */
  function bumpMailPreviewRequest() {
    if (mailPreviewAbort) {
      try { mailPreviewAbort.abort(); } catch (e) { /* ignore */ }
    }
    mailPreviewAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    mailPreviewGeneration += 1;
    return {
      gen: mailPreviewGeneration,
      signal: mailPreviewAbort ? mailPreviewAbort.signal : null,
    };
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * RFC 2047 encoded-word → display text (defense if API/cache still returns =?…?=).
   * Mirrors lib/Support/MimeHeader.php — plain subjects pass through unchanged.
   */
  function decodeMimeHeader(raw) {
    const input = String(raw == null ? '' : raw).trim();
    if (!input || input.indexOf('=?') === -1) {
      return input;
    }
    const stillEncoded = /=\?[\w-]+\?[BQbq]\?[^\?]*\?=/;
    const decoded = input.replace(/=\?([\w-]+)\?([BQbq])\?([^\?]*)\?=/g, function (_m, charset, enc, payload) {
      let bin = '';
      if (String(enc).toUpperCase() === 'Q') {
        const qp = String(payload).replace(/_/g, ' ').replace(/=([0-9A-Fa-f]{2})/g, function (__m, hex) {
          return String.fromCharCode(parseInt(hex, 16));
        });
        bin = qp;
      } else {
        try {
          bin = atob(String(payload).replace(/\s+/g, ''));
        } catch (_e) {
          return _m;
        }
      }
      try {
        if (typeof TextDecoder !== 'undefined') {
          const bytes = new Uint8Array(bin.length);
          for (let i = 0; i < bin.length; i++) {
            bytes[i] = bin.charCodeAt(i) & 0xff;
          }
          return new TextDecoder(String(charset || 'utf-8'), { fatal: false }).decode(bytes);
        }
      } catch (_e2) { /* fall through */ }
      return bin;
    });
    if (!decoded || stillEncoded.test(decoded)) {
      return input;
    }
    return decoded.replace(/[ \t]+/g, ' ').trim();
  }

  /** Chase reason codes — never show snake_case in the UI. */
  function chaseReasonLabel(code) {
    const key = String(code || '');
    const map = {
      lead_sla: t('New lead waiting too long'),
      close_overdue: t('Close date overdue'),
      no_next_action: t('No next action'),
      quote_followup_due: t('Quote follow-up due'),
      close_this_week: t('Closing this week'),
      stale_stage: t('Stage went stale'),
      unowned: t('No owner'),
    };
    return map[key] || (key ? key : t('Needs attention'));
  }

  /** Commitment status — never show raw API codes. */
  function commitmentStatusLabel(status) {
    const key = String(status || '');
    const map = {
      committed: t('Committed'),
      done: t('Done'),
      missed: t('Missed'),
      carried: t('Carried'),
      abandoned: t('Abandoned'),
    };
    return map[key] || (key ? key : t('Unknown'));
  }

  /** Brief commitment kinds — human titles for 1-click suggestions. */
  function commitmentKindLabel(kind) {
    const key = String(kind || '');
    const map = {
      ar_chase: t('Collect overdue money'),
      renewal_act: t('Act on renewals'),
      plan_push: t('Push a stalled plan'),
      link_debt: t('Fix missing links'),
      unbilled_review: t('Assign unbilled hours'),
      custom: t('Custom promise'),
    };
    return map[key] || (key ? key : t('Custom promise'));
  }

  /** Pulse attention kind fallback when API omits kindLabel. */
  function pulseAttentionKindLabel(kind) {
    const key = String(kind || '');
    const map = {
      renewal_hot: t('Renewal soon'),
      overdue_invoice: t('Overdue invoice'),
      plan_stalled: t('Plan stalled'),
      plan_at_risk: t('Plan at risk'),
    };
    return map[key] || (key ? key : t('Attention'));
  }

  function workspaceId() {
    const fromUrl = new URLSearchParams(window.location.search).get('workspaceId');
    if (fromUrl) return parseInt(fromUrl, 10);
    const root = qs('[data-crm-workspace-switch]');
    if (root) {
      const attr = root.getAttribute('data-crm-active-workspace');
      if (attr) {
        const n = parseInt(attr, 10);
        if (n > 0) return n;
      }
    }
    const sw = qs('#crm-workspace-switch');
    if (sw && sw.value) return parseInt(sw.value, 10);
    return 0;
  }

  /**
   * Resolve workspace after navigation.js populates the switcher (race-safe).
   * Falls back to /api/v1/workspaces current id.
   */
  async function resolveWorkspaceId() {
    let id = workspaceId();
    if (id > 0) {
      return id;
    }
    for (let i = 0; i < 40; i++) {
      await new Promise(function (r) { setTimeout(r, 50); });
      id = workspaceId();
      if (id > 0) {
        return id;
      }
    }
    try {
      const data = await apiGet('/api/v1/workspaces');
      const current = data && data.current && data.current.id;
      if (current) {
        return parseInt(current, 10);
      }
      const first = (data.workspaces || [])[0];
      if (first && first.id) {
        return parseInt(first.id, 10);
      }
    } catch (e) {
      /* leave id 0 — callers show Select a workspace */
    }
    return 0;
  }

  async function apiGet(path) {
    const res = await fetch(OC.generateUrl('/apps/customercheck' + path), {
      credentials: 'same-origin',
      headers: { requesttoken: OC.requestToken },
    });
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.message || 'Request failed');
    }
    return json.data;
  }

  async function apiPost(path, body, opts) {
    const fetchOpts = {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        requesttoken: OC.requestToken,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body || {}),
    };
    if (opts && opts.signal) {
      fetchOpts.signal = opts.signal;
    }
    const res = await fetch(OC.generateUrl('/apps/customercheck' + path), fetchOpts);
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.message || 'Request failed');
    }
    return json.data;
  }

  function isAbortError(err) {
    return !!(err && (err.name === 'AbortError' || err.code === 20));
  }

  async function apiPut(path, body) {
    const res = await fetch(OC.generateUrl('/apps/customercheck' + path), {
      method: 'PUT',
      credentials: 'same-origin',
      headers: {
        requesttoken: OC.requestToken,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body || {}),
    });
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.message || 'Request failed');
    }
    return json.data;
  }

  async function apiDelete(path) {
    const res = await fetch(OC.generateUrl('/apps/customercheck' + path), {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { requesttoken: OC.requestToken },
    });
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.message || 'Request failed');
    }
    return json.data;
  }

  function makeDraftSendKey() {
    var rand = '';
    try {
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        var buf = new Uint8Array(8);
        window.crypto.getRandomValues(buf);
        rand = Array.from(buf, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
      }
    } catch (e) { /* fall through */ }
    if (!rand) {
      rand = Math.random().toString(36).slice(2, 10) + Math.random().toString(36).slice(2, 6);
    }
    return 'crm-draft-' + Date.now() + '-' + rand;
  }

  function euros(cents) {
    if (cents == null) return '—';
    return (Number(cents) / 100).toLocaleString(undefined, { style: 'currency', currency: 'EUR' });
  }

  function showAlert(parent, message) {
    const existing = parent.querySelector('[data-crm-inline-alert]');
    if (existing) existing.remove();
    const alert = document.createElement('p');
    alert.setAttribute('role', 'alert');
    alert.setAttribute('data-crm-inline-alert', '1');
    alert.textContent = message || String(message);
    parent.prepend(alert);
  }

  function clearMountBusy(mount) {
    if (!mount) return;
    mount.removeAttribute('aria-busy');
    mount.classList.remove('crm-skeleton');
  }

  /** Local calendar YYYY-MM-DD — never toISOString (UTC day shift). */
  function localYmd(d) {
    const x = d instanceof Date ? d : new Date();
    const y = x.getFullYear();
    const m = String(x.getMonth() + 1).padStart(2, '0');
    const day = String(x.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function utcYmd(d) {
    const y = d.getUTCFullYear();
    const m = String(d.getUTCMonth() + 1).padStart(2, '0');
    const day = String(d.getUTCDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function formatDisplayDate(iso) {
    const raw = String(iso || '').trim();
    if (!raw || raw === '—') return '—';
    const hasTime = /T|\s\d{1,2}:/.test(raw);
    const d = new Date(hasTime ? raw.replace(' ', 'T') : (raw + 'T12:00:00'));
    if (Number.isNaN(d.getTime())) return raw;
    try {
      const locale = (document.documentElement.lang || navigator.language || undefined);
      return new Intl.DateTimeFormat(locale, hasTime
        ? { dateStyle: 'medium', timeStyle: 'short' }
        : { dateStyle: 'medium' }).format(d);
    } catch (e) {
      return raw;
    }
  }

  /** Stable ms for Assign/Talk date sort (space timestamps → ISO). */
  function parseReviewTimestamp(raw) {
    const s = String(raw == null ? '' : raw).trim();
    if (!s) return 0;
    const normalized = /T/.test(s)
      ? s
      : (/^\d{4}-\d{2}-\d{2}\s+\d{1,2}:/.test(s) ? s.replace(' ', 'T') : (s + (/^\d{4}-\d{2}-\d{2}$/.test(s) ? 'T12:00:00' : '')));
    const ts = new Date(normalized).getTime();
    return Number.isFinite(ts) ? ts : 0;
  }

  function weekOfLabel(weekKey) {
    const m = String(weekKey || '').match(/^(\d{4})-W(\d{1,2})$/);
    if (!m) return weekKey || '';
    try {
      const year = parseInt(m[1], 10);
      const week = parseInt(m[2], 10);
      const simple = new Date(Date.UTC(year, 0, 1 + (week - 1) * 7));
      const day = simple.getUTCDay();
      const ISOweekStart = new Date(simple);
      if (day <= 4) ISOweekStart.setUTCDate(simple.getUTCDate() - simple.getUTCDay() + 1);
      else ISOweekStart.setUTCDate(simple.getUTCDate() + 8 - simple.getUTCDay());
      return t('Week of %s', [formatDisplayDate(utcYmd(ISOweekStart))]);
    } catch (e) {
      return weekKey;
    }
  }

  function renderList(mount, items, emptyMsg, emptyCta) {
    if ((!items || !items.length) && emptyCta && emptyCta.href) {
      clearMountBusy(mount);
      mount.innerHTML =
        '<div class="crm-empty-state" role="status">' +
        '<p class="crm-empty-state__text">' + escapeHtml(emptyMsg) + '</p>' +
        '<div class="crm-empty-state__actions">' +
        '<a class="crm-btn crm-btn--primary crm-btn--lg" href="' + escapeHtml(emptyCta.href) + '">' +
        escapeHtml(emptyCta.label || t('Open')) + '</a></div></div>';
      return;
    }
    renderSuiteTable(mount, items, emptyMsg, t('Items'));
  }

  /**
   * Suite table — same pattern as Today / Companies (What · Detail · Action).
   * @param {HTMLElement} mount
   * @param {Array<{title?: string, name?: string, why?: string, meta?: string, href?: string, cta?: string, actionHtml?: string, id?: number|string, selectable?: boolean}>} items
   * @param {string} emptyMsg
   * @param {string} ariaLabel
   * @param {{selectable?: boolean}=} options
   */
  function renderSuiteTable(mount, items, emptyMsg, ariaLabel, options) {
    clearMountBusy(mount);
    if (!items || !items.length) {
      mount.innerHTML = '<div class="crm-callout crm-callout--success" role="status"><p>' +
        escapeHtml(emptyMsg) + '</p></div>';
      return;
    }
    const selectable = !!(options && options.selectable);
    const hasRichActions = items.some(function (it) { return !!it.actionHtml; });
    const actionColClass = 'crm-table-actions-col actions-cell' +
      (hasRichActions ? ' crm-table-actions-col--wide' : '');
    const tableClass = 'table table--hover crm-table--responsive';
    let rows = '';
    items.forEach(function (it) {
      const title = decodeMimeHeader(it.title || it.name || it.why || '');
      const meta = it.meta || '';
      const href = it.href || '';
      const cta = it.cta || t('Open');
      let action;
      if (it.actionHtml) {
        action = it.actionHtml;
      } else if (href) {
        action = '<a class="crm-btn crm-btn--sm crm-btn--primary" href="' + escapeHtml(href) + '">' + escapeHtml(cta) + '</a>';
      } else {
        action = '<span class="crm-muted">—</span>';
      }
      const rowId = it.id != null ? String(it.id) : '';
      const canSelect = selectable && it.selectable !== false && rowId !== '';
      const selectCell = selectable
        ? ('<td data-label="' + escapeHtml(t('Select')) + '" class="crm-table-select-col">' +
          (canSelect
            ? ('<label class="crm-check crm-review-select">' +
              '<input type="checkbox" class="crm-input" data-crm-review-select="' + escapeHtml(rowId) + '" value="' + escapeHtml(rowId) + '" />' +
              '<span class="visually-hidden">' + escapeHtml(t('Select')) + ' ' + escapeHtml(title) + '</span></label>')
            : '') +
          '</td>')
        : '';
      rows += '<tr class="crm-today-row"' + (rowId ? ' data-crm-review-item-id="' + escapeHtml(rowId) + '"' : '') + '>' +
        selectCell +
        '<td data-label="' + escapeHtml(t('What')) + '"><span class="crm-today-what">' + escapeHtml(title) + '</span></td>' +
        '<td data-label="' + escapeHtml(t('Detail')) + '">' + (meta ? escapeHtml(meta) : '—') + '</td>' +
        '<td data-label="' + escapeHtml(t('Action')) + '" class="' + actionColClass + '"><div class="crm-table-actions">' +
        action + '</div></td></tr>';
    });
    const selectHead = selectable
      ? ('<th scope="col" class="crm-table-select-col">' +
        '<label class="crm-check crm-review-select-all">' +
        '<input type="checkbox" class="crm-input" data-crm-review-select-all />' +
        '<span class="visually-hidden">' + escapeHtml(t('Select all open items')) + '</span></label></th>')
      : '';
    mount.innerHTML =
      '<div class="table-container crm-table--responsive" role="region" aria-label="' + escapeHtml(ariaLabel) + '">' +
      '<table class="' + tableClass + '"' + (selectable ? ' data-crm-review-selectable="1"' : '') + '>' +
      '<thead><tr>' +
      selectHead +
      '<th scope="col">' + escapeHtml(t('What')) + '</th>' +
      '<th scope="col">' + escapeHtml(t('Detail')) + '</th>' +
      '<th scope="col" class="' + actionColClass + '">' + escapeHtml(t('Action')) + '</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  /**
   * Assign inbox — mail-thread list (same pattern as company Talk) + inline detail.
   * @returns {{ boardEl: Element, listEl: Element, readerEl: Element, detailRowEl: Element, tableEl: Element|null, options: Element[], colCount: number }|null}
   */
  function renderReviewBoard(mount, items, emptyMsg, ariaLabel, options) {
    clearMountBusy(mount);
    if (!items || !items.length) {
      mount.innerHTML = '<div class="crm-callout crm-callout--success" role="status"><p>' +
        escapeHtml(emptyMsg) + '</p></div>';
      return null;
    }
    const selectable = !!(options && options.selectable);
    const sortKey = (options && options.sortKey) || 'sent';
    const sortDir = (options && options.sortDir) === 'asc' ? 'asc' : 'desc';
    function sortBtn(col, label) {
      const active = sortKey === col;
      const arrow = !active ? '' : (sortDir === 'asc' ? ' ↑' : ' ↓');
      const sortHint = active
        ? (sortDir === 'asc' ? t('Sorted ascending') : t('Sorted descending'))
        : t('Not sorted');
      return '<button type="button" class="crm-btn crm-btn--ghost crm-review-sort' + (active ? ' is-active' : '') + '" ' +
        'data-crm-review-sort="' + escapeHtml(col) + '" aria-pressed="' + (active ? 'true' : 'false') + '" ' +
        'aria-label="' + escapeHtml(label + ' — ' + sortHint) + '">' +
        escapeHtml(label) + escapeHtml(arrow) + '</button>';
    }
    let rows = '';
    items.forEach(function (it) {
      const title = decodeMimeHeader(it.title || '');
      const fromLabel = decodeMimeHeader(it.from || it.snippet || '');
      const rowId = it.id != null ? String(it.id) : '';
      const canSelect = selectable && it.selectable !== false && rowId !== '';
      const badges = Array.isArray(it.badges) ? it.badges : [];
      const statusText = badges.map(function (b) { return String(b); }).join(' ');
      const metaHtml = badges.length
        ? badges.map(function (b) {
          return '<span class="crm-badge">' + escapeHtml(String(b)) + '</span>';
        }).join(' ')
        : '';
      const snippet = String(it.listSnippet || '').trim();
      const whenRaw = String(it.sentAt || it.createdAt || '').trim();
      const whenLabel = whenRaw ? formatDisplayDate(whenRaw) : '';
      const whenSort = String(parseReviewTimestamp(whenRaw));
      const selectCtl = selectable
        ? (canSelect
          ? ('<label class="crm-check crm-review-select">' +
            '<input type="checkbox" class="crm-input" data-crm-review-select="' + escapeHtml(rowId) + '" value="' + escapeHtml(rowId) + '" />' +
            '<span class="visually-hidden">' + escapeHtml(t('Select')) + ' ' + escapeHtml(title) + '</span></label>')
          : '<span class="crm-review-select crm-review-select--spacer" aria-hidden="true"></span>')
        : '';
      const optId = rowId ? ('crm-review-opt-' + rowId) : '';
      // list + listitem (not listbox) so checkboxes stay valid for axe nested-interactive.
      rows +=
        '<li class="crm-mail-thread-row crm-review-row"' +
        (optId ? ' id="' + escapeHtml(optId) + '"' : '') +
        (rowId ? ' data-crm-review-item-id="' + escapeHtml(rowId) + '"' : '') +
        ' data-crm-sort-from="' + escapeHtml(fromLabel.toLowerCase()) + '"' +
        ' data-crm-sort-subject="' + escapeHtml(title.toLowerCase()) + '"' +
        ' data-crm-sort-status="' + escapeHtml(statusText.toLowerCase()) + '"' +
        ' data-crm-sort-sent="' + escapeHtml(whenSort) + '"' +
        ' role="listitem" tabindex="-1" aria-current="false">' +
        selectCtl +
        '<div class="crm-mail-thread-row__main">' +
        '<p class="crm-mail-thread-row__from">' + escapeHtml(fromLabel || t('Unknown sender')) + '</p>' +
        '<p class="crm-mail-thread-row__subject crm-review-row__subject">' + escapeHtml(title) + '</p>' +
        (snippet
          ? ('<p class="crm-mail-thread-row__snippet crm-muted">' + escapeHtml(snippet) + '</p>')
          : '') +
        (metaHtml
          ? ('<p class="crm-feed__meta crm-muted">' + metaHtml + '</p>')
          : '') +
        '</div>' +
        (whenLabel
          ? ('<time class="crm-mail-thread-row__when" datetime="' + escapeHtml(whenRaw) + '">' +
            escapeHtml(whenLabel) + '</time>')
          : '<span class="crm-mail-thread-row__when crm-muted" aria-hidden="true">—</span>') +
        // Screen-reader / keyboard affordance only — row click already opens (no visible Open chrome).
        '<button type="button" class="crm-sr-only crm-review-open" data-crm-review-open="' +
        escapeHtml(rowId) + '" tabindex="-1" aria-label="' + escapeHtml(t('Open details: %s', [title])) + '">' +
        escapeHtml(t('Open')) + '</button>' +
        '</li>';
    });
    const selectToolbar = selectable
      ? ('<div class="crm-review-board__toolbar">' +
        '<label class="crm-check crm-review-select-all">' +
        '<input type="checkbox" class="crm-input" data-crm-review-select-all />' +
        '<span>' + escapeHtml(t('Select all open items')) + '</span></label>' +
        '<p class="crm-muted crm-review-board__hint" role="note">' +
        escapeHtml(t('Open a row to read it and decide.')) + '</p></div>')
      : ('<div class="crm-review-board__toolbar">' +
        '<p class="crm-muted crm-review-board__hint" role="note">' +
        escapeHtml(t('Open a row to read it and decide.')) + '</p></div>');
    mount.innerHTML =
      '<div class="crm-review-board" role="region" aria-label="' + escapeHtml(ariaLabel) + '"' +
      (selectable ? ' data-crm-review-selectable="1"' : '') + '>' +
      selectToolbar +
      '<div class="crm-review-sortbar" role="toolbar" aria-label="' + escapeHtml(t('Sort')) + '">' +
      sortBtn('sent', t('Date')) +
      sortBtn('from', t('From')) +
      sortBtn('subject', t('Subject')) +
      '</div>' +
      '<div class="crm-mail-3pane crm-review-mail crm-review-table">' +
      '<div class="crm-mail-3pane__thread">' +
      '<ul class="crm-mail-thread crm-review-list" role="list" aria-label="' + escapeHtml(t('Message list')) + '">' +
      rows + '</ul></div></div></div>';
    const listEl = mount.querySelector('.crm-review-list');
    // Real listitem (not presentation) so axe aria-required-children stays clean,
    // while placeDetailUnderRow can still park the pane directly under the opened row.
    const detailHost = document.createElement('li');
    detailHost.className = 'crm-mail-3pane__reader crm-review-detail-row';
    detailHost.setAttribute('data-crm-review-detail-row', '');
    detailHost.setAttribute('role', 'listitem');
    detailHost.setAttribute('aria-label', t('Details'));
    detailHost.hidden = true;
    const reader = document.createElement('section');
    reader.className = 'crm-review-detail crm-review-reader';
    reader.setAttribute('data-crm-review-reader', '');
    reader.setAttribute('role', 'region');
    reader.setAttribute('aria-label', t('Details'));
    reader.setAttribute('aria-live', 'polite');
    reader.tabIndex = -1;
    reader.innerHTML =
      '<h3 class="crm-review-detail__title visually-hidden">' + escapeHtml(t('Details')) + '</h3>' +
      '<div class="crm-mail-reader__empty crm-empty-state" role="status">' +
      '<p class="crm-empty-state__text">' + escapeHtml(t('Select a message to read and decide.')) + '</p>' +
      '</div>';
    detailHost.appendChild(reader);
    if (listEl && listEl.firstElementChild) {
      listEl.firstElementChild.after(detailHost);
    } else if (listEl) {
      listEl.appendChild(detailHost);
    }
    const rowOptions = Array.from(mount.querySelectorAll('li.crm-review-row'));
    if (rowOptions.length) {
      rowOptions[0].tabIndex = 0;
    }
    return {
      boardEl: mount.querySelector('.crm-review-board'),
      listEl: listEl,
      readerEl: reader,
      detailRowEl: detailHost,
      tableEl: mount.querySelector('.crm-review-mail'),
      options: rowOptions,
      colCount: 0,
    };
  }

  function pulseStat(value, label, hint, variant) {
    const tip = hint ? String(hint) : '';
    const titleAttr = tip ? ' title="' + escapeHtml(tip) + '"' : '';
    const aria = tip
      ? ' aria-label="' + escapeHtml(String(label) + ': ' + String(value) + '. ' + tip) + '"'
      : '';
    const mod = variant === 'alert' ? ' crm-today-stat--alert' : '';
    return '<div class="crm-today-stat' + mod + '" role="listitem"' + titleAttr + aria + '>' +
      '<span class="crm-today-stat__value"' + (tip ? ' aria-hidden="true"' : '') + '>' +
      escapeHtml(String(value)) + '</span>' +
      '<span class="crm-today-stat__label"' + (tip ? ' aria-hidden="true"' : '') + '>' +
      escapeHtml(label) + '</span>' +
      '</div>';
  }

  function focusCapacityIfHash() {
    if (window.location.hash !== '#capacity') return;
    const el = document.getElementById('capacity');
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.focus({ preventScroll: true });
  }

  async function loadPulse(mount) {
    const ws = await resolveWorkspaceId();
    if (!ws) {
      clearMountBusy(mount);
      mount.innerHTML = '<div class="crm-callout crm-callout--danger" role="alert"><p>' +
        escapeHtml(t('Select a workspace first.')) + '</p></div>';
      return;
    }
    const data = await apiGet('/api/v1/workspaces/' + ws + '/pulse');
    const m = data.metrics || {};
    const unbilled = m.unbilled || {};
    let unbilledValue = '—';
    if (unbilled.state === 'ok' && unbilled.openBillableMinutes != null) {
      unbilledValue = (unbilled.openBillableMinutes / 60).toFixed(1) + 'h';
    } else if (unbilled.displayValue) {
      unbilledValue = String(unbilled.displayValue);
    }
    const unbilledHint = unbilled.hint || (
      unbilled.state === 'honesty_blocked'
        ? t('Unbilled hours are not ready yet — we will not guess zero.')
        : (unbilled.state === 'unavailable'
          ? t('ProjectCheck is off — unbilled hours cannot be shown.')
          : '')
    );
    const todayHref = OC.generateUrl('/apps/customercheck/today') + '?workspaceId=' + ws;
    const debt = (data.linkDebt || []).map(function (d) {
      return {
        title: d.name,
        meta: t('Missing') + ': ' + (d.missing || []).join(', '),
        href: OC.generateUrl('/apps/customercheck/companies/' + d.companyId) + '?workspaceId=' + ws,
      };
    });
    const attention = (data.attention || []).slice(0, 25).map(function (a) {
      const metaParts = [];
      const kindLabel = a.kindLabel || pulseAttentionKindLabel(a.kind);
      if (kindLabel) metaParts.push(String(kindLabel));
      if (a.daysUntil != null) metaParts.push(t('%s days left', [String(a.daysUntil)]));
      if (a.amountCents != null) metaParts.push(euros(a.amountCents));
      // IC overdue aging — only when facade v3 fields are present (never invent).
      if (a.kind === 'overdue_invoice' && a.agingBucket != null && String(a.agingBucket) !== '') {
        const agingLabel = a.agingBucketLabel || String(a.agingBucket);
        if (a.daysPastDue != null) {
          metaParts.push(agingLabel + ' · ' + t('%s days past due', [String(a.daysPastDue)]));
        } else {
          metaParts.push(agingLabel);
        }
      }
      return {
        title: a.label || a.why || t('Attention'),
        meta: metaParts.join(' · '),
        href: a.companyId
          ? OC.generateUrl('/apps/customercheck/companies/' + a.companyId) + '?workspaceId=' + ws
          : undefined,
      };
    });

    const fed =
      t('Data from') + ': ProjectCheck ' + (data.fedBy && data.fedBy.projectcheck ? '✓' : '—') +
      ' · InvoiceCheck ' + (data.fedBy && data.fedBy.invoicecheck ? '✓' : '—') +
      ' · TicketCheck ' + (data.fedBy && data.fedBy.ticketcheck ? '✓' : '—');
    const companiesN = m.activeCustomers || 0;
    const overdueCents = Number(m.overdueCents) || 0;
    const glanceLead = t('Companies') + ': ' + String(companiesN) + ' · ' + fed;

    clearMountBusy(mount);
    mount.innerHTML =
      '<nav class="crm-quick-filters" aria-label="' + escapeHtml(t('Jump to section')) + '">' +
      '<a class="crm-quick-filter" href="#crm-pulse-metrics">' + escapeHtml(t('At a glance')) + '</a>' +
      '<a class="crm-quick-filter" href="#crm-pulse-attention">' + escapeHtml(t('Attention')) +
      '<span class="crm-quick-filter__count" aria-hidden="true">' + attention.length + '</span></a>' +
      '<a class="crm-quick-filter" href="#crm-pulse-debt">' + escapeHtml(t('Missing links')) +
      '<span class="crm-quick-filter__count" aria-hidden="true">' + debt.length + '</span></a>' +
      '</nav>' +

      '<section class="crm-card crm-pulse-glance" id="crm-pulse-metrics" aria-labelledby="crm-pulse-metrics-title">' +
      '<header class="crm-card__header">' +
      '<div class="crm-card__header-text">' +
      '<h2 id="crm-pulse-metrics-title" class="crm-card__title">' + escapeHtml(t('At a glance')) + '</h2>' +
      '<p class="crm-card__lead">' + escapeHtml(glanceLead) + '</p>' +
      '</div>' +
      '<a class="crm-btn crm-btn--primary" href="' + escapeHtml(todayHref) + '">' + escapeHtml(t('Go to Today')) + '</a>' +
      '</header>' +
      '<div class="crm-card__body">' +
      '<div class="crm-pulse-glance__group" role="group" aria-labelledby="crm-pulse-money-title">' +
      '<h3 id="crm-pulse-money-title" class="crm-pulse-glance__heading">' + escapeHtml(t('Cash')) + '</h3>' +
      '<div class="crm-today-role__stats crm-pulse-glance__stats" role="list">' +
      pulseStat(euros(overdueCents), t('Overdue'), null, overdueCents > 0 ? 'alert' : '') +
      pulseStat(euros(m.openCents), t('Unpaid')) +
      pulseStat(euros(m.paid30dCents), t('Paid lately')) +
      pulseStat(euros(m.pipelineCents), t('Open deals')) +
      '</div></div>' +
      '<div class="crm-pulse-glance__group" role="group" aria-labelledby="crm-pulse-work-title">' +
      '<h3 id="crm-pulse-work-title" class="crm-pulse-glance__heading">' + escapeHtml(t('Work')) + '</h3>' +
      '<div class="crm-today-role__stats crm-pulse-glance__stats" role="list">' +
      pulseStat(unbilledValue, t('Unbilled hours'), unbilledHint) +
      pulseStat(m.waitingTickets || 0, t('Waiting tickets')) +
      pulseStat(m.renewals30d || 0, t('Renewals soon')) +
      pulseStat(m.openProjects != null ? m.openProjects : '—', t('Open projects')) +
      '</div></div>' +
      (unbilled.state && unbilled.state !== 'ok' && unbilledHint
        ? '<p class="crm-muted" role="status">' + escapeHtml(unbilledHint) + '</p>'
        : '') +
      '</div></section>' +

      '<section class="crm-card" id="crm-pulse-attention" aria-labelledby="crm-pulse-attention-title">' +
      '<header class="crm-card__header">' +
      '<h2 id="crm-pulse-attention-title" class="crm-card__title">' + escapeHtml(t('Needs attention')) +
      ' <span class="crm-badge crm-badge--neutral">' + attention.length + '</span></h2>' +
      '</header>' +
      '<div class="crm-card__body" data-crm-pulse-attention></div></section>' +

      '<section class="crm-card" id="crm-pulse-debt" aria-labelledby="crm-pulse-debt-title">' +
      '<header class="crm-card__header">' +
      '<h2 id="crm-pulse-debt-title" class="crm-card__title">' + escapeHtml(t('Missing links')) +
      ' <span class="crm-badge crm-badge--neutral">' + debt.length + '</span></h2>' +
      '</header>' +
      '<div class="crm-card__body" data-crm-pulse-debt></div></section>';

    renderSuiteTable(
      mount.querySelector('[data-crm-pulse-attention]'),
      attention,
      t('Nothing needs attention right now.'),
      t('Attention queue'),
    );
    renderSuiteTable(
      mount.querySelector('[data-crm-pulse-debt]'),
      debt,
      t('All links set — nice.'),
      t('Missing links'),
    );
  }

  function verifyActionsHtml(id) {
    return '<div class="crm-brief-verify" role="group" aria-label="' + escapeHtml(t('Check this promise')) + '">' +
      '<button type="button" class="crm-btn crm-btn--primary" data-verify="' + id + '" data-action="done">' + t('Done') + '</button>' +
      '<details class="crm-more-menu crm-brief-verify__more">' +
      '<summary class="crm-btn crm-btn--ghost crm-btn--sm">' + t('More') + '</summary>' +
      '<div class="crm-more-menu__panel" role="group" aria-label="' + escapeHtml(t('Other outcomes')) + '">' +
      '<label class="form-label" for="crm-evidence-' + id + '">' + t('How was it done?') + '</label>' +
      '<select id="crm-evidence-' + id + '" class="crm-input crm-input--sm" data-evidence-for="' + id + '" aria-label="' + escapeHtml(t('How was it done?')) + '">' +
      '<option value="activity_done" selected>' + t('Activity done') + '</option>' +
      '<option value="invoice_paid">' + t('Invoice paid') + '</option>' +
      '<option value="links_fixed">' + t('Links fixed') + '</option>' +
      '<option value="plan_healthy">' + t('Plan healthy') + '</option>' +
      '<option value="manager_override">' + t('Manager override') + '</option>' +
      '</select>' +
      '<label class="crm-sr-only" for="crm-evidence-note-' + id + '">' + t('Reason for manager override') + '</label>' +
      '<input id="crm-evidence-note-' + id + '" type="text" class="crm-input crm-input--sm crm-brief-verify__note" data-evidence-note-for="' + id + '" placeholder="' + escapeHtml(t('Reason for manager override')) + '" autocomplete="off" hidden />' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-verify="' + id + '" data-action="missed">' + t('Missed') + '</button>' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-verify="' + id + '" data-action="carry">' + t('Carry') + '</button>' +
      '<button type="button" class="crm-btn crm-btn--ghost" data-verify="' + id + '" data-action="abandon">' + t('Abandon') + '</button>' +
      '</div></details>' +
      '</div>';
  }

  async function loadBrief(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/brief');
    const cash = data.cash || {};
    clearMountBusy(mount);
    mount.innerHTML =
      '<section class="crm-card crm-today-role" aria-labelledby="crm-brief-wip-title">' +
      '<header class="crm-card__header"><div class="crm-card__header-text">' +
      '<h2 id="crm-brief-wip-title" class="crm-card__title">' + escapeHtml(t('This week')) + '</h2>' +
      '<p class="crm-card__lead">' + escapeHtml(t('This week')) + ': ' + (data.wipUsed || 0) + ' ' +
      escapeHtml(t('of')) + ' ' + (data.wipLimit || 5) + ' ' + escapeHtml(t('promises')) +
      (data.weekKey ? ' · ' + escapeHtml(weekOfLabel(data.weekKey)) : '') + '</p></div></header>' +
      (data.forecastDisclaimer
        ? '<div class="crm-card__body"><p class="crm-muted">' + escapeHtml(data.forecastDisclaimer) + '</p></div>'
        : '') +
      '</section>' +
      '<section class="crm-card" aria-labelledby="crm-brief-cash-title">' +
      '<header class="crm-card__header"><h2 id="crm-brief-cash-title" class="crm-card__title">' +
      escapeHtml(t('Money this month')) + '</h2></header>' +
      '<div class="crm-card__body">' +
      '<div class="crm-today-role__stats" role="list" aria-label="' + escapeHtml(t('Money this month')) + '">' +
      pulseStat(euros(cash.overdueArCents), t('Money customers still owe')) +
      pulseStat(euros(cash.expectedInThisMonthCents), t('Expected cash this month')) +
      pulseStat(euros(cash.renewalsThisMonthCents), t('Renewals this month')) +
      '</div>' +
      '<details class="crm-advanced"><summary class="crm-advanced__summary">' + escapeHtml(t('More money detail')) + '</summary>' +
      '<div class="crm-today-role__stats" role="list">' +
      pulseStat(euros(cash.arDueThisMonthCents), t('Due this month')) +
      pulseStat(euros(cash.arDueNextMonthCents), t('Due next month')) +
      pulseStat(euros(cash.renewalsNextMonthCents), t('Renewals next month')) +
      '</div>' +
      '<p class="crm-muted" role="status">' + escapeHtml(t('Open deals not counted')) + ': ' + euros(cash.pipelineExcludedCents) +
      ' · ' + escapeHtml(t('Open deals are not cash.')) + '</p></details>' +
      '</div></section>' +
      '<section class="crm-card" aria-labelledby="crm-brief-last-title">' +
      '<header class="crm-card__header"><h2 id="crm-brief-last-title" class="crm-card__title">' +
      escapeHtml(t('Last week')) + '</h2></header><div class="crm-card__body" data-crm-brief-last></div></section>';

    const last = mount.querySelector('[data-crm-brief-last]');
    const lastItems = data.lastWeek || [];
    renderSuiteTable(
      last,
      lastItems.map(function (c) {
        const status = String(c.status || '');
        const needsVerify = status === 'committed' || status === 'missed';
        const metaParts = [commitmentStatusLabel(status)];
        if (c.dueOn) metaParts.push(String(c.dueOn));
        return {
          title: c.title || t('Commitment'),
          meta: metaParts.join(' · '),
          actionHtml: needsVerify && c.id && shellCan('brief')
            ? verifyActionsHtml(c.id)
            : '<span class="crm-muted">—</span>',
        };
      }),
      t('No commitments from last week.'),
      t('Last week'),
    );
    if (lastItems.length) {
      last.addEventListener('change', function (ev) {
        const sel = ev.target.closest('[data-evidence-for]');
        if (!sel) return;
        const id = sel.getAttribute('data-evidence-for');
        const note = last.querySelector('[data-evidence-note-for="' + id + '"]');
        if (!note) return;
        const isOverride = String(sel.value || '') === 'manager_override';
        note.hidden = !isOverride;
        if (!isOverride) {
          note.value = '';
        }
      });
      last.addEventListener('click', async function (ev) {
        const btn = ev.target.closest('[data-verify][data-action]');
        if (!btn) return;
        const id = btn.getAttribute('data-verify');
        const action = btn.getAttribute('data-action');
        const payload = { action: action };
        if (action === 'done') {
          const sel = last.querySelector('[data-evidence-for="' + id + '"]');
          const evidence = sel ? String(sel.value || '').trim() : '';
          const chosen = evidence || 'activity_done';
          payload.evidence = chosen;
          if (chosen === 'manager_override') {
            const noteEl = last.querySelector('[data-evidence-note-for="' + id + '"]');
            const note = noteEl ? String(noteEl.value || '').trim() : '';
            if (!note) {
              showAlert(mount, t('Manager override needs a reason.'));
              return;
            }
            payload.note = note;
          }
        }
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/brief/' + id + '/verify', payload);
          await loadBrief(mount);
        } catch (e) {
          showAlert(mount, e.message || String(e));
        }
      });
    }

    const thisWeek = document.createElement('section');
    thisWeek.className = 'crm-card';
    thisWeek.setAttribute('aria-labelledby', 'crm-brief-this-title');
    thisWeek.innerHTML =
      '<header class="crm-card__header"><h2 id="crm-brief-this-title" class="crm-card__title">' +
      escapeHtml(t('This week')) + '</h2></header><div class="crm-card__body" data-crm-brief-this></div>';
    mount.appendChild(thisWeek);
    const list = thisWeek.querySelector('[data-crm-brief-this]');
    renderList(list, (data.commitments || []).map(function (c) {
      return {
        title: c.title,
        meta: commitmentStatusLabel(c.status) + (c.dueOn ? ' · ' + c.dueOn : ''),
      };
    }), t('No promises yet this week.'));

    const wipUsed = Number(data.wipUsed) || 0;
    const wipLimit = Number(data.wipLimit) || 5;
    const wipLeft = Math.max(0, wipLimit - wipUsed);
    const canBrief = shellCan('brief');
    const canConfirm = canBrief && wipLeft > 0;
    const suggestedKinds = (data.suggestedKinds || []).filter(function (k) {
      return k && k !== 'custom';
    });

    const formCard = document.createElement('section');
    formCard.className = 'crm-card';
    formCard.id = 'crm-brief-add';
    formCard.setAttribute('aria-labelledby', 'crm-brief-add-title');

    if (!canBrief) {
      formCard.innerHTML =
        '<header class="crm-card__header"><div class="crm-card__header-text">' +
        '<h2 id="crm-brief-add-title" class="crm-card__title">' + escapeHtml(t('Add a commitment')) + '</h2>' +
        '<p class="crm-card__lead">' + escapeHtml(t('View only')) + '</p></div></header>' +
        '<div class="crm-card__body">' +
        '<div class="crm-callout" role="status"><p>' +
        escapeHtml(t('You can read the weekly Brief here. Ask a workspace member to add commitments.')) +
        '</p></div></div>';
      mount.appendChild(formCard);
      return;
    }

    if (!canConfirm) {
      formCard.innerHTML =
        '<header class="crm-card__header"><div class="crm-card__header-text">' +
        '<h2 id="crm-brief-add-title" class="crm-card__title">' + escapeHtml(t('Add a commitment')) + '</h2>' +
        '<p class="crm-card__lead">' + escapeHtml(t('Your week is full.')) + '</p></div></header>' +
        '<div class="crm-card__body">' +
        '<div class="crm-callout" role="status"><p>' +
        escapeHtml(t('Your week is full. Finish or drop a promise first.')) +
        '</p></div></div>';
      mount.appendChild(formCard);
      return;
    }

    let suggestHtml = '';
    if (suggestedKinds.length) {
      suggestHtml =
        '<div class="crm-brief-suggest" role="group" aria-label="' + escapeHtml(t('Quick promises')) + '">' +
        '<p class="crm-brief-suggest__lead">' + escapeHtml(t('Or tap a ready-made promise:')) + '</p>' +
        '<div class="crm-brief-suggest__actions">';
      suggestedKinds.forEach(function (kind) {
        suggestHtml +=
          '<button type="button" class="crm-btn crm-btn--secondary" data-crm-brief-suggest="' +
          escapeHtml(String(kind)) + '">' + escapeHtml(commitmentKindLabel(kind)) + '</button>';
      });
      suggestHtml += '</div></div>';
    }

    const slotsLead = t('Promises left') + ': ' + String(wipLeft) + '/' + String(wipLimit) +
      '. ' + t('One clear sentence about what you will finish.');

    formCard.innerHTML =
      '<header class="crm-card__header"><div class="crm-card__header-text">' +
      '<h2 id="crm-brief-add-title" class="crm-card__title">' + escapeHtml(t('Add a commitment')) + '</h2>' +
      '<p class="crm-card__lead">' + escapeHtml(slotsLead) + '</p></div></header>' +
      '<div class="crm-card__body">' +
      suggestHtml +
      '<form class="crm-form crm-form--stack" id="crm-brief-confirm-form" aria-labelledby="crm-brief-add-title">' +
      '<div class="form-group">' +
      '<label class="form-label" for="crm-brief-title">' + escapeHtml(t('What will you finish?')) + '</label>' +
      '<input id="crm-brief-title" name="title" type="text" required maxlength="255" ' +
      'class="crm-input form-input" autocomplete="off" aria-describedby="crm-brief-title-help" ' +
      'placeholder="' + escapeHtml(t('Example: Follow up Acme overdue invoice')) + '" />' +
      '<p class="form-help" id="crm-brief-title-help">' +
      escapeHtml(t('Name the company or task so your future self understands it.')) + '</p>' +
      '</div>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary" id="crm-brief-confirm-submit">' +
      escapeHtml(t('Add to this week')) + '</button>' +
      '</div></form></div>';
    mount.appendChild(formCard);

    async function postCommitment(title, kind) {
      const clean = String(title || '').trim();
      if (!clean) return;
      const submitBtn = formCard.querySelector('#crm-brief-confirm-submit');
      const suggestBtns = formCard.querySelectorAll('[data-crm-brief-suggest]');
      if (submitBtn) submitBtn.disabled = true;
      suggestBtns.forEach(function (b) { b.disabled = true; });
      try {
        await apiPost('/api/v1/workspaces/' + ws + '/brief/confirm', {
          weekKey: data.weekKey,
          title: clean,
          kind: kind || 'custom',
        });
        await loadBrief(mount);
      } catch (e) {
        if (submitBtn) submitBtn.disabled = false;
        suggestBtns.forEach(function (b) { b.disabled = false; });
        showAlert(formCard, e.message || String(e));
      }
    }

    const form = formCard.querySelector('#crm-brief-confirm-form');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      const title = form.querySelector('#crm-brief-title').value;
      postCommitment(title, 'custom');
    });
    formCard.addEventListener('click', function (ev) {
      const btn = ev.target.closest('[data-crm-brief-suggest]');
      if (!btn) return;
      const kind = btn.getAttribute('data-crm-brief-suggest');
      postCommitment(commitmentKindLabel(kind), kind);
    });
  }

  async function loadAccounts(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/my-accounts');
    renderList(mount, (data.items || []).map(function (a) {
      const cover = a.covering
        ? (' · ' + t('Covering') + (a.coveringPrimary ? ' ' + a.coveringPrimary : ''))
        : '';
      const flags = (a.flagLabels && a.flagLabels.length)
        ? (' · ' + a.flagLabels.join(' · '))
        : '';
      return {
        title: a.name,
        meta: (a.gap ? t('Needs attention') : t('Up to date')) +
          (a.nextTouchOn ? ' · ' + formatDisplayDate(a.nextTouchOn) : '') + flags + cover,
        href: OC.generateUrl('/apps/customercheck/companies/' + a.id) + '?workspaceId=' + ws,
      };
    }), t('No customers assigned to you yet.'), {
      href: OC.generateUrl('/apps/customercheck/companies/new') + '?workspaceId=' + ws,
      label: t('Add customer'),
    });
  }

  function userPickerFieldHtml(opts) {
    const id = opts.id;
    const name = opts.name;
    const label = opts.label;
    const required = opts.required ? ' required' : '';
    const showHelp = opts.help !== false;
    return (
      '<div class="form-group">' +
      '<label class="form-label" for="' + id + '-search">' + escapeHtml(label) + '</label>' +
      '<div class="user-picker" id="' + id + '-picker" data-crm-user-picker="1">' +
      '<div class="user-picker__control">' +
      '<input type="search" id="' + id + '-search" class="form-input crm-input user-picker__search" ' +
      'role="combobox" aria-autocomplete="list" aria-controls="' + id + '-suggest" aria-expanded="false" ' +
      'autocomplete="off" placeholder="' + escapeHtml(t('Search by name or login…')) + '"' + required + ' />' +
      '<button type="button" class="user-picker__clear" id="' + id + '-clear" hidden aria-label="' +
      escapeHtml(t('Clear')) + '">×</button></div>' +
      '<ul id="' + id + '-suggest" class="user-picker__list" role="listbox" hidden></ul>' +
      '<input type="hidden" id="' + id + '" name="' + name + '" value=""' + required + ' />' +
      '</div>' +
      (showHelp
        ? '<p class="form-help">' + escapeHtml(t('Search and pick. Never type a raw user id.')) + '</p>'
        : '') +
      '</div>'
    );
  }

  function teamCapacityPanel(step, title, titleId, lead, bodyHtml, open) {
    return (
      '<details class="crm-team-capacity__panel"' + (open ? ' open' : '') +
      ' aria-labelledby="' + escapeHtml(titleId) + '">' +
      '<summary class="crm-team-capacity__panel-summary">' +
      '<span class="crm-team-capacity__step" aria-hidden="true">' + String(step) + '</span>' +
      '<span class="crm-team-capacity__panel-head">' +
      '<span class="crm-team-capacity__panel-title" id="' + escapeHtml(titleId) + '">' + escapeHtml(title) + '</span>' +
      '<span class="crm-team-capacity__panel-lead">' + escapeHtml(lead) + '</span>' +
      '</span></summary>' +
      '<div class="crm-team-capacity__panel-body">' + bodyHtml + '</div>' +
      '</details>'
    );
  }

  /**
   * Bind a single-select directory combobox. Commits only a picked uid into the hidden field.
   */
  function bindPersonaUserPicker(opts) {
    const search = document.getElementById(opts.searchId);
    const suggest = document.getElementById(opts.suggestId);
    const hidden = document.getElementById(opts.hiddenId);
    const clearBtn = opts.clearId ? document.getElementById(opts.clearId) : null;
    if (!search || !suggest || !hidden) {
      return;
    }
    let timer = null;
    let searchGeneration = 0;
    const nav = (window.CustomerCheckUserPickerNav && window.CustomerCheckUserPickerNav.bindListboxKeyboard)
      ? window.CustomerCheckUserPickerNav.bindListboxKeyboard(search, suggest)
      : { reset: function () {} };
    const syncClear = function () {
      if (clearBtn) clearBtn.hidden = !hidden.value;
    };
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        hidden.value = '';
        search.value = '';
        suggest.innerHTML = '';
        suggest.hidden = true;
        search.setAttribute('aria-expanded', 'false');
        nav.reset();
        syncClear();
        search.focus();
      });
    }
    search.addEventListener('input', function () {
      if (hidden.value) {
        hidden.value = '';
        syncClear();
      }
      clearTimeout(timer);
      const q = search.value.trim();
      if (q.length < 2) {
        searchGeneration += 1;
        suggest.hidden = true;
        suggest.innerHTML = '';
        search.setAttribute('aria-expanded', 'false');
        nav.reset();
        return;
      }
      searchGeneration += 1;
      const myGeneration = searchGeneration;
      timer = setTimeout(async function () {
        try {
          const ws = typeof opts.workspaceIdFn === 'function' ? opts.workspaceIdFn() : 0;
          let path = '/api/v1/settings/users?q=' + encodeURIComponent(q);
          if (ws > 0) {
            path += '&workspaceId=' + encodeURIComponent(String(ws));
          }
          const data = await apiGet(path);
          if (myGeneration !== searchGeneration) {
            return;
          }
          const users = (data && data.users) || [];
          suggest.innerHTML = '';
          users.forEach(function (u) {
            const opt = document.createElement('li');
            opt.className = 'user-picker__item';
            opt.setAttribute('role', 'option');
            opt.setAttribute('aria-selected', 'false');
            const nameEl = document.createElement('span');
            nameEl.className = 'user-picker__name';
            nameEl.textContent = u.displayName || u.id;
            const meta = document.createElement('span');
            meta.className = 'user-picker__meta';
            meta.textContent = u.id || '';
            opt.appendChild(nameEl);
            opt.appendChild(meta);
            opt.addEventListener('click', function () {
              hidden.value = String(u.id || '');
              search.value = (u.displayName || u.id || '') + (u.id ? ' (' + u.id + ')' : '');
              suggest.innerHTML = '';
              suggest.hidden = true;
              search.setAttribute('aria-expanded', 'false');
              nav.reset();
              syncClear();
            });
            suggest.appendChild(opt);
          });
          suggest.hidden = users.length === 0;
          search.setAttribute('aria-expanded', users.length === 0 ? 'false' : 'true');
          nav.reset();
        } catch (e) {
          suggest.hidden = true;
          search.setAttribute('aria-expanded', 'false');
          nav.reset();
        }
      }, 250);
    });
    syncClear();
  }

  function teamFormsHtml(opts) {
    opts = opts || {};
    const showBulk = opts.showBulk !== false;
    const pickerOpts = { help: false };
    let bulkSection = '';
    if (showBulk) {
      const bulkStep = 2;
      bulkSection = teamCapacityPanel(
        bulkStep,
        t('Move work to someone else'),
        'crm-bulk-title',
        t('Hand deals, accounts, and open tasks to another colleague.'),
        '<form class="crm-form crm-form--stack" data-crm-bulk-form aria-labelledby="crm-bulk-title">' +
        '<div class="crm-form-row-2 crm-team-capacity__pickers">' +
        userPickerFieldHtml(Object.assign({ id: 'crm-bulk-from', name: 'fromUser', label: t('From colleague'), required: true }, pickerOpts)) +
        userPickerFieldHtml(Object.assign({ id: 'crm-bulk-to', name: 'toUser', label: t('To colleague'), required: true }, pickerOpts)) +
        '</div>' +
        '<div class="crm-team-capacity__confirm">' +
        '<label class="crm-check" for="crm-bulk-confirm">' +
        '<input type="checkbox" id="crm-bulk-confirm" name="confirmReassign" value="1" required /> ' +
        escapeHtml(t('I confirm reassigning work from the selected “from” colleague')) +
        '</label></div>' +
        '<details class="crm-team-capacity__options">' +
        '<summary class="crm-team-capacity__options-summary">' + escapeHtml(t('What to move')) + '</summary>' +
        '<fieldset class="crm-fieldset crm-team-capacity__options-fieldset">' +
        '<legend class="visually-hidden">' + escapeHtml(t('What to move')) + '</legend>' +
        '<label class="crm-check"><input type="checkbox" name="entityTypes" value="deals" checked> ' + escapeHtml(t('Deals')) + '</label>' +
        '<label class="crm-check"><input type="checkbox" name="entityTypes" value="accounts" checked> ' + escapeHtml(t('Account owners')) + '</label>' +
        '<label class="crm-check"><input type="checkbox" name="entityTypes" value="promises" checked> ' + escapeHtml(t('Open promises')) + '</label>' +
        '<label class="crm-check"><input type="checkbox" name="entityTypes" value="commitments"> ' + escapeHtml(t('Commitments')) + '</label>' +
        '<label class="crm-check"><input type="checkbox" name="entityTypes" value="checklist" checked> ' + escapeHtml(t('Deal checklist')) + '</label>' +
        '<label class="crm-check"><input type="checkbox" name="entityTypes" value="activities" checked> ' + escapeHtml(t('Open to-dos')) + '</label>' +
        '</fieldset></details>' +
        '<div class="crm-form-actions crm-team-capacity__actions">' +
        '<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg">' + escapeHtml(t('Reassign')) + '</button></div>' +
        '</form>',
        false,
      );
    }
    const escStep = showBulk ? 3 : 2;
    const oooBody =
      '<form class="crm-form crm-form--stack" data-crm-ooo-form aria-labelledby="crm-ooo-title">' +
      '<div class="crm-form-row-2 crm-team-capacity__pickers">' +
      userPickerFieldHtml(Object.assign({ id: 'crm-ooo-primary', name: 'primaryUserId', label: t('Person away'), required: true }, pickerOpts)) +
      userPickerFieldHtml(Object.assign({ id: 'crm-ooo-backup', name: 'backupUserId', label: t('Covering colleague'), required: true }, pickerOpts)) +
      '</div>' +
      '<div class="crm-form-row-2 crm-team-capacity__dates">' +
      '<div class="form-group"><label class="form-label" for="crm-ooo-from">' + escapeHtml(t('From')) + '</label>' +
      '<input id="crm-ooo-from" name="from" type="date" required class="crm-input form-input datepicker-input" autocomplete="off" /></div>' +
      '<div class="form-group"><label class="form-label" for="crm-ooo-until">' + escapeHtml(t('Until')) + '</label>' +
      '<input id="crm-ooo-until" name="until" type="date" required class="crm-input form-input datepicker-input" autocomplete="off" data-datepicker-min-from="crm-ooo-from" /></div>' +
      '</div>' +
      '<p class="form-help crm-team-capacity__hint">' + escapeHtml(t('Cover lasts at most 60 days.')) + '</p>' +
      '<div class="crm-form-actions crm-team-capacity__actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg">' + escapeHtml(t('Save cover')) + '</button></div>' +
      '</form>';
    const escBody =
      '<form class="crm-form crm-form--stack" data-crm-escalate-form aria-labelledby="crm-esc-title">' +
      userPickerFieldHtml(Object.assign({ id: 'crm-esc-subject', name: 'subjectUserId', label: t('Teammate'), required: true }, pickerOpts)) +
      '<div class="form-group"><label class="form-label" for="crm-esc-reason">' + escapeHtml(t('Why')) + '</label>' +
      '<textarea id="crm-esc-reason" name="reason" required class="crm-input form-input" rows="3" maxlength="2000"></textarea></div>' +
      '<div class="crm-form-actions crm-team-capacity__actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg">' + escapeHtml(t('Create escalation')) + '</button></div>' +
      '</form>';
    return '' +
      '<div class="crm-team-capacity__stack">' +
      '<p class="crm-team-capacity__intro">' + escapeHtml(t('One step at a time — open a section, complete it, then move on. Pick people from search.')) + '</p>' +
      teamCapacityPanel(1, t('Cover while away'), 'crm-ooo-title', t('Who is out and who covers them.'), oooBody, true) +
      bulkSection +
      teamCapacityPanel(escStep, t('Escalate for help'), 'crm-esc-title', t('Ask leadership to look at a teammate.'), escBody, false) +
      '</div>';
  }

  async function loadTeam(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/team');
    clearMountBusy(mount);
    mount.innerHTML = '';

    const capacityHost = document.querySelector('[data-crm-team-capacity]');
    const rows = data.rows || [];
    const summary = data.summary || {};
    const people = summary.people != null ? summary.people : rows.length;
    const stuckN = summary.stuck != null ? summary.stuck : rows.filter(function (r) { return !!r.stuck; }).length;

    const stats = document.createElement('div');
    stats.className = 'crm-today-role__stats';
    stats.setAttribute('role', 'list');
    stats.setAttribute('aria-label', t('Team snapshot'));
    stats.innerHTML =
      pulseStat(people, t('People')) +
      pulseStat(stuckN, t('Need help'));
    mount.appendChild(stats);

    if (!rows.length) {
      const empty = document.createElement('div');
      empty.className = 'crm-empty-state';
      empty.setAttribute('role', 'status');
      empty.innerHTML =
        '<h3 class="crm-empty-state__title">' + escapeHtml(t('No teammates here yet')) + '</h3>' +
        '<p class="crm-empty-state__text">' + escapeHtml(t('Add people to this workspace in Settings, then come back to help them.')) + '</p>' +
        '<div class="crm-empty-state__actions">' +
        '<a class="crm-btn crm-btn--primary crm-btn--lg" href="' +
        escapeHtml(OC.generateUrl('/apps/customercheck/settings/mailbox') + '?workspaceId=' + ws) + '">' +
        escapeHtml(t('Open Settings')) + '</a></div>';
      mount.appendChild(empty);
    } else {
      const wrap = document.createElement('div');
      wrap.className = 'table-container crm-table--responsive';
      wrap.setAttribute('role', 'region');
      wrap.setAttribute('aria-label', t('Team coaching board'));
      const table = document.createElement('table');
      table.className = 'table table--hover crm-table crm-table--responsive crm-table--team';
      table.innerHTML =
        '<thead><tr>' +
        '<th scope="col">' + escapeHtml(t('Person')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Status')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Open deals')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Old quotes')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Missing coverage')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Overdue work')) + '</th>' +
        '<th scope="col">' + escapeHtml(t('Away')) + '</th>' +
        '<th scope="col" class="crm-table-actions-col">' + escapeHtml(t('Actions')) + '</th>' +
        '</tr></thead>';
      const tbody = document.createElement('tbody');
      let stuckCount = 0;
      rows.forEach(function (r) {
        if (r.stuck) stuckCount += 1;
        const tr = document.createElement('tr');
        const name = r.displayName || r.userId || '';
        const stuckLabel = r.stuck
          ? (t('Needs help') + (r.stuckScore ? ' · ' + r.stuckScore : ''))
          : t('On track');
        const stuckBadge = r.stuck ? 'warning' : 'success';
        let oooHtml = '<span class="crm-muted" aria-hidden="true">—</span><span class="crm-sr-only">' + escapeHtml(t('Not scheduled')) + '</span>';
        if (r.ooo) {
          const oooLabel = r.ooo.covering
            ? (t('Covering') + ' ' + (r.ooo.coveringPrimary || ''))
            : t('Away');
          oooHtml = '<span class="crm-badge crm-badge--info">' + escapeHtml(oooLabel) + '</span>';
        }
        tr.innerHTML =
          '<td data-label="' + escapeHtml(t('Person')) + '">' +
          '<span class="crm-team-person">' + escapeHtml(name) + '</span>' +
          (r.roleLabel ? (' <span class="crm-muted">· ' + escapeHtml(r.roleLabel) + '</span>') : '') +
          (r.userId && r.userId !== name ? (' <span class="crm-sr-only">' + escapeHtml(r.userId) + '</span>') : '') +
          '</td>' +
          '<td data-label="' + escapeHtml(t('Status')) + '"><span class="crm-badge crm-badge--' + stuckBadge + '">' + escapeHtml(stuckLabel) + '</span></td>' +
          '<td data-label="' + escapeHtml(t('Open deals')) + '">' + (r.chase || 0) + '</td>' +
          '<td data-label="' + escapeHtml(t('Old quotes')) + '">' + (r.quote14 || 0) + '</td>' +
          '<td data-label="' + escapeHtml(t('Missing coverage')) + '">' + (r.coverageGaps || 0) + '</td>' +
          '<td data-label="' + escapeHtml(t('Overdue work')) + '">' + (r.overdueWork || 0) + '</td>' +
          '<td data-label="' + escapeHtml(t('Away')) + '">' + oooHtml + '</td>' +
          '<td data-label="' + escapeHtml(t('Actions')) + '" class="actions-cell crm-table-actions-col">' +
          '<div class="crm-table-actions">' +
          (!data.selfOnly
            ? ('<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-one-on-one="' +
              escapeHtml(r.userId || '') + '">' + escapeHtml(t('Coach')) + '</button> ')
            : '') +
          '<a class="crm-btn crm-btn--sm crm-btn--secondary" href="#capacity">' + escapeHtml(t('Tools')) + '</a>' +
          '</div></td>';
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrap.appendChild(table);
      mount.appendChild(wrap);
      if (stuckCount > 0 && !data.selfOnly) {
        const shell = document.querySelector('details.crm-team-capacity-shell');
        if (shell instanceof HTMLDetailsElement) {
          shell.open = true;
        }
      }
    }

    if ((data.escalations || []).length) {
      const escCard = document.createElement('section');
      escCard.className = 'crm-team-escalations';
      escCard.setAttribute('aria-labelledby', 'crm-team-esc-list-title');
      escCard.innerHTML = '<h3 id="crm-team-esc-list-title" class="crm-card__title">' + escapeHtml(t('Open escalations')) + '</h3>';
      const escMount = document.createElement('div');
      escCard.appendChild(escMount);
      mount.appendChild(escCard);
      renderList(escMount, data.escalations.map(function (e) {
        return {
          title: e.subjectUserId,
          meta: e.reason,
        };
      }), '');
    }

    if (capacityHost) {
      if (!data.selfOnly && shellCan('coach')) {
        capacityHost.innerHTML = teamFormsHtml({ showBulk: shellCan('bulk') });
        wireTeamCapacityForms(mount, capacityHost, ws);
      } else if (!data.selfOnly) {
        capacityHost.innerHTML =
          '<div class="crm-callout crm-callout--info" role="status"><p>' +
          escapeHtml(t('Team coaching tools need a manager role in this workspace.')) +
          '</p></div>';
      } else {
        capacityHost.innerHTML =
          '<div class="crm-callout crm-callout--info" role="status"><p>' +
          escapeHtml(t('Managers set cover and reassign here. You are viewing your own row only.')) +
          '</p></div>';
      }
    }

    focusCapacityIfHash();
  }

  function wireTeamCapacityForms(mount, capacityHost, ws) {
    const capacityUi = window.CustomerCheckTeamCapacityUi || {};
    if (typeof capacityUi.bindExclusiveAccordion === 'function') {
      capacityUi.bindExclusiveAccordion(capacityHost, 0);
    }
    const submitGuard = typeof capacityUi.withSubmitGuard === 'function'
      ? capacityUi.withSubmitGuard
      : async function (form, fn) { await fn(); };
    const isoDate = function (form, name) {
      const el = form.elements[name];
      const DP = window.CustomerCheckDatepicker;
      if (el && DP && typeof DP.isoValue === 'function') {
        return String(DP.isoValue(el) || '').trim();
      }
      return String((el && el.value) || '').trim();
    };
    const oooErrorMessage = function (code) {
      if (code === 'same_person') {
        return t('Pick a different colleague to receive the work.');
      }
      if (code === 'missing_dates') {
        return t('Choose a date');
      }
      if (code === 'invalid_range') {
        return t('The end date cannot be before the start date.');
      }
      if (code === 'too_long') {
        return t('Cover lasts at most 60 days.');
      }
      return t('Select a colleague from the directory first.');
    };

    const wsFn = function () { return ws; };
    [
      { searchId: 'crm-ooo-primary-search', suggestId: 'crm-ooo-primary-suggest', hiddenId: 'crm-ooo-primary', clearId: 'crm-ooo-primary-clear' },
      { searchId: 'crm-ooo-backup-search', suggestId: 'crm-ooo-backup-suggest', hiddenId: 'crm-ooo-backup', clearId: 'crm-ooo-backup-clear' },
      { searchId: 'crm-bulk-from-search', suggestId: 'crm-bulk-from-suggest', hiddenId: 'crm-bulk-from', clearId: 'crm-bulk-from-clear' },
      { searchId: 'crm-bulk-to-search', suggestId: 'crm-bulk-to-suggest', hiddenId: 'crm-bulk-to', clearId: 'crm-bulk-to-clear' },
      { searchId: 'crm-esc-subject-search', suggestId: 'crm-esc-subject-suggest', hiddenId: 'crm-esc-subject', clearId: 'crm-esc-subject-clear' },
    ].forEach(function (cfg) {
      bindPersonaUserPicker(Object.assign({ workspaceIdFn: wsFn }, cfg));
    });

    const oooForm = capacityHost.querySelector('[data-crm-ooo-form]');
    if (oooForm) {
      oooForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitGuard(oooForm, async function () {
        const fd = new FormData(oooForm);
        const from = isoDate(oooForm, 'from');
        const until = isoDate(oooForm, 'until');
        const primary = String(fd.get('primaryUserId') || '').trim();
        const backup = String(fd.get('backupUserId') || '').trim();
        const validate = capacityUi.validateOooCoverInput;
        const code = typeof validate === 'function'
          ? validate({ primaryUserId: primary, backupUserId: backup, startsOn: from, endsOn: until, maxDays: 60 })
          : null;
        if (code) {
          showAlert(oooForm, oooErrorMessage(code));
          return;
        }
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/ooo', {
            primaryUserId: primary,
            backupUserId: backup,
            from: from,
            until: until,
            startsOn: from,
            endsOn: until,
          });
          await loadTeam(mount);
          focusCapacityIfHash();
        } catch (e) {
          showAlert(oooForm, e.message || String(e));
        }
        });
      });
    }

    const bulkForm = capacityHost.querySelector('[data-crm-bulk-form]');
    if (bulkForm) {
      bulkForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitGuard(bulkForm, async function () {
        const fd = new FormData(bulkForm);
        const entityTypes = Array.from(bulkForm.querySelectorAll('input[name="entityTypes"]:checked'))
          .map(function (el) { return el.value; });
        const fromUser = String(fd.get('fromUser') || '').trim();
        const toUser = String(fd.get('toUser') || '').trim();
        const confirmed = !!bulkForm.querySelector('#crm-bulk-confirm:checked');
        const bulkValidate = capacityUi.validateBulkReassignInput;
        const bulkCode = typeof bulkValidate === 'function'
          ? bulkValidate({ fromUser: fromUser, toUser: toUser, confirmed: confirmed, entityTypes: entityTypes })
          : null;
        if (bulkCode === 'same_person') {
          showAlert(bulkForm, t('Pick a different colleague to receive the work.'));
          return;
        }
        if (bulkCode === 'not_confirmed') {
          showAlert(bulkForm, t('Confirm the reassignment before continuing.'));
          return;
        }
        if (bulkCode === 'nothing_selected') {
          showAlert(bulkForm, t('Select 1–200 items to reassign.'));
          return;
        }
        if (bulkCode) {
          showAlert(bulkForm, t('Select a colleague from the directory first.'));
          return;
        }
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/bulk-reassign', {
            fromUser: fromUser,
            toUser: toUser,
            confirmFromUserName: fromUser,
            fromUserId: fromUser,
            toUserId: toUser,
            confirmName: fromUser,
            entityTypes: entityTypes,
          });
          await loadTeam(mount);
        } catch (e) {
          showAlert(bulkForm, e.message || String(e));
        }
        });
      });
    }

    const escForm = capacityHost.querySelector('[data-crm-escalate-form]');
    if (escForm) {
      escForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitGuard(escForm, async function () {
        const fd = new FormData(escForm);
        const subject = String(fd.get('subjectUserId') || '').trim();
        if (!subject) {
          showAlert(escForm, t('Select a colleague from the directory first.'));
          return;
        }
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/escalations', {
            subjectUserId: subject,
            reason: String(fd.get('reason') || '').trim(),
          });
          await loadTeam(mount);
        } catch (e) {
          showAlert(escForm, e.message || String(e));
        }
        });
      });
    }

    if (!mount.dataset.crmOneOnOneBound) {
      mount.dataset.crmOneOnOneBound = '1';
      mount.addEventListener('click', async function (ev) {
        const btn = ev.target.closest('[data-crm-one-on-one]');
        if (!btn) return;
        const report = btn.getAttribute('data-crm-one-on-one') || '';
        if (!report) return;
        try {
          const prep = await apiGet('/api/v1/workspaces/' + ws + '/one-on-one/' + encodeURIComponent(report));
          openOneOnOneDialog(mount, ws, report, prep);
        } catch (e) {
          showAlert(mount, e.message || String(e));
        }
      });
    }
  }

  function openOneOnOneDialog(mount, ws, report, prep) {
    const existing = mount.querySelector('[data-crm-one-on-one-panel]');
    if (existing) existing.remove();
    const panel = document.createElement('section');
    panel.className = 'crm-card';
    panel.setAttribute('data-crm-one-on-one-panel', '1');
    panel.setAttribute('aria-labelledby', 'crm-11-title');
    const chaseLines = (prep.chase || []).slice(0, 8).map(function (d) {
      const reason = chaseReasonLabel(d.primaryReason || '');
      return '<li>' + escapeHtml((d.title || '') + (reason ? ' · ' + reason : '')) + '</li>';
    }).join('') || '<li class="crm-muted">' + escapeHtml(t('No open follow-ups.')) + '</li>';
    const promiseLines = (prep.promisesDue || []).slice(0, 8).map(function (p) {
      return '<li>' + escapeHtml((p.title || '') + (p.dueOn ? ' · ' + p.dueOn : '')) + '</li>';
    }).join('') || '<li class="crm-muted">' + escapeHtml(t('No overdue promises.')) + '</li>';
    const prior = (prep.notes && prep.notes.notes) ? prep.notes.notes : '';
    panel.innerHTML =
      '<header class="crm-card__header"><div class="crm-card__header-text">' +
      '<h3 id="crm-11-title" class="crm-card__title">' +
      escapeHtml(t('Coach') + ' — ' + report) + '</h3>' +
      '<p class="crm-card__lead">' + escapeHtml(t('Open deals and promises for this person — then save what you discussed.')) +
      '</p></div></header>' +
      '<div class="crm-card__body">' +
      '<div class="crm-11-prep__block">' +
      '<h4 class="crm-pulse-glance__heading" id="crm-11-chase">' + escapeHtml(t('Open deals')) + '</h4>' +
      '<ul class="crm-list" aria-labelledby="crm-11-chase">' + chaseLines + '</ul></div>' +
      '<div class="crm-11-prep__block">' +
      '<h4 class="crm-pulse-glance__heading" id="crm-11-promises">' + escapeHtml(t('Promises')) + '</h4>' +
      '<ul class="crm-list" aria-labelledby="crm-11-promises">' + promiseLines + '</ul></div>' +
      '<form class="crm-form crm-form--stack" data-crm-11-form aria-labelledby="crm-11-notes-title">' +
      '<h4 class="crm-pulse-glance__heading" id="crm-11-notes-title">' + escapeHtml(t('Meeting notes')) + '</h4>' +
      '<div class="form-group">' +
      '<label class="form-label" for="crm-11-notes">' + escapeHtml(t('What did you discuss?')) + '</label>' +
      '<textarea id="crm-11-notes" name="notes" class="crm-input form-input" rows="4" maxlength="8000" ' +
      'aria-describedby="crm-11-notes-help">' + escapeHtml(prior) + '</textarea>' +
      '<p class="form-help" id="crm-11-notes-help">' +
      escapeHtml(t('Short notes are enough — next week’s you will thank you.')) + '</p></div>' +
      '<div class="form-group">' +
      '<label class="form-label" for="crm-11-date">' + escapeHtml(t('Meeting date')) + '</label>' +
      '<input id="crm-11-date" name="meetingOn" type="date" class="crm-input form-input datepicker-input" autocomplete="off" value="' +
      escapeHtml((prep.notes && prep.notes.meetingOn) || localYmd()) + '" /></div>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save notes')) + '</button>' +
      '<button type="button" class="crm-btn crm-btn--ghost" data-crm-11-close="1">' + escapeHtml(t('Close')) + '</button>' +
      '</div></form></div>';
    mount.appendChild(panel);
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const notes = panel.querySelector('#crm-11-notes');
    if (notes) notes.focus();
    panel.querySelector('[data-crm-11-close]').addEventListener('click', function () {
      panel.remove();
    });
    panel.querySelector('[data-crm-11-form]').addEventListener('submit', async function (ev) {
      ev.preventDefault();
      const fd = new FormData(ev.target);
      try {
        await apiPost('/api/v1/workspaces/' + ws + '/one-on-one/' + encodeURIComponent(report), {
          notes: String(fd.get('notes') || ''),
          meetingOn: String(fd.get('meetingOn') || ''),
        });
        panel.remove();
      } catch (e) {
        showAlert(panel, e.message || String(e));
      }
    });
  }

  function strategyHealthBadge(health) {
    switch (String(health || '')) {
      case 'at_risk': return 'danger';
      case 'stalled': return 'warning';
      case 'on_track': return 'success';
      default: return 'neutral';
    }
  }

  function strategyPriorityBadge(priority) {
    return String(priority || '') === 'p1' ? 'warning' : 'neutral';
  }

  function strategyNeedsAttention(p) {
    const health = String(p.health || '');
    return health === 'at_risk' || health === 'stalled';
  }

  function strategyAssignDue(p) {
    const next = String(p.nextAssignOn || '').trim();
    if (!next) return false;
    const today = localYmd();
    return next <= today;
  }

  function strategyMatchesFilter(p, filter) {
    const key = String(filter || 'all');
    if (key === 'all') return true;
    if (key === 'needs_attention') return strategyNeedsAttention(p);
    if (key === 'on_track') return String(p.health || '') === 'on_track';
    if (key === 'high') return String(p.priority || '') === 'p1';
    if (key === 'paused') return String(p.status || '') === 'paused';
    return true;
  }

  function truncateGoal(goal, maxLen) {
    const text = String(goal || '').trim().replace(/\s+/g, ' ');
    if (!text) return '';
    const limit = maxLen > 0 ? maxLen : 140;
    if (text.length <= limit) return text;
    return text.slice(0, Math.max(0, limit - 1)).trimEnd() + '…';
  }

  function strategyPlanFormHtml(p) {
    const companyId = String(p.companyId || '');
    const goalId = 'crm-strategy-goal-' + companyId;
    const priorityId = 'crm-strategy-priority-' + companyId;
    const reviewId = 'crm-strategy-review-' + companyId;
    const statusId = 'crm-strategy-status-' + companyId;
    return '<form class="crm-form crm-form--stack crm-strategy-edit" data-plan-form="' +
      escapeHtml(companyId) + '" data-plan-updated-at="' + escapeHtml(p.updatedAt || '') +
      '" aria-label="' + escapeHtml(t('Edit account plan')) + '">' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + escapeHtml(goalId) + '">' + escapeHtml(t('Goal')) + '</label>' +
      '<textarea id="' + escapeHtml(goalId) + '" name="goal" class="crm-input form-input crm-plan-goal" rows="3" maxlength="2000">' +
      escapeHtml(p.goal || '') + '</textarea></div>' +
      '<details class="crm-plan-more">' +
      '<summary class="crm-plan-more__summary">' + escapeHtml(t('Priority, owner & review')) + '</summary>' +
      '<div class="crm-plan-more__body">' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + escapeHtml(priorityId) + '">' + escapeHtml(t('Priority')) + '</label>' +
      '<select id="' + escapeHtml(priorityId) + '" name="priority" class="crm-input form-select">' +
      '<option value="p1"' + (p.priority === 'p1' ? ' selected' : '') + '>' + escapeHtml(t('High')) + '</option>' +
      '<option value="p2"' + (p.priority === 'p2' || !p.priority ? ' selected' : '') + '>' + escapeHtml(t('Normal')) + '</option>' +
      '<option value="p3"' + (p.priority === 'p3' ? ' selected' : '') + '>' + escapeHtml(t('Low')) + '</option>' +
      '</select></div>' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + escapeHtml(reviewId) + '">' + escapeHtml(t('Next review')) + '</label>' +
      '<input id="' + escapeHtml(reviewId) + '" type="date" name="nextAssignOn" class="crm-input form-input datepicker-input" autocomplete="off" value="' +
      escapeHtml(p.nextAssignOn || '') + '" /></div>' +
      '<div class="form-group">' +
      '<label class="form-label" for="' + escapeHtml(statusId) + '">' + escapeHtml(t('Status')) + '</label>' +
      '<select id="' + escapeHtml(statusId) + '" name="status" class="crm-input form-select">' +
      '<option value="active"' + ((p.status || 'active') === 'active' ? ' selected' : '') + '>' + escapeHtml(t('Active')) + '</option>' +
      '<option value="paused"' + (p.status === 'paused' ? ' selected' : '') + '>' + escapeHtml(t('Paused')) + '</option>' +
      '<option value="done"' + (p.status === 'done' ? ' selected' : '') + '>' + escapeHtml(t('Done')) + '</option>' +
      '</select></div>' +
      '</div></details>' +
      '<div class="crm-form-actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Save')) + '</button>' +
      '<button type="button" class="crm-btn crm-btn--ghost" data-cancel-plan="' +
      escapeHtml(companyId) + '">' + escapeHtml(t('Cancel')) + '</button>' +
      '</div></form>';
  }

  function renderStrategyBoard(mount, ws, items, filter) {
    const root = document.getElementById('crm-strategy-root') || mount;
    const activeFilter = String(filter || root.getAttribute('data-crm-strategy-filter') || 'all');
    root.setAttribute('data-crm-strategy-filter', activeFilter);

    const total = items.length;
    const needsN = items.filter(strategyNeedsAttention).length;
    const dueN = items.filter(strategyAssignDue).length;
    const filtered = items.filter(function (p) { return strategyMatchesFilter(p, activeFilter); });

    function filterBtn(key, label, count) {
      const active = activeFilter === key;
      return '<button type="button" class="crm-quick-filter' + (active ? ' is-active' : '') + '" ' +
        'data-crm-strategy-filter-btn="' + escapeHtml(key) + '" ' +
        'aria-pressed="' + (active ? 'true' : 'false') + '">' +
        escapeHtml(label) +
        (count != null
          ? (' <span class="crm-quick-filter__count">' + escapeHtml(String(count)) + '</span>')
          : '') +
        '</button>';
    }

    let html = '';
    html += '<div class="crm-today-role__stats" role="list" aria-label="' + escapeHtml(t('Plan snapshot')) + '">' +
      pulseStat(total, t('Plans')) +
      pulseStat(needsN, t('Needs attention'), '', needsN > 0 ? 'alert' : '') +
      pulseStat(dueN, t('Assign due'), '', dueN > 0 ? 'alert' : '') +
      '</div>';
    html += '<nav class="crm-quick-filters" aria-label="' + escapeHtml(t('Filter plans')) + '">' +
      filterBtn('all', t('All'), total) +
      filterBtn('needs_attention', t('Needs attention'), needsN) +
      filterBtn('on_track', t('On track'), items.filter(function (p) { return String(p.health || '') === 'on_track'; }).length) +
      filterBtn('high', t('High'), items.filter(function (p) { return String(p.priority || '') === 'p1'; }).length) +
      '</nav>';

    if (!total) {
      html +=
        '<div class="crm-empty-state" role="status">' +
        '<h3 class="crm-empty-state__title">' + escapeHtml(t('No account plans yet')) + '</h3>' +
        '<p class="crm-empty-state__text">' + escapeHtml(t('Open a company and add a goal — it will show up here.')) + '</p>' +
        '<div class="crm-empty-state__actions">' +
        '<a class="crm-btn crm-btn--primary crm-btn--lg" href="' +
        escapeHtml(OC.generateUrl('/apps/customercheck/companies') + '?workspaceId=' + ws) + '">' +
        escapeHtml(t('Open companies')) + '</a></div></div>';
      mount.innerHTML = html;
      return;
    }

    if (!filtered.length) {
      html +=
        '<div class="crm-empty-state" role="status">' +
        '<h3 class="crm-empty-state__title">' + escapeHtml(t('No plans match this filter')) + '</h3>' +
        '<p class="crm-empty-state__text">' + escapeHtml(t('Try All, or open a company to update a plan.')) + '</p>' +
        '</div>';
      mount.innerHTML = html;
      return;
    }

    html += '<div class="table-container crm-table--responsive" role="region" aria-label="' +
      escapeHtml(t('Account plans')) + '">';
    html += '<table class="table table--hover crm-table crm-table--responsive crm-table--strategy">';
    html += '<thead><tr>' +
      '<th scope="col">' + escapeHtml(t('Company')) + '</th>' +
      '<th scope="col">' + escapeHtml(t('Goal')) + '</th>' +
      '<th scope="col">' + escapeHtml(t('Health')) + '</th>' +
      '<th scope="col">' + escapeHtml(t('Priority')) + '</th>' +
      '<th scope="col">' + escapeHtml(t('Next review')) + '</th>' +
      '<th scope="col">' + escapeHtml(t('Open moves')) + '</th>' +
      '<th scope="col" class="crm-table-actions-col">' + escapeHtml(t('Action')) + '</th>' +
      '</tr></thead><tbody>';

    filtered.forEach(function (p) {
      const companyId = String(p.companyId || '');
      const companyHref = OC.generateUrl('/apps/customercheck/companies/' + companyId) +
        '?workspaceId=' + ws + '#crm-account-plan';
      const companyLabel = p.companyName ? String(p.companyName) : t('Unknown company');
      const healthLabel = p.healthLabel || p.health || t('Unknown');
      const priorityLabel = p.priorityLabel || (
        p.priority === 'p1' ? t('High') : (p.priority === 'p3' ? t('Low') : t('Normal'))
      );
      const statusLabel = p.statusLabel || '';
      const goalText = truncateGoal(p.goal, 140);
      const goalCell = goalText
        ? escapeHtml(goalText)
        : ('<span class="crm-muted">' + escapeHtml(t('No goal yet')) + '</span>');
      const reviewCell = p.nextAssignOn
        ? ('<time datetime="' + escapeHtml(String(p.nextAssignOn)) + '">' +
          escapeHtml(formatDisplayDate(p.nextAssignOn)) + '</time>')
        : ('<span class="crm-muted" aria-hidden="true">—</span><span class="visually-hidden">' +
          escapeHtml(t('Not scheduled')) + '</span>');
      const openMoves = p.openMoves != null
        ? Number(p.openMoves)
        : (Array.isArray(p.mapSteps)
          ? p.mapSteps.filter(function (s) { return String(s.status || '') === 'open'; }).length
          : 0);
      const editBtn = shellCan('write')
        ? ('<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-edit-plan="' +
          escapeHtml(companyId) + '">' + escapeHtml(t('Edit')) + '</button>')
        : '';
      html += '<tr class="crm-strategy-row" data-crm-strategy-company="' + escapeHtml(companyId) + '">' +
        '<td data-label="' + escapeHtml(t('Company')) + '">' +
        '<a class="crm-strategy-company" href="' + escapeHtml(companyHref) + '">' +
        escapeHtml(companyLabel) + '</a>' +
        (statusLabel && String(p.status || '') !== 'active'
          ? (' <span class="crm-badge crm-badge--neutral">' + escapeHtml(statusLabel) + '</span>')
          : '') +
        '</td>' +
        '<td data-label="' + escapeHtml(t('Goal')) + '">' + goalCell + '</td>' +
        '<td data-label="' + escapeHtml(t('Health')) + '">' +
        '<span class="crm-badge crm-badge--' + strategyHealthBadge(p.health) + '">' +
        escapeHtml(healthLabel) + '</span></td>' +
        '<td data-label="' + escapeHtml(t('Priority')) + '">' +
        '<span class="crm-badge crm-badge--' + strategyPriorityBadge(p.priority) + '">' +
        escapeHtml(priorityLabel) + '</span></td>' +
        '<td data-label="' + escapeHtml(t('Next review')) + '">' + reviewCell + '</td>' +
        '<td data-label="' + escapeHtml(t('Open moves')) + '">' + escapeHtml(String(openMoves)) + '</td>' +
        '<td data-label="' + escapeHtml(t('Action')) + '" class="actions-cell crm-table-actions-col">' +
        '<div class="crm-table-actions">' +
        '<a class="crm-btn crm-btn--sm crm-btn--primary" href="' + escapeHtml(companyHref) + '">' +
        escapeHtml(t('Open plan')) + '</a> ' + editBtn +
        '</div></td></tr>';
      html += '<tr class="crm-strategy-edit-row" data-crm-strategy-edit="' + escapeHtml(companyId) + '" hidden>' +
        '<td colspan="7">' + strategyPlanFormHtml(p) + '</td></tr>';
    });

    html += '</tbody></table></div>';
    mount.innerHTML = html;
  }

  async function loadStrategy(mount, opts) {
    const gen = ++strategyLoadGeneration;
    const root = document.getElementById('crm-strategy-root') || mount;
    const filter = root.getAttribute('data-crm-strategy-filter') || 'all';
    const fromCache = !!(opts && opts.fromCache && Array.isArray(mount._crmStrategyItems));

    let ws;
    let items;
    if (fromCache) {
      ws = mount._crmStrategyWs;
      items = mount._crmStrategyItems;
    } else {
      ws = await resolveWorkspaceId();
      if (gen !== strategyLoadGeneration) return;
      if (!ws) {
        clearMountBusy(mount);
        mount.innerHTML = '<div class="crm-callout crm-callout--danger" role="alert"><p>' +
          escapeHtml(t('Select a workspace first.')) + '</p></div>';
        return;
      }
      const data = await apiGet('/api/v1/workspaces/' + ws + '/strategy');
      if (gen !== strategyLoadGeneration) return;
      items = data.items || [];
      mount._crmStrategyItems = items;
      mount._crmStrategyWs = ws;
    }

    if (gen !== strategyLoadGeneration) return;
    clearMountBusy(mount);
    renderStrategyBoard(mount, ws, items, filter);

    if (!root.dataset.crmStrategyBound) {
      root.dataset.crmStrategyBound = '1';
      root.addEventListener('click', function (ev) {
        const filterBtn = ev.target.closest('[data-crm-strategy-filter-btn]');
        if (filterBtn && root.contains(filterBtn)) {
          const next = filterBtn.getAttribute('data-crm-strategy-filter-btn') || 'all';
          root.setAttribute('data-crm-strategy-filter', next);
          loadStrategy(mount, { fromCache: true });
          return;
        }
        const editBtn = ev.target.closest('[data-edit-plan]');
        if (editBtn && root.contains(editBtn)) {
          const id = editBtn.getAttribute('data-edit-plan');
          const editRow = mount.querySelector('[data-crm-strategy-edit="' + CSS.escape(id) + '"]');
          const form = editRow ? editRow.querySelector('[data-plan-form]') : null;
          if (form) {
            if (!form.dataset.crmPlanSnapshot) {
              form.dataset.crmPlanSnapshot = form.innerHTML;
            } else {
              form.innerHTML = form.dataset.crmPlanSnapshot;
            }
            if (editRow) editRow.hidden = false;
            editBtn.hidden = true;
            const first = form.querySelector('textarea, input, select');
            if (first) first.focus();
          }
          return;
        }
        const cancelBtn = ev.target.closest('[data-cancel-plan]');
        if (cancelBtn && root.contains(cancelBtn)) {
          const id = cancelBtn.getAttribute('data-cancel-plan');
          const editRow = mount.querySelector('[data-crm-strategy-edit="' + CSS.escape(id) + '"]');
          const form = editRow ? editRow.querySelector('[data-plan-form]') : null;
          const edit = mount.querySelector('[data-edit-plan="' + CSS.escape(id) + '"]');
          if (form && form.dataset.crmPlanSnapshot) {
            form.innerHTML = form.dataset.crmPlanSnapshot;
          }
          if (editRow) editRow.hidden = true;
          if (edit) edit.hidden = false;
        }
      });
      root.addEventListener('submit', async function (ev) {
        const form = ev.target.closest('[data-plan-form]');
        if (!form || !root.contains(form)) return;
        ev.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') return;
        const companyId = form.getAttribute('data-plan-form');
        const fd = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        form.setAttribute('aria-busy', 'true');
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = t('Saving…');
        }
        try {
          await apiPut('/api/v1/companies/' + companyId + '/account-plan', {
            goal: String(fd.get('goal') || '').trim(),
            priority: String(fd.get('priority') || 'p2'),
            nextAssignOn: String(fd.get('nextAssignOn') || ''),
            status: String(fd.get('status') || 'active'),
            updatedAt: form.getAttribute('data-plan-updated-at') || '',
          });
          await loadStrategy(mount);
        } catch (e) {
          form.removeAttribute('aria-busy');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = t('Save');
          }
          showAlert(form, e.message || String(e));
        }
      });
    }
  }

  /**
   * Load + show a mail preview (never toggles closed). Preview is POST-only.
   * @returns {Promise<boolean>}
   */
  async function openMailPreview(wrap, ws, mount, opts) {
    if (!wrap || !(ws > 0)) return false;
    const btn = wrap.querySelector('[data-crm-mail-preview-toggle]');
    const pre = wrap.querySelector('.crm-mail-preview__body');
    const mailId = parseInt(wrap.getAttribute('data-crm-mail-preview') || '0', 10);
    if (!mailId || !pre) return false;
    if (btn && btn.getAttribute('aria-busy') === 'true') return false;
    const expectedGen = (opts && Object.prototype.hasOwnProperty.call(opts, 'previewGeneration'))
      ? opts.previewGeneration
      : null;
    function isStalePreview() {
      if (!wrap.isConnected) return true;
      return expectedGen != null && expectedGen !== mailPreviewGeneration;
    }
    if (!pre.hidden && pre.getAttribute('data-loaded') === '1') {
      if (btn) {
        btn.hidden = false;
        btn.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'true');
        btn.textContent = t('Hide message');
      }
      return true;
    }
    if (pre.getAttribute('data-loaded') === '1') {
      pre.hidden = false;
      pre.classList.remove('crm-mail-preview__body--pending');
      if (btn) {
        btn.hidden = false;
        btn.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'true');
        btn.textContent = t('Hide message');
      }
      if (!(opts && opts.quietFocus)) {
        try { pre.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
      }
      return true;
    }
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }
    try {
      const postOpts = {};
      if (opts && opts.signal) {
        postOpts.signal = opts.signal;
      }
      const res = await apiPost('/api/v1/workspaces/' + ws + '/mail/' + mailId + '/preview', {}, postOpts);
      if (isStalePreview()) {
        return false;
      }
      const bodyText = (res && res.preview) || '';
      // Never use innerHTML — mail bodies are untrusted.
      pre.textContent = bodyText !== ''
        ? bodyText
        : t('No message text could be loaded.');
      if (res && res.truncated) {
        pre.textContent += '\n\n' + t('(Preview truncated)');
      }
      pre.setAttribute('data-loaded', '1');
      pre.hidden = false;
      pre.classList.remove('crm-mail-preview__body--pending');
      if (btn) {
        btn.hidden = false;
        btn.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'true');
        btn.textContent = t('Hide message');
      }
      if (!(opts && opts.quietFocus)) {
        try { pre.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
      }
      return true;
    } catch (e) {
      if (isAbortError(e) || isStalePreview()) {
        return false;
      }
      const errMsg = e.message || String(e);
      pre.textContent = errMsg;
      pre.hidden = false;
      pre.classList.remove('crm-mail-preview__body--pending');
      pre.removeAttribute('data-loaded');
      pre.setAttribute('role', 'alert');
      if (btn) {
        btn.hidden = false;
        btn.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'false');
        btn.textContent = t('Show message');
      }
      showAlert(mount, errMsg);
      return false;
    } finally {
      if (btn && wrap.isConnected) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
      }
    }
  }

  /** Open every mail preview in root (Assign: open row = read message, no second click). */
  function ensureMailPreviewsOpen(root, ws, mount, opts) {
    if (!root || !(ws > 0)) return;
    root.querySelectorAll('[data-crm-mail-preview]').forEach(function (wrap) {
      void openMailPreview(wrap, ws, mount, opts || {});
    });
  }

  /**
   * Shared Show/Hide message + Answer + Copy wiring (Assign + Mail triage).
   * Preview is POST-only; Answer hydrates a thin quote via preview once when needed.
   */
  function bindMailPreviewActions(root, ws, mount) {
    if (!root || !(ws > 0)) return;
    root.querySelectorAll('[data-crm-mail-preview-toggle]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        const wrap = btn.closest('[data-crm-mail-preview]');
        if (!wrap) return;
        const pre = wrap.querySelector('.crm-mail-preview__body');
        if (!pre) return;
        if (btn.getAttribute('aria-busy') === 'true') return;
        if (!pre.hidden && pre.getAttribute('data-loaded') === '1') {
          pre.hidden = true;
          btn.setAttribute('aria-expanded', 'false');
          btn.textContent = t('Show message');
          return;
        }
        await openMailPreview(wrap, ws, mount, {});
      });
    });
    root.querySelectorAll('[data-crm-mail-copy]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        const wrap = btn.closest('[data-crm-mail-preview]');
        if (!wrap) return;
        const pre = wrap.querySelector('.crm-mail-preview__body');
        if (!pre) return;
        if (pre.getAttribute('data-loaded') !== '1' || pre.hidden) {
          const ok = await openMailPreview(wrap, ws, mount, { quietFocus: true });
          if (!ok) return;
        }
        const text = String(pre.textContent || '').trim();
        if (!text) {
          showAlert(mount, t('No message text could be loaded.'));
          return;
        }
        const live = document.getElementById('crm-live-region');
        try {
          await navigator.clipboard.writeText(text);
          if (live) live.textContent = t('Copied to clipboard.');
          btn.textContent = t('Copied');
          setTimeout(function () {
            if (btn.isConnected) btn.textContent = t('Copy message');
          }, 1600);
        } catch (e) {
          try {
            const range = document.createRange();
            range.selectNodeContents(pre);
            const sel = window.getSelection();
            if (sel) {
              sel.removeAllRanges();
              sel.addRange(range);
            }
            pre.focus({ preventScroll: true });
          } catch (e2) { /* ignore */ }
          if (live) live.textContent = t('Select the message text and copy it (Ctrl+C or Cmd+C).');
          showAlert(mount, t('Clipboard access was denied. Select the message text and copy it.'));
        }
      });
    });
    root.querySelectorAll('[data-crm-mail-reply]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        const wrap = btn.closest('[data-crm-mail-preview]');
        if (!wrap) return;
        const mailId = parseInt(wrap.getAttribute('data-crm-mail-preview') || '0', 10);
        if (!mailId || btn.getAttribute('aria-busy') === 'true') return;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        try {
          let pack = await apiGet('/api/v1/workspaces/' + ws + '/mail/' + mailId + '/reply');
          // Hydrate cached preview once when Answer is pressed first — richer quote in mailto.
          const thin = !pack || !pack.available || String(pack.bodyText || '').length < 40;
          if (thin) {
            try {
              await apiPost('/api/v1/workspaces/' + ws + '/mail/' + mailId + '/preview', {});
              pack = await apiGet('/api/v1/workspaces/' + ws + '/mail/' + mailId + '/reply');
            } catch (hydrateErr) {
              // Keep first reply pack if hydrate fails (rate limit / IMAP).
            }
          }
          if (!pack || !pack.available) {
            showAlert(mount, t('This message has no usable reply address.'));
            return;
          }
          const SharedMail = window.CustomerCheckSharedMail;
          if (SharedMail && typeof SharedMail.offerPreparedMail === 'function') {
            const companyHint = parseInt(String(
              (pack && pack.companyId)
              || (wrap.getAttribute('data-company-id') || '')
              || (wrap.closest('[data-company-id]') && wrap.closest('[data-company-id]').getAttribute('data-company-id'))
              || 0
            ), 10) || 0;
            await SharedMail.offerPreparedMail(pack, {
              companyId: companyHint,
              title: t('Answer'),
              replyMode: true,
              mailboxId: parseInt(String(pack.mailboxId || 0), 10) || 0,
            });
            return;
          }
          const C = window.CustomerCheckComponents || window.CrmComponents;
          const rawUrl = pack.mailAppUrl || pack.mailtoUrl || '';
          const url = (C && typeof C.safeExternalHref === 'function')
            ? C.safeExternalHref(rawUrl, '#')
            : rawUrl;
          if (!url || url === '#') {
            showAlert(mount, t('Could not open a mail program for this address.'));
            return;
          }
          if (/^mailto:/i.test(url)) {
            window.location.href = url;
          } else {
            window.open(url, '_blank', 'noopener,noreferrer');
          }
        } catch (e) {
          showAlert(mount, e.message || String(e));
        } finally {
          btn.disabled = false;
          btn.removeAttribute('aria-busy');
        }
      });
    });
  }

  async function loadMail(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/mail/unmatched');
    clearMountBusy(mount);
    mount.innerHTML = '';
    if (data.disclosure) {
      const disclosure = document.createElement('section');
      disclosure.className = 'crm-card';
      disclosure.innerHTML = '<div class="crm-card__body"><p class="crm-muted" role="status">' +
        escapeHtml(data.disclosure) + '</p></div>';
      mount.appendChild(disclosure);
    }
    // ARGUS MF-01: Assign is write-only — viewers may read/preview, not triage.
    const canWrite = shellCan('write');
    const tableHost = document.createElement('section');
    tableHost.className = 'crm-card';
    tableHost.setAttribute('aria-labelledby', 'crm-mail-triage-title');
    tableHost.innerHTML =
      '<header class="crm-card__header"><h2 id="crm-mail-triage-title" class="crm-card__title">' +
      escapeHtml(t('Mail triage')) + '</h2></header>' +
      '<div class="crm-card__body" data-crm-mail-triage-body></div>';
    mount.appendChild(tableHost);
    const body = tableHost.querySelector('[data-crm-mail-triage-body]');
    const items = data.items || [];
    renderSuiteTable(
      body,
      items.map(function (m) {
        const mid = m.id;
        const searchId = 'crm-triage-search-' + mid;
        const suggestId = 'crm-triage-suggest-' + mid;
        const hiddenId = 'crm-triage-company-' + mid;
        const previewHtml =
            '<div class="crm-mail-preview" data-crm-mail-preview="' + escapeHtml(String(mid)) + '">' +
            '<div class="crm-mail-preview__actions crm-inline-actions">' +
            '<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" data-crm-mail-preview-toggle ' +
            'aria-expanded="false" aria-controls="crm-triage-preview-body-' + escapeHtml(String(mid)) + '" ' +
            'title="' + escapeHtml(t('Plain text only — attachments stay in the mailbox.')) + '">' +
            escapeHtml(t('Show message')) + '</button>' +
            '<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" data-crm-mail-copy ' +
            'aria-label="' + escapeHtml(t('Copy message')) + '" ' +
            'title="' + escapeHtml(t('Copy the readable message text to the clipboard.')) + '">' +
            escapeHtml(t('Copy message')) + '</button>' +
            '<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-mail-reply ' +
            'aria-label="' + escapeHtml(t('Answer: %s', [String(m.subject || t('Shared mail'))])) + '" ' +
            'title="' + escapeHtml(t('Reply from your connected personal or team email, or open your mail program.')) + '">' +
            escapeHtml(t('Answer')) + '</button>' +
            '</div>' +
            '<pre class="crm-mail-preview__body" id="crm-triage-preview-body-' + escapeHtml(String(mid)) + '" ' +
            'role="region" aria-label="' + escapeHtml(t('Message text')) + '" tabindex="0" hidden></pre>' +
            '</div>';
        const assignHtml = canWrite
          ? (
            '<form class="crm-form crm-form--inline crm-form--row crm-triage-assign" data-triage-id="' + escapeHtml(String(mid)) + '">' +
            '<label class="form-label" for="' + escapeHtml(searchId) + '">' + escapeHtml(t('Company')) + '</label>' +
            '<div class="user-picker crm-triage-company-picker">' +
            '<div class="user-picker__control">' +
            '<input type="search" id="' + escapeHtml(searchId) + '" class="form-input crm-input user-picker__search" ' +
            'role="combobox" aria-autocomplete="list" aria-controls="' + escapeHtml(suggestId) + '" aria-expanded="false" ' +
            'autocomplete="off" placeholder="' + escapeHtml(t('Search by company name…')) + '" />' +
            '</div>' +
            '<ul id="' + escapeHtml(suggestId) + '" class="user-picker__list" role="listbox" hidden></ul>' +
            '<input type="hidden" id="' + escapeHtml(hiddenId) + '" name="companyId" value="" required />' +
            '</div>' +
            '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Assign')) + '</button>' +
            '</form>'
          )
          : '';
        return {
          title: m.subject || m.from || t('Shared mail'),
          meta: t('Shared') + (m.sentAt ? ' · ' + m.sentAt : '') + (m.from && m.subject ? ' · ' + m.from : ''),
          actionHtml: previewHtml + assignHtml,
        };
      }),
      t('No unmatched shared mail.'),
      t('Mail triage'),
    );
    if (canWrite) {
    body.querySelectorAll('[data-triage-id]').forEach(function (form) {
      const mid = form.getAttribute('data-triage-id');
      const search = form.querySelector('.user-picker__search');
      const suggest = form.querySelector('.user-picker__list');
      const hidden = form.querySelector('[name="companyId"]');
      if (search && suggest && hidden) {
        const nav = (window.CustomerCheckUserPickerNav && window.CustomerCheckUserPickerNav.bindListboxKeyboard)
          ? window.CustomerCheckUserPickerNav.bindListboxKeyboard(search, suggest)
          : { reset: function () {} };
        let timer = null;
        let searchGeneration = 0;
        search.addEventListener('input', function () {
          if (hidden.value) {
            hidden.value = '';
          }
          clearTimeout(timer);
          const q = search.value.trim();
          if (q.length < 2) {
            searchGeneration += 1;
            suggest.hidden = true;
            suggest.innerHTML = '';
            search.setAttribute('aria-expanded', 'false');
            nav.reset();
            return;
          }
          searchGeneration += 1;
          const myGeneration = searchGeneration;
          timer = setTimeout(async function () {
            try {
              const data = await apiGet('/api/v1/companies?q=' + encodeURIComponent(q) + '&limit=20&workspaceId=' + encodeURIComponent(String(ws)));
              if (myGeneration !== searchGeneration) {
                return;
              }
              const list = Array.isArray(data) ? data : [];
              suggest.innerHTML = '';
              list.forEach(function (c) {
                const opt = document.createElement('li');
                opt.className = 'user-picker__item';
                opt.setAttribute('role', 'option');
                opt.setAttribute('aria-selected', 'false');
                const nameEl = document.createElement('span');
                nameEl.className = 'user-picker__name';
                nameEl.textContent = c.name || ('#' + String(c.id || ''));
                opt.appendChild(nameEl);
                opt.addEventListener('click', function () {
                  hidden.value = String(c.id || '');
                  search.value = c.name || ('#' + String(c.id || ''));
                  suggest.innerHTML = '';
                  suggest.hidden = true;
                  search.setAttribute('aria-expanded', 'false');
                  nav.reset();
                });
                suggest.appendChild(opt);
              });
              suggest.hidden = list.length === 0;
              search.setAttribute('aria-expanded', list.length === 0 ? 'false' : 'true');
              nav.reset();
            } catch (e) {
              suggest.hidden = true;
              search.setAttribute('aria-expanded', 'false');
              nav.reset();
            }
          }, 250);
        });
      }
      form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') {
          return;
        }
        const id = form.getAttribute('data-triage-id');
        const companyId = parseInt(hidden ? hidden.value : '0', 10);
        if (!companyId || companyId < 1) {
          showAlert(mount, t('Pick a company from the search results first.'));
          return;
        }
        const submitBtns = form.querySelectorAll('button[type="submit"]');
        form.setAttribute('aria-busy', 'true');
        submitBtns.forEach(function (b) { b.disabled = true; });
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/mail/' + id + '/triage', { companyId: companyId });
          await loadMail(mount);
        } catch (e) {
          showAlert(form, e.message || String(e));
        } finally {
          form.removeAttribute('aria-busy');
          submitBtns.forEach(function (b) { b.disabled = false; });
        }
      });
    });
    }
    bindMailPreviewActions(body, ws, mount);

    // §14.4 — failed shared sends (workspace-visible to mail.read)
    await renderFailedSendsSection(mount, ws, function () { return loadMail(mount); });
  }

  function normalizeReviewKindAttr(mount) {
    const raw = (mount && mount.getAttribute('data-crm-review-kind')) || '';
    const k = String(raw).trim();
    if (k === 'shared_mail' || k === 'personal_mail_shared_unmatched') {
      return 'mail';
    }
    return k;
  }

  async function loadReview(mount) {
    const ws = await resolveWorkspaceId();
    const kind = normalizeReviewKindAttr(mount);
    if (mount && kind && mount.getAttribute('data-crm-review-kind') !== kind) {
      mount.setAttribute('data-crm-review-kind', kind);
    }
    const includeArchived = !!(document.getElementById('crm-review-include-archived') || {}).checked
      || mount.getAttribute('data-crm-include-archived') === '1';
    const searchQ = (document.getElementById('crm-review-q') || {}).value
      || mount.getAttribute('data-crm-review-q')
      || '';
    let qs = [];
    if (kind) qs.push('kind=' + encodeURIComponent(kind));
    if (includeArchived) qs.push('includeArchived=1');
    if (String(searchQ).trim() !== '') qs.push('q=' + encodeURIComponent(String(searchQ).trim()));
    const qsKind = qs.length ? ('?' + qs.join('&')) : '';
    const loadGen = ++reviewLoadGeneration;
    const data = await apiGet('/api/v1/workspaces/' + ws + '/review/items' + qsKind);
    if (loadGen !== reviewLoadGeneration) {
      return;
    }
    clearMountBusy(mount);
    mount.innerHTML = '';
    const canWrite = data.canWrite !== false && shellCan('write');
    const canReopen = !!data.canReopen;
    const items = data.items || [];
    const openCount = data.openCount || 0;

    function sourceKindLabel(code) {
      const map = {
        shared_mail: t('Shared mail'),
        personal_mail_shared_unmatched: t('Personal shared'),
        mail: t('Mail'),
        contact_suggest: t('Contacts'),
        calendar_event: t('Calendar'),
        file: t('Files'),
        task: t('Tasks'),
        import_anomaly: t('Import'),
      };
      return map[code] || code || t('Assign');
    }

    function isMailSourceKind(code) {
      return code === 'shared_mail' || code === 'personal_mail_shared_unmatched';
    }


    function statusLabel(code) {
      const map = {
        open: t('Needs a decision'),
        snoozed: t('Snoozed'),
        assigned: t('Assigned'),
        no_relation: t('No relation'),
        follow_up: t('Follow-up'),
      };
      return map[code] || code || '';
    }

    function setDisclosureText(text) {
      const el = document.getElementById('crm-review-disclosure');
      if (el) {
        el.textContent = text;
        return;
      }
      const disclosure = document.createElement('p');
      disclosure.className = 'crm-callout';
      disclosure.id = 'crm-review-disclosure';
      disclosure.setAttribute('role', 'status');
      disclosure.textContent = text;
      mount.parentNode ? mount.parentNode.insertBefore(disclosure, mount) : mount.appendChild(disclosure);
    }

    if (kind && kind !== 'shared_mail' && kind !== 'mail') {
      setDisclosureText(t('These items come from %s. Everyone in this workspace with Assign access can see them.', [sourceKindLabel(kind)]));
    } else if (!kind) {
      const hasNonMail = items.some(function (it) {
        return it && it.sourceKind && !isMailSourceKind(it.sourceKind);
      });
      setDisclosureText(hasNonMail
        ? t('This inbox may include shared mail and platform sources (Contacts, Files, Calendar, Tasks, import). Everyone in this workspace with Assign access can see them.')
        : t('Write to the customer and CC the shared mailbox — or forward their mail to it. We file on From, a unique To/CC contact, or the original sender inside a forward — never onto every recipient.'));
    } else {
      setDisclosureText(t('Write to the customer and CC the shared mailbox — or forward their mail to it. We file on From, a unique To/CC contact, or the original sender inside a forward — never onto every recipient.'));
    }

    const head = document.createElement('p');
    head.className = 'crm-muted crm-review-count';
    head.setAttribute('role', 'status');
    const shown = items.length;
    if (openCount <= 0) {
      head.textContent = t('Nothing needs a decision right now.');
    } else if (openCount === 1) {
      head.textContent = t('1 item needs a decision');
    } else if (shown > 0 && shown < openCount) {
      head.textContent = t('Showing %1$s of %2$s items that need a decision — newest first. Delete or assign to see the next ones.', [
        String(shown),
        String(openCount),
      ]);
    } else {
      head.textContent = t('%s items need a decision', [String(openCount)]);
    }
    mount.appendChild(head);

    const kindChips = document.createElement('nav');
    kindChips.className = 'crm-quick-filters';
    kindChips.setAttribute('aria-label', t('Filter by source'));
    [
      { id: '', label: t('All') },
      { id: 'mail', label: t('Mail') },
      { id: 'contact_suggest', label: t('Contacts') },
      { id: 'file', label: t('Files') },
      { id: 'calendar_event', label: t('Calendar') },
      { id: 'task', label: t('Tasks') },
      { id: 'import_anomaly', label: t('Import') },
    ].forEach(function (chip) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'crm-btn crm-btn--sm' + (kind === chip.id ? ' crm-btn--primary' : ' crm-btn--secondary');
      btn.setAttribute('aria-pressed', kind === chip.id ? 'true' : 'false');
      btn.textContent = chip.label;
      btn.addEventListener('click', function () {
        mount.setAttribute('data-crm-review-kind', chip.id);
        try {
          const url = new URL(window.location.href);
          if (chip.id) url.searchParams.set('kind', chip.id);
          else url.searchParams.delete('kind');
          if (url.pathname.indexOf('/sales-inbox') !== -1) {
            url.searchParams.set('tab', 'unmatched');
          }
          window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        } catch (e) { /* ignore */ }
        void loadReview(mount);
      });
      kindChips.appendChild(btn);
    });
    mount.appendChild(kindChips);

    // AZ-style filter toolbar: search always visible; archived is a quiet secondary row.
    const filterBar = document.createElement('div');
    filterBar.className = 'crm-review-toolbar';
    filterBar.setAttribute('role', 'search');
    filterBar.setAttribute('aria-label', t('Search Assign'));
    filterBar.innerHTML =
      '<form class="crm-form crm-review-search">' +
      '<div class="crm-review-search__field">' +
      '<label class="form-label" for="crm-review-q">' + escapeHtml(t('Search Assign')) + '</label>' +
      '<input type="search" id="crm-review-q" class="form-input crm-input" autocomplete="off" ' +
      'placeholder="' + escapeHtml(t('From, subject, date, or snippet…')) + '" value="' + escapeHtml(String(searchQ || '')) + '" />' +
      '</div>' +
      '<div class="crm-review-search__actions">' +
      '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Search')) + '</button>' +
      '</div>' +
      '</form>' +
      '<details class="crm-advanced crm-review-toolbar__extra">' +
      '<summary class="crm-advanced__summary">' + escapeHtml(t('More search options')) + '</summary>' +
      '<label class="crm-switch" for="crm-review-include-archived">' +
      '<input type="checkbox" id="crm-review-include-archived" class="crm-input" ' + (includeArchived ? 'checked ' : '') + '/>' +
      '<span>' + escapeHtml(t('Include archived companies in suggestions')) + '</span></label>' +
      '</details>';
    mount.appendChild(filterBar);

    const showMailSync = !kind || kind === 'shared_mail' || kind === 'mail' || kind === 'personal_mail_shared_unmatched';
    var lastSyncMessage = mount.getAttribute('data-crm-last-sync-message') || '';
    if (showMailSync && data.mailSync && window.CustomerCheckMailSync) {
      const syncHost = document.createElement('div');
      syncHost.setAttribute('data-crm-mail-sync-bar', '');
      mount.appendChild(syncHost);
      window.CustomerCheckMailSync.mountBar(syncHost, {
        t: t,
        summary: data.mailSync,
        canSync: canWrite,
        workspaceId: ws,
        syncMessage: lastSyncMessage,
        apiPost: function (path, body) {
          return apiPost('/api/v1' + path, body);
        },
        announce: function (msg) {
          const live = document.getElementById('crm-live-region');
          if (live) live.textContent = msg;
        },
        onSynced: function (payload) {
          if (payload && payload.message) {
            mount.setAttribute('data-crm-last-sync-message', String(payload.message));
          }
          return loadReview(mount);
        },
      });
    }

    const searchBar = filterBar.querySelector('form.crm-review-search');
    if (searchBar) {
      searchBar.addEventListener('submit', function (ev) {
        ev.preventDefault();
        const qEl = searchBar.querySelector('#crm-review-q');
        const nextQ = qEl ? String(qEl.value || '').trim() : '';
        if (nextQ) mount.setAttribute('data-crm-review-q', nextQ);
        else mount.removeAttribute('data-crm-review-q');
        void loadReview(mount);
      });
      // Instant search while typing (debounced) — no extra click for granny.
      const qEl = searchBar.querySelector('#crm-review-q');
      let searchTimer = null;
      if (qEl) {
        qEl.addEventListener('input', function () {
          if (searchTimer) window.clearTimeout(searchTimer);
          searchTimer = window.setTimeout(function () {
            const nextQ = String(qEl.value || '').trim();
            if (nextQ) mount.setAttribute('data-crm-review-q', nextQ);
            else mount.removeAttribute('data-crm-review-q');
            void loadReview(mount);
          }, 320);
        });
      }
    }
    const archToggle = filterBar.querySelector('#crm-review-include-archived');
    if (archToggle) {
      archToggle.addEventListener('change', function () {
        mount.setAttribute('data-crm-include-archived', archToggle.checked ? '1' : '0');
        void loadReview(mount);
      });
    }

    const body = document.createElement('div');
    body.setAttribute('data-crm-review-body', '');
    mount.appendChild(body);

    let bulkBar = null;
    if (canWrite) {
      bulkBar = document.createElement('div');
      bulkBar.className = 'crm-review-bulk-bar';
      bulkBar.setAttribute('data-crm-review-bulk', '');
      bulkBar.setAttribute('role', 'region');
      bulkBar.setAttribute('aria-label', t('Selected messages'));
      bulkBar.hidden = true;
      bulkBar.innerHTML =
        '<p class="crm-muted crm-review-bulk-bar__hint" data-crm-review-bulk-count>' +
        escapeHtml(t('Select messages to delete or mark as spam')) + '</p>' +
        '<div class="crm-inline-actions crm-review-bulk-bar__actions">' +
        '<button type="button" class="crm-btn crm-btn--ghost" data-crm-review-bulk-clear disabled>' +
        escapeHtml(t('Clear selection')) + '</button>' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-bulk-spam disabled ' +
        'title="' + escapeHtml(t('Moves shared-mailbox messages into the server Spam/Junk folder.')) + '">' +
        escapeHtml(t('Mark as spam')) + '</button>' +
        '<button type="button" class="crm-btn crm-btn--danger" data-crm-review-bulk-remove disabled ' +
        'title="' + escapeHtml(t('Deletes shared-mailbox messages forever on the mail server.')) + '">' +
        escapeHtml(t('Delete selected')) + '</button>' +
        '</div>';
      mount.insertBefore(bulkBar, body);
    }

    function reasonText(code) {
      const map = {
        person_email_exact: t('Exact person email match'),
        domain_unique: t('Unique company domain match'),
        org_name_unique: t('Organisation name unique'),
        org_name_fuzzy: t('Organisation name close match'),
      };
      return map[code] || code || t('Suggested match');
    }

    const viewModels = items.map(function (item) {
        const top = (item.suggestions && item.suggestions[0]) || null;
        const companyLabel = top && top.companyName
          ? String(top.companyName)
          : (top ? ('#' + String(top.companyId)) : '');
        const prefillId = top ? String(top.companyId) : '';
        const prefillName = top && top.companyName ? String(top.companyName) : '';
        const searchId = 'crm-review-search-' + item.id;
        const suggestId = 'crm-review-suggest-' + item.id;
        const hiddenId = 'crm-review-company-' + item.id;
        let actions = '<span class="crm-muted">' + escapeHtml(t('View only')) + '</span>';
        const terminal = item.status === 'assigned' || item.status === 'no_relation';
        const overheadHint = escapeHtml(t('Logged without a deal — not counted as sales work.'));
        const ref = item.sourceRef || {};
        const anomalyField = String(ref.fieldKey || '');
        const anomalyRaw = String(ref.raw || item.snippet || '');
        const mailMsgId = isMailSourceKind(item.sourceKind)
          ? (parseInt(String(ref.mailMsgId || '0'), 10) || 0)
          : 0;
        const fromAddr = String(ref.from || (isMailSourceKind(item.sourceKind) ? (item.snippet || '') : '') || '');
        const badges = [sourceKindLabel(item.sourceKind), statusLabel(item.status)];
        if (item.overhead) {
          badges.push(t('Overhead'));
        }
        if (top && companyLabel) {
          badges.push(t('Suggested: %s', [companyLabel]));
        }
        const companyNameHint = (item.sourceKind === 'import_anomaly' && anomalyField === 'company_name' && anomalyRaw)
          ? ('<p class="crm-callout" role="status">' +
            escapeHtml(t('Ambiguous company name from import: “%s”. Pick the matching company or mark no relation.', [anomalyRaw])) +
            '</p>')
          : '';
        // Read zone first: message loads when the row opens (no second click).
        const mailPreviewBlock = mailMsgId > 0
          ? ('<div class="crm-review-zone crm-review-zone--read">' +
            '<p class="crm-review-zone__label" id="crm-review-read-label-' + escapeHtml(String(item.id)) + '">' +
            escapeHtml(t('Message')) + '</p>' +
            '<div class="crm-mail-preview" data-crm-mail-preview="' + escapeHtml(String(mailMsgId)) + '" ' +
            (prefillId ? ('data-company-id="' + escapeHtml(prefillId) + '" ') : '') +
            'data-crm-mail-auto-open="1" aria-labelledby="crm-review-read-label-' + escapeHtml(String(item.id)) + '">' +
            '<pre class="crm-mail-preview__body crm-mail-preview__body--pending" id="crm-mail-preview-body-' +
            escapeHtml('r' + String(item.id) + '-m' + String(mailMsgId)) + '" ' +
            'role="region" aria-label="' + escapeHtml(t('Message text')) + '" tabindex="0" hidden></pre>' +
            '<div class="crm-mail-preview__actions crm-inline-actions crm-review-mail-tools">' +
            '<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-mail-reply ' +
            'aria-label="' + escapeHtml(t('Answer: %s', [String(item.subject || t('Shared mail'))])) + '" ' +
            'title="' + escapeHtml(t('Reply from your connected personal or team email, or open your mail program.')) + '">' +
            escapeHtml(t('Answer')) + '</button>' +
            '<button type="button" class="crm-btn crm-btn--secondary" data-crm-mail-copy ' +
            'aria-label="' + escapeHtml(t('Copy message')) + '" ' +
            'title="' + escapeHtml(t('Copy the readable message text to the clipboard.')) + '">' +
            escapeHtml(t('Copy message')) + '</button>' +
            '<button type="button" class="crm-btn crm-btn--ghost" data-crm-mail-preview-toggle hidden ' +
            'id="crm-mail-preview-btn-' + escapeHtml(String(item.id)) + '" ' +
            'aria-expanded="false" aria-controls="crm-mail-preview-body-' +
            escapeHtml('r' + String(item.id) + '-m' + String(mailMsgId)) + '" ' +
            'title="' + escapeHtml(t('Plain text only — attachments stay in the mailbox.')) + '">' +
            escapeHtml(t('Hide message')) + '</button>' +
            '</div></div></div>')
          : '';
        if (terminal && item.overhead) {
          actions =
            mailPreviewBlock +
            '<p class="crm-callout" role="status">' + overheadHint + '</p>' +
            (canReopen
              ? '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-reopen="' +
                escapeHtml(String(item.id)) + '">' +
                escapeHtml(t('Reopen')) +
                '</button>'
              : '');
        } else if (terminal && canReopen) {
          actions =
            mailPreviewBlock +
            '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-reopen="' +
            escapeHtml(String(item.id)) + '">' +
            escapeHtml(t('Reopen')) +
            '</button>';
        } else if (canWrite && !terminal) {
          const searchValue = prefillName || (anomalyField === 'company_name' ? anomalyRaw : '');
          const hasSuggest = !!(top && prefillId);
          const quickAssign = hasSuggest
            ? ('<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-review-quick-assign ' +
              'data-company-id="' + escapeHtml(prefillId) + '">' +
              escapeHtml(t('Assign to %s', [companyLabel])) + '</button>')
            : '';
          const pickerBlock =
            '<div class="user-picker crm-review-company-picker">' +
            '<label class="form-label visually-hidden" for="' + escapeHtml(searchId) + '">' +
            escapeHtml(hasSuggest ? t('Or pick another company') : t('Company')) + '</label>' +
            '<input type="search" id="' + escapeHtml(searchId) + '" class="form-input crm-input user-picker__search" ' +
            'role="combobox" aria-autocomplete="list" aria-controls="' + escapeHtml(suggestId) + '" aria-expanded="false" ' +
            'autocomplete="off" placeholder="' + escapeHtml(t('Search by company name…')) + '" value="' + escapeHtml(searchValue) + '" />' +
            '<ul id="' + escapeHtml(suggestId) + '" class="user-picker__list" role="listbox" hidden></ul>' +
            '<input type="hidden" id="' + escapeHtml(hiddenId) + '" name="companyId" value="' + escapeHtml(prefillId) + '" />' +
            '</div>';
          // With suggestion: one-tap assign. Without: search + Assign as one clean row.
          const companySection = hasSuggest
            ? ('<p class="crm-callout crm-review-suggest-chip" role="status">' +
              escapeHtml(t('Suggested: %s', [companyLabel])) + ' · ' +
              escapeHtml(t('%s percent match', [String(top.score)])) +
              (top.reasonCode ? (' — ' + escapeHtml(reasonText(top.reasonCode))) : '') +
              '</p>' +
              quickAssign +
              '<details class="crm-advanced crm-review-pick-other">' +
              '<summary class="crm-advanced__summary">' + escapeHtml(t('Pick another company')) + '</summary>' +
              '<div class="crm-review-assign-row">' +
              pickerBlock +
              '<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg">' + escapeHtml(t('Assign')) + '</button>' +
              '</div></details>')
            : ('<div class="crm-review-pick-company">' +
              '<p class="crm-review-decide__heading">' + escapeHtml(t('Assign to a company')) + '</p>' +
              '<div class="crm-review-pick-company__body">' +
              '<div class="crm-review-assign-row">' +
              pickerBlock +
              '<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg">' + escapeHtml(t('Assign')) + '</button>' +
              '</div></div></div>');
          // Optional deal stays with Assign (not under snooze) but collapsed so one-tap Assign stays calm.
          const dealField = hasSuggest
            ? ('<details class="crm-advanced crm-review-deal-more">' +
              '<summary class="crm-advanced__summary">' + escapeHtml(t('Optional deal')) + '</summary>' +
              '<div class="crm-review-deal-field">' +
              '<label class="form-label" for="crm-review-deal-' + escapeHtml(String(item.id)) + '">' + escapeHtml(t('Deal (optional)')) + '</label>' +
              '<select id="crm-review-deal-' + escapeHtml(String(item.id)) + '" name="dealId" class="form-select crm-input" data-crm-review-deal>' +
              '<option value="">' + escapeHtml(t('No deal — mark as overhead')) + '</option>' +
              '</select>' +
              '<p class="crm-muted crm-review-deal-field__hint" data-crm-review-overhead-hint>' + overheadHint + '</p>' +
              '</div></details>')
            : ('<input type="hidden" name="dealId" value="" data-crm-review-deal />');
          const laterBlock =
            '<details class="crm-review-more">' +
            '<summary>' + escapeHtml(t('Remind me later')) + '</summary>' +
            '<div class="crm-review-more__body">' +
            '<p class="crm-muted crm-review-more__hint">' +
            escapeHtml(t('Hides this row for a while. It comes back to Assign — the mail in the shared mailbox stays.')) +
            '</p>' +
            '<div class="crm-review-more__field">' +
            '<p class="form-label" id="crm-review-snooze-label-' + escapeHtml(String(item.id)) + '">' + escapeHtml(t('Bring back in')) + '</p>' +
            '<div class="crm-review-snooze" role="group" aria-labelledby="crm-review-snooze-label-' + escapeHtml(String(item.id)) + '">' +
            '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-snooze="1d" title="' + escapeHtml(t('Tomorrow')) + '">' + escapeHtml(t('1 day')) + '</button>' +
            '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-snooze="7d" title="' + escapeHtml(t('Later')) + '">' + escapeHtml(t('7 days')) + '</button>' +
            '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-snooze="30d">' + escapeHtml(t('30 days')) + '</button>' +
            '</div></div></div></details>';
          actions =
            companyNameHint +
            mailPreviewBlock +
            '<form class="crm-form crm-review-assign" data-review-id="' + escapeHtml(String(item.id)) + '" data-crm-review-row tabindex="-1">' +
            '<div class="crm-review-zone crm-review-zone--decide">' +
            '<p class="visually-hidden">' + escapeHtml(t('What should happen?')) + '</p>' +
            '<p class="crm-muted crm-review-decide__lead">' +
            escapeHtml(t('Link it to a company, mark it unrelated, or remove it from this inbox.')) +
            '</p>' +
            '<div class="crm-review-primary">' +
            companySection +
            dealField +
            '<div class="crm-review-decide__secondary" role="group" aria-label="' + escapeHtml(t('What should happen?')) + '">' +
            '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-none title="' +
            escapeHtml(t('Not a customer or company you track.')) + '">' +
            escapeHtml(t('No relation')) + '</button>' +
            (item.sourceKind === 'shared_mail'
              ? ('<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-spam ' +
                'title="' + escapeHtml(t('Moves this message into the server Spam/Junk folder and leaves Assign.')) + '">' +
                escapeHtml(t('Mark as spam')) + '</button>')
              : '') +
            '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-discard' +
            (item.sourceKind === 'shared_mail' ? ' data-crm-review-discard-mail="1"' : '') +
            ' title="' +
            escapeHtml(item.sourceKind === 'shared_mail'
              ? t('Deletes this message forever from the shared mailbox.')
              : t('Removes this item from Assign.')) + '">' +
            escapeHtml(item.sourceKind === 'shared_mail' ? t('Delete forever') : t('Remove from Assign')) + '</button>' +
            '</div></div></div>' +
            laterBlock +
            '</form>';
        } else if (mailPreviewBlock) {
          actions = mailPreviewBlock + actions;
        }
        if (canWrite && item.sourceKind === 'import_anomaly' && !terminal) {
          const fieldKey = String(ref.fieldKey || 'country');
          const raw = String(ref.raw || item.snippet || '');
          if (fieldKey === 'country' && raw) {
            actions +=
              '<details class="crm-advanced"><summary class="crm-advanced__summary">' + escapeHtml(t('Fix country mapping')) + '</summary>' +
              '<form class="crm-form crm-form--row" data-crm-review-learn="' + escapeHtml(String(item.id)) + '">' +
              '<label class="form-label" for="crm-learn-' + escapeHtml(String(item.id)) + '">' + escapeHtml(t('ISO country')) + '</label>' +
              '<input type="text" id="crm-learn-' + escapeHtml(String(item.id)) + '" class="form-input crm-input" maxlength="2" ' +
              'placeholder="' + escapeHtml(t('DE')) + '" autocomplete="off" />' +
              '<button type="button" class="crm-btn crm-btn--secondary" data-crm-review-apply-once>' +
              escapeHtml(t('Apply once')) + '</button>' +
              '<button type="submit" class="crm-btn crm-btn--primary">' + escapeHtml(t('Apply & learn')) + '</button>' +
              '</form></details>';
          }
        }
        const subject = item.subject || item.snippet || t('Assign item');
        return {
          id: item.id,
          selectable: canWrite && !terminal,
          title: subject,
          subject: subject,
          from: fromAddr || (item.sourceKind === 'shared_mail' ? String(item.snippet || '') : sourceKindLabel(item.sourceKind)),
          listSnippet: item.sourceKind === 'shared_mail' ? '' : String(item.snippet || '').slice(0, 140),
          badges: badges,
          actionHtml: actions,
          mailMsgId: mailMsgId,
          sentAt: item.sentAt || null,
          createdAt: item.createdAt || item.updatedAt || null,
        };
      });

    if (!mount.getAttribute('data-crm-review-sort')) {
      mount.setAttribute('data-crm-review-sort', 'sent');
      mount.setAttribute('data-crm-review-sort-dir', 'desc');
    }
    const sortKey = mount.getAttribute('data-crm-review-sort') || 'sent';
    const sortDir = mount.getAttribute('data-crm-review-sort-dir') === 'asc' ? 'asc' : 'desc';
    function sortModels(list, key, dir) {
      const mul = dir === 'desc' ? -1 : 1;
      return list.slice().sort(function (a, b) {
        let av = '';
        let bv = '';
        if (key === 'from') {
          av = String(a.from || '').toLowerCase();
          bv = String(b.from || '').toLowerCase();
        } else if (key === 'status') {
          av = (a.badges || []).join(' ').toLowerCase();
          bv = (b.badges || []).join(' ').toLowerCase();
        } else if (key === 'sent') {
          const aTs = parseReviewTimestamp(a.sentAt || a.createdAt || '');
          const bTs = parseReviewTimestamp(b.sentAt || b.createdAt || '');
          if (aTs < bTs) return -1 * mul;
          if (aTs > bTs) return 1 * mul;
          return (Number(b.id) || 0) - (Number(a.id) || 0);
        } else {
          av = String(a.subject || a.title || '').toLowerCase();
          bv = String(b.subject || b.title || '').toLowerCase();
        }
        if (av < bv) return -1 * mul;
        if (av > bv) return 1 * mul;
        return (Number(b.id) || 0) - (Number(a.id) || 0);
      });
    }
    const sortedModels = sortModels(viewModels, sortKey, sortDir);
    const board = renderReviewBoard(
      body,
      sortedModels,
      t('Nothing to review'),
      t('Assign inbox'),
      { selectable: canWrite, sortKey: sortKey, sortDir: sortDir },
    );

    const byId = {};
    viewModels.forEach(function (vm) { byId[String(vm.id)] = vm; });
    const threadRows = board ? board.options : [];
    let focusIdx = 0;
    let selectedId = '';

    function activeReaderForm() {
      return body.querySelector('[data-crm-review-reader] [data-crm-review-row], [data-crm-review-reader] [data-review-id]');
    }

    function markThreadSelection(id) {
      let firstVisible = null;
      threadRows.forEach(function (row, idx) {
        const rid = row.getAttribute('data-crm-review-item-id') || '';
        const on = id !== '' && rid === String(id);
        row.setAttribute('aria-current', on ? 'true' : 'false');
        row.classList.toggle('is-selected', on);
        row.tabIndex = on ? 0 : -1;
        if (on) focusIdx = idx;
        if (!firstVisible && !row.hidden) firstVisible = row;
      });
      // After collapse, keep one row in the tab order (WCAG keyboard access).
      if (id === '' && firstVisible) {
        firstVisible.tabIndex = 0;
      }
    }

    function placeDetailUnderRow(row) {
      if (!board || !board.detailRowEl || !row || !board.listEl) return board && board.readerEl;
      const detailTr = board.detailRowEl;
      detailTr.hidden = false;
      if (detailTr.parentNode !== board.listEl || row.nextElementSibling !== detailTr) {
        row.after(detailTr);
      }
      return board.readerEl;
    }

    function clearReviewSelection(opts) {
      const prevId = selectedId;
      selectedId = '';
      mount.removeAttribute('data-crm-review-selected');
      markThreadSelection('');
      bumpMailPreviewRequest();
      if (board && board.detailRowEl) {
        board.detailRowEl.hidden = true;
      }
      if (board && board.readerEl) {
        board.readerEl.innerHTML =
          '<h3 class="crm-review-detail__title visually-hidden">' + escapeHtml(t('Details')) + '</h3>' +
          '<div class="crm-mail-reader__empty crm-empty-state" role="status">' +
          '<p class="crm-empty-state__text">' + escapeHtml(t('Select a message to read and decide.')) + '</p>' +
          '</div>';
      }
      const row = prevId ? document.getElementById('crm-review-opt-' + prevId) : null;
      if (row) {
        row.tabIndex = 0;
        if (!(opts && opts.quietFocus)) {
          try { row.focus({ preventScroll: true }); } catch (e) { try { row.focus(); } catch (e2) { /* ignore */ } }
        }
      }
    }

    function selectReviewItem(id, opts) {
      const vm = byId[String(id)];
      if (!vm || !board) return;
      // Second activation of the open row collapses the reader (accordion).
      if (!(opts && opts.forceOpen) && selectedId === String(id)) {
        clearReviewSelection(opts);
        return;
      }
      selectedId = String(id);
      mount.setAttribute('data-crm-review-selected', selectedId);
      markThreadSelection(selectedId);
      const row = document.getElementById('crm-review-opt-' + selectedId);
      const reader = placeDetailUnderRow(row);
      if (!reader) return;
      const chipBadges = (vm.badges || []).filter(function (b) {
        return /suggested\s*:/i.test(String(b));
      });
      const header =
        '<header class="crm-mail-reader__header">' +
        '<p class="crm-mail-reader__from"><span class="crm-mail-reader__label">' + escapeHtml(t('From')) + '</span> ' +
        escapeHtml(vm.from || t('Unknown sender')) + '</p>' +
        '<h3 class="crm-mail-reader__subject">' + escapeHtml(vm.subject || vm.title || '') + '</h3>' +
        (function () {
          const whenRaw = String(vm.sentAt || vm.createdAt || '').trim();
          if (!whenRaw) return '';
          return '<p class="crm-mail-reader__when crm-muted"><span class="crm-mail-reader__label">' +
            escapeHtml(vm.sentAt ? t('Sent') : t('Added')) + '</span> ' +
            escapeHtml(formatDisplayDate(whenRaw)) + '</p>';
        }()) +
        (chipBadges.length
          ? ('<p class="crm-mail-reader__meta">' + chipBadges.map(function (b) {
            return '<span class="crm-badge">' + escapeHtml(String(b)) + '</span>';
          }).join('') + '</p>')
          : '') +
        '</header>';
      reader.innerHTML =
        '<h3 class="crm-review-detail__title visually-hidden">' + escapeHtml(t('Details')) + '</h3>' +
        '<div class="crm-mail-reader__panel">' + header +
        '<div class="crm-mail-reader__body" data-crm-review-reader-actions>' + (vm.actionHtml || '') + '</div></div>';
      bindMailPreviewActions(reader, ws, mount);
      // Opening a row always loads the message — never require a second click.
      // Bump generation + abort prior fetch so rapid j/k does not stampede IMAP.
      const previewReq = bumpMailPreviewRequest();
      ensureMailPreviewsOpen(reader, ws, mount, {
        quietFocus: !!(opts && opts.quietFocus),
        previewGeneration: previewReq.gen,
        signal: previewReq.signal,
      });
      if (!(opts && opts.quietFocus) && row) {
        try {
          row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (scrollErr) { /* ignore */ }
      }
      if (canReopen) {
        reader.querySelectorAll('[data-crm-review-reopen]').forEach(function (btn) {
          btn.addEventListener('click', async function () {
            const rid = btn.getAttribute('data-crm-review-reopen');
            try {
              await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + rid + '/reopen', {});
              await loadReview(mount);
            } catch (e) {
              showAlert(mount, e.message || String(e));
            }
          });
        });
      }
      if (canWrite) {
        reader.querySelectorAll('[data-review-id]').forEach(function (form) {
          wireAssignAssignForm(form);
        });
        reader.querySelectorAll('[data-crm-review-learn]').forEach(function (learnForm) {
          wireAssignLearnForm(learnForm);
        });
      }
      if (!(opts && opts.quietFocus) && row) {
        try { row.focus({ preventScroll: true }); } catch (e) { try { row.focus(); } catch (e2) { /* ignore */ } }
      }
    }

    if (board && threadRows.length) {
      board.listEl.addEventListener('click', function (ev) {
        if (ev.target.closest && ev.target.closest('[data-crm-review-select], .crm-review-select')) {
          ev.stopPropagation();
          // Click toggles the box before change in some browsers — sync the sticky bar immediately.
          if (typeof queueMicrotask === 'function') queueMicrotask(syncAssignBulkBar);
          else setTimeout(syncAssignBulkBar, 0);
          return;
        }
        const openBtn = ev.target.closest ? ev.target.closest('[data-crm-review-open]') : null;
        if (openBtn) {
          ev.preventDefault();
          const oid = openBtn.getAttribute('data-crm-review-open');
          if (oid) selectReviewItem(oid);
          return;
        }
        const row = ev.target.closest ? ev.target.closest('li.crm-review-row') : null;
        if (!row || !board.listEl.contains(row)) return;
        const id = row.getAttribute('data-crm-review-item-id');
        if (id) selectReviewItem(id);
      });
      board.listEl.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') return;
        const row = ev.target.closest ? ev.target.closest('li.crm-review-row') : null;
        if (!row || ev.target.closest('[data-crm-review-select], .crm-review-select, button, a, input')) return;
        ev.preventDefault();
        const id = row.getAttribute('data-crm-review-item-id');
        if (id) selectReviewItem(id);
      });
      if (board.boardEl) {
        board.boardEl.querySelectorAll('[data-crm-review-sort]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const col = btn.getAttribute('data-crm-review-sort') || 'sent';
            const cur = mount.getAttribute('data-crm-review-sort') || 'sent';
            const curDir = mount.getAttribute('data-crm-review-sort-dir') === 'asc' ? 'asc' : 'desc';
            let nextDir;
            if (cur === col) {
              nextDir = curDir === 'asc' ? 'desc' : 'asc';
            } else {
              nextDir = col === 'sent' ? 'desc' : 'asc';
            }
            mount.setAttribute('data-crm-review-sort', col);
            mount.setAttribute('data-crm-review-sort-dir', nextDir);
            void loadReview(mount);
          });
        });
      }
      // Stay collapsed until the user opens a row (no auto-pick of the first message).
      const prefer = mount.getAttribute('data-crm-review-selected') || '';
      if (prefer && byId[prefer]) {
        selectReviewItem(prefer, { quietFocus: true });
      } else {
        mount.removeAttribute('data-crm-review-selected');
        if (board.detailRowEl) board.detailRowEl.hidden = true;
      }
    }

    // Instant client-side filter — Search still hits the server on submit.
    const liveQ = document.getElementById('crm-review-q');
    if (liveQ && board && board.listEl) {
      const applyLiveFilter = function () {
        const q = String(liveQ.value || '').trim().toLowerCase();
        let visible = 0;
        board.listEl.querySelectorAll('li.crm-review-row').forEach(function (row) {
          if (!q) {
            row.hidden = false;
            visible += 1;
            return;
          }
          const hay = [
            row.getAttribute('data-crm-sort-from') || '',
            row.getAttribute('data-crm-sort-subject') || '',
            row.getAttribute('data-crm-sort-status') || '',
          ].join(' ');
          const show = hay.indexOf(q) !== -1;
          row.hidden = !show;
          if (show) visible += 1;
        });
        let note = board.boardEl.querySelector('[data-crm-review-filter-note]');
        if (!note) {
          note = document.createElement('p');
          note.className = 'crm-muted';
          note.setAttribute('data-crm-review-filter-note', '');
          note.setAttribute('role', 'status');
          const tableWrap = board.boardEl.querySelector('.table-container');
          if (tableWrap && tableWrap.parentNode) {
            tableWrap.parentNode.insertBefore(note, tableWrap.nextSibling);
          }
        }
        if (!q) {
          note.hidden = true;
          note.textContent = '';
        } else {
          note.hidden = false;
          note.textContent = visible === 0
            ? t('No rows match this filter.')
            : (visible === 1
              ? t('1 row matches')
              : t('%s rows match', [String(visible)]));
        }
        if (board.detailRowEl) {
          const sel = board.listEl.querySelector('li.crm-review-row.is-selected');
          board.detailRowEl.hidden = !sel || !!sel.hidden;
        }
      };
      liveQ.addEventListener('input', applyLiveFilter);
    }

    if (items.length === 0) {
      const emptyCta = document.createElement('div');
      emptyCta.className = 'crm-empty-state';
      emptyCta.setAttribute('role', 'status');
      emptyCta.innerHTML =
        '<h3 class="crm-empty-state__title">' + escapeHtml(t('Nothing to review')) + '</h3>' +
        '<p class="crm-empty-state__text">' + escapeHtml(t('Only mail we cannot place on a customer yet shows here. Known contacts are filed automatically.')) + '</p>' +
        '<p class="crm-empty-state__actions"><a class="crm-btn crm-btn--primary" href="' +
        escapeHtml(OC.generateUrl('/apps/customercheck/settings/review-sources')) +
        '">' + escapeHtml(t('Open review source settings')) + '</a></p>';
      body.appendChild(emptyCta);
    }

    const sources = data.sources || {};
    if (canWrite && sources.contact_suggest && sources.contact_suggest.enabled) {
      const send = document.createElement('details');
      send.className = 'crm-advanced';
      send.innerHTML =
        '<summary class="crm-advanced__summary">' + escapeHtml(t('Send a contact to Assign')) + '</summary>';
      const sendForm = document.createElement('form');
      sendForm.className = 'crm-form crm-form--row';
      sendForm.setAttribute('aria-label', t('Send contact to Assign'));
      sendForm.innerHTML =
        '<label class="form-label" for="crm-review-contact-q">' + escapeHtml(t('Send a contact to Assign')) + '</label>' +
        '<input type="search" id="crm-review-contact-q" class="form-input crm-input" autocomplete="off" ' +
        'placeholder="' + escapeHtml(t('Name or email…')) + '" />' +
        '<button type="submit" class="crm-btn crm-btn--secondary">' + escapeHtml(t('Send to Assign')) + '</button>';
      send.appendChild(sendForm);
      mount.appendChild(send);
      sendForm.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const q = (sendForm.querySelector('#crm-review-contact-q') || {}).value || '';
        if (String(q).trim().length < 2) {
          showAlert(mount, t('Type at least 2 characters.'));
          return;
        }
        try {
          const hits = await apiGet('/api/v1/contacts/suggest?q=' + encodeURIComponent(String(q).trim()));
          const row = (Array.isArray(hits) ? hits : (hits.items || hits.data || []))[0];
          if (!row) {
            showAlert(mount, t('No contact found.'));
            return;
          }
          await apiPost('/api/v1/workspaces/' + ws + '/review/enqueue', {
            sourceKind: 'contact_suggest',
            refKey: String(row.uid || row.email || row.name || q),
            sourceRef: { uid: row.uid || null, email: row.email || null },
            subject: row.name || row.email || String(q),
            snippet: row.email || '',
            email: row.email || null,
            org: row.name || null,
          });
          await loadReview(mount);
        } catch (e) {
          showAlert(mount, e.message || String(e));
        }
      });
    }

    // GNQ §5.6 keyboard: j/k move thread selection; a assign; s snooze; n no relation.
    function focusRow(dir) {
      if (!threadRows.length) return;
      const visible = threadRows.filter(function (row) { return !row.hidden; });
      if (!visible.length) return;
      const step = dir >= 0 ? 1 : -1;
      let cur = visible.indexOf(threadRows[focusIdx]);
      if (cur < 0) {
        cur = step > 0 ? -1 : 0;
      }
      const next = visible[(cur + step + visible.length) % visible.length];
      focusIdx = threadRows.indexOf(next);
      const id = next.getAttribute('data-crm-review-item-id');
      if (id) selectReviewItem(id);
    }
    if (typeof mount._crmAssignKeydown === 'function') {
      mount.removeEventListener('keydown', mount._crmAssignKeydown);
    }
    mount._crmAssignKeydown = function (ev) {
      if (!threadRows.length) return;
      const tag = (ev.target && ev.target.tagName) || '';
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
        if (ev.key !== 'Escape') return;
      }
      const form = activeReaderForm();
      if (ev.key === 'j' || ev.key === 'ArrowDown') {
        ev.preventDefault();
        focusRow(1);
      } else if (ev.key === 'k' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        focusRow(-1);
      } else if (ev.key === 'a' && form) {
        const quick = form.querySelector('[data-crm-review-quick-assign]');
        if (quick) {
          ev.preventDefault();
          quick.click();
          return;
        }
        const pickCompany = form.querySelector('.crm-review-pick-company');
        const searchOpen = pickCompany && pickCompany.querySelector('.user-picker__search');
        if (searchOpen && !String(searchOpen.value || '').trim() && !(form.querySelector('[name="companyId"]') || {}).value) {
          ev.preventDefault();
          searchOpen.focus();
          return;
        }
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
          ev.preventDefault();
          btn.click();
        }
      } else if (ev.key === 's' && form) {
        const btn = form.querySelector('[data-crm-review-snooze="7d"]')
          || form.querySelector('[data-crm-review-snooze]');
        if (btn) {
          ev.preventDefault();
          btn.click();
        }
      } else if (ev.key === 'n' && form) {
        const btn = form.querySelector('[data-crm-review-none]');
        if (btn) {
          ev.preventDefault();
          btn.click();
        }
      } else if ((ev.key === 'd' || ev.key === 'Delete') && form) {
        const btn = form.querySelector('[data-crm-review-discard]');
        if (btn) {
          ev.preventDefault();
          btn.click();
        }
      } else if (ev.key === 'Enter' && form) {
        const search = form.querySelector('.user-picker__search');
        if (search) {
          ev.preventDefault();
          search.focus();
        }
      }
    };
    mount.addEventListener('keydown', mount._crmAssignKeydown);

    function wireAssignAssignForm(form) {
      if (!form || form.getAttribute('data-crm-review-wired') === '1') return;
      form.setAttribute('data-crm-review-wired', '1');
      const rid = form.getAttribute('data-review-id');
      const search = form.querySelector('.user-picker__search');
      const suggest = form.querySelector('.user-picker__list');
      const hidden = form.querySelector('[name="companyId"]');
      const dealSelect = form.querySelector('select[data-crm-review-deal]');
      const overheadHintEl = form.querySelector('[data-crm-review-overhead-hint]');

      const quickBtn = form.querySelector('[data-crm-review-quick-assign]');
      if (quickBtn) {
        quickBtn.addEventListener('click', async function () {
          const companyId = parseInt(quickBtn.getAttribute('data-company-id') || (hidden ? hidden.value : '0'), 10);
          if (!(companyId > 0)) {
            showAlert(mount, t('Pick a company first.'));
            return;
          }
          if (hidden) hidden.value = String(companyId);
          quickBtn.disabled = true;
          try {
            const payload = { companyId: companyId };
            if (dealSelect && dealSelect.value) {
              payload.dealId = parseInt(dealSelect.value, 10);
            }
            await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + rid + '/assign', payload);
            await loadReview(mount);
          } catch (e) {
            const msg = e.message || String(e);
            showAlert(mount, msg);
            // Concurrent triage: refresh so the row does not stay "assignable" after a peer won.
            if (/handled this message|not open for triage|Reload Assign/i.test(msg)) {
              try { await loadReview(mount); } catch (reloadErr) { /* keep alert */ }
            }
          } finally {
            quickBtn.disabled = false;
          }
        });
      }

      async function loadDealsForCompany(companyId) {
        if (!dealSelect) {
          return;
        }
        dealSelect.innerHTML = '<option value="">' + escapeHtml(t('No deal — mark as overhead')) + '</option>';
        if (!companyId || companyId < 1) {
          return;
        }
        try {
          const deals = await apiGet('/api/v1/deals?companyId=' + encodeURIComponent(String(companyId)) + '&workspaceId=' + encodeURIComponent(String(ws)));
          const rows = Array.isArray(deals) ? deals : (deals && deals.items) || [];
          rows.forEach(function (d) {
            if (!d || !d.id) {
              return;
            }
            const opt = document.createElement('option');
            opt.value = String(d.id);
            opt.textContent = (d.title || t('Deal')) + ' · ' + (d.stage || '');
            dealSelect.appendChild(opt);
          });
        } catch (e) {
          const soft = document.createElement('p');
          soft.className = 'crm-muted';
          soft.setAttribute('role', 'status');
          soft.textContent = t('Deals could not be loaded — you can still assign without a deal.');
          if (dealSelect && dealSelect.parentNode) {
            dealSelect.parentNode.insertBefore(soft, dealSelect);
          }
        }
      }

      if (dealSelect) {
        dealSelect.addEventListener('change', function () {
          if (!overheadHintEl) {
            return;
          }
          const hasDeal = String(dealSelect.value || '') !== '';
          overheadHintEl.textContent = hasDeal
            ? t('Assigned to a deal — not overhead.')
            : t('No deal selected — this stays overhead (display only, not a cost).');
        });
      }

      if (search && suggest && hidden) {
        const nav = (window.CustomerCheckUserPickerNav && window.CustomerCheckUserPickerNav.bindListboxKeyboard)
          ? window.CustomerCheckUserPickerNav.bindListboxKeyboard(search, suggest)
          : { reset: function () {} };
        let timer = null;
        if (hidden.value) {
          void loadDealsForCompany(parseInt(hidden.value, 10));
        }
        search.addEventListener('input', function () {
          if (hidden.value) hidden.value = '';
          if (dealSelect) {
            dealSelect.innerHTML = '<option value="">' + escapeHtml(t('No deal — mark as overhead')) + '</option>';
          }
          clearTimeout(timer);
          const q = search.value.trim();
          if (q.length < 2) {
            suggest.hidden = true;
            suggest.innerHTML = '';
            search.setAttribute('aria-expanded', 'false');
            nav.reset();
            return;
          }
          timer = setTimeout(async function () {
            try {
              const list = await apiGet('/api/v1/companies?q=' + encodeURIComponent(q) + '&limit=20&workspaceId=' + encodeURIComponent(String(ws)));
              const rows = Array.isArray(list) ? list : [];
              suggest.innerHTML = '';
              rows.forEach(function (c) {
                const opt = document.createElement('li');
                opt.className = 'user-picker__item';
                opt.setAttribute('role', 'option');
                opt.textContent = c.name || ('#' + String(c.id || ''));
                opt.addEventListener('click', function () {
                  hidden.value = String(c.id || '');
                  search.value = c.name || ('#' + String(c.id || ''));
                  suggest.hidden = true;
                  search.setAttribute('aria-expanded', 'false');
                  nav.reset();
                  void loadDealsForCompany(parseInt(hidden.value, 10));
                });
                suggest.appendChild(opt);
              });
              suggest.hidden = rows.length === 0;
              search.setAttribute('aria-expanded', rows.length === 0 ? 'false' : 'true');
              nav.reset();
            } catch (e) {
              suggest.hidden = true;
            }
          }, 250);
        });
      }
      form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') {
          return;
        }
        const companyId = parseInt(hidden ? hidden.value : '0', 10);
        if (!companyId || companyId < 1) {
          showAlert(mount, t('Pick a company from the search results first.'));
          return;
        }
        const dealRaw = dealSelect ? String(dealSelect.value || '').trim() : '';
        const payload = { companyId: companyId };
        if (dealRaw !== '') {
          payload.dealId = parseInt(dealRaw, 10);
        }
        const submitBtns = form.querySelectorAll('button[type="submit"]');
        form.setAttribute('aria-busy', 'true');
        submitBtns.forEach(function (b) { b.disabled = true; });
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + rid + '/assign', payload);
          await loadReview(mount);
        } catch (e) {
          const msg = e.message || String(e);
          showAlert(form, msg);
          if (/handled this message|not open for triage|Reload Assign/i.test(msg)) {
            try { await loadReview(mount); } catch (reloadErr) { /* keep alert */ }
          }
        } finally {
          form.removeAttribute('aria-busy');
          submitBtns.forEach(function (b) { b.disabled = false; });
        }
      });
      form.querySelectorAll('[data-crm-review-snooze]').forEach(function (snoozeBtn) {
        snoozeBtn.addEventListener('click', async function () {
          const preset = snoozeBtn.getAttribute('data-crm-review-snooze') || '7d';
          try {
            await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + rid + '/snooze', { preset: preset });
            await loadReview(mount);
          } catch (e) {
            showAlert(form, e.message || String(e));
          }
        });
      });
      const followBtn = form.querySelector('[data-crm-review-followup]');
      if (followBtn) {
        followBtn.addEventListener('click', async function () {
          const noteInput = form.querySelector('[name="followUpNote"]');
          const note = noteInput ? String(noteInput.value || '').trim() : '';
          if (note.length < 3) {
            showAlert(form, t('Short follow-up note (at least 3 characters)'));
            if (noteInput) noteInput.focus();
            return;
          }
          try {
            await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + rid + '/follow-up', { note: note });
            await loadReview(mount);
          } catch (e) {
            showAlert(form, e.message || String(e));
          }
        });
      }
      const noneBtn = form.querySelector('[data-crm-review-none]');
      if (noneBtn) {
        noneBtn.addEventListener('click', async function () {
          try {
            await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + rid + '/no-relation', { note: '' });
            await loadReview(mount);
          } catch (e) {
            showAlert(form, e.message || String(e));
          }
        });
      }
      const discardBtn = form.querySelector('[data-crm-review-discard]');
      if (discardBtn) {
        discardBtn.addEventListener('click', async function () {
          const C = window.CustomerCheckComponents || window.CrmComponents;
          const isMail = discardBtn.getAttribute('data-crm-review-discard-mail') === '1';
          if (!(C && typeof C.confirmDialog === 'function')) {
            showAlert(form, t('Confirmation UI is unavailable. Reload the page and try again.'));
            return;
          }
          const ok = await C.confirmDialog({
              title: isMail ? t('Delete this mail forever?') : t('Remove from Assign?'),
              message: isMail
                ? t('Permanently deletes the message from the shared mailbox. It also leaves Assign. Prefer Mark as spam when you want the mail server’s Spam folder.')
                : t('Removes this item from Assign.'),
              confirmLabel: isMail ? t('Delete forever') : t('Remove from Assign'),
              cancelLabel: t('Cancel'),
              variant: 'danger',
            });
          if (!ok || !ok.confirmed) return;
          discardBtn.disabled = true;
          try {
            await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + rid + '/discard', {});
            const live = document.getElementById('crm-live-region');
            if (live) {
              live.textContent = isMail ? t('Mail deleted.') : t('1 item removed from Assign');
            }
            await loadReview(mount);
          } catch (e) {
            discardBtn.disabled = false;
            showAlert(form, e.message || String(e));
          }
        });
      }
      const spamBtn = form.querySelector('[data-crm-review-spam]');
      if (spamBtn) {
        spamBtn.addEventListener('click', async function () {
          const C = window.CustomerCheckComponents || window.CrmComponents;
          if (!(C && typeof C.confirmDialog === 'function')) {
            showAlert(form, t('Confirmation UI is unavailable. Reload the page and try again.'));
            return;
          }
          const ok = await C.confirmDialog({
            title: t('Mark as spam?'),
            message: t('Moves this message into the shared mailbox Spam/Junk folder and removes it from Assign. You can usually recover it from Spam in the mail host.'),
            confirmLabel: t('Mark as spam'),
            cancelLabel: t('Cancel'),
            variant: 'warning',
          });
          if (!ok || !ok.confirmed) return;
          spamBtn.disabled = true;
          try {
            await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + rid + '/mark-spam', {});
            const live = document.getElementById('crm-live-region');
            if (live) live.textContent = t('Marked as spam.');
            await loadReview(mount);
          } catch (e) {
            spamBtn.disabled = false;
            showAlert(form, e.message || String(e));
          }
        });
      }
    }

    function wireAssignLearnForm(learnForm) {
      if (!learnForm || learnForm.getAttribute('data-crm-review-wired') === '1') return;
      learnForm.setAttribute('data-crm-review-wired', '1');
      async function applyCountryMap(learn, closeNote) {
        const input = learnForm.querySelector('input');
        const canonical = input ? String(input.value || '').trim() : '';
        const itemId = learnForm.getAttribute('data-crm-review-learn');
        await apiPost('/api/v1/workspaces/' + ws + '/review/items/' + itemId + '/apply-country', {
          canonical: canonical,
          learn: !!learn,
        });
        void closeNote;
        await loadReview(mount);
      }
      learnForm.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        try {
          await applyCountryMap(true, t('Mapped via Apply & learn'));
        } catch (e) {
          showAlert(learnForm, e.message || String(e));
        }
      });
      const onceBtn = learnForm.querySelector('[data-crm-review-apply-once]');
      if (onceBtn) {
        onceBtn.addEventListener('click', async function () {
          try {
            await applyCountryMap(false, t('Applied once without learning'));
          } catch (e) {
            showAlert(learnForm, e.message || String(e));
          }
        });
      }
    }

    if (!canWrite) {
      if (!kind || kind === 'shared_mail' || kind === 'mail' || kind === 'personal_mail_shared_unmatched') {
        await renderFailedSendsSection(mount, ws, function () { return loadReview(mount); });
      }
      return;
    }

    function selectedAssignIds() {
      return Array.from(body.querySelectorAll('[data-crm-review-select]:checked'))
        .filter(function (el) {
          const row = el.closest('li.crm-review-row');
          return !row || !row.hidden;
        })
        .map(function (el) { return parseInt(el.value, 10); })
        .filter(function (id) { return id > 0; });
    }

    function syncAssignBulkBar() {
      if (!bulkBar) return;
      const ids = selectedAssignIds();
      const countEl = bulkBar.querySelector('[data-crm-review-bulk-count]');
      const clearBtn = bulkBar.querySelector('[data-crm-review-bulk-clear]');
      const spamBtn = bulkBar.querySelector('[data-crm-review-bulk-spam]');
      const removeBtn = bulkBar.querySelector('[data-crm-review-bulk-remove]');
      const n = ids.length;
      if (countEl) {
        countEl.textContent = n === 0
          ? t('Select messages to delete or mark as spam')
          : (n === 1
            ? t('1 message selected')
            : t('%s messages selected', [String(n)]));
      }
      if (clearBtn) clearBtn.disabled = n === 0;
      if (spamBtn) {
        spamBtn.disabled = n === 0;
        spamBtn.textContent = n > 0 ? t('Mark as spam') + ' (' + String(n) + ')' : t('Mark as spam');
      }
      if (removeBtn) {
        removeBtn.disabled = n === 0;
        removeBtn.textContent = n > 0 ? t('Delete selected') + ' (' + String(n) + ')' : t('Delete selected');
      }
      bulkBar.hidden = n === 0;
      const allBoxes = Array.from(body.querySelectorAll('[data-crm-review-select]')).filter(function (b) {
        const row = b.closest('li.crm-review-row');
        return !row || !row.hidden;
      });
      const selectAlls = Array.from(body.querySelectorAll('[data-crm-review-select-all]'));
      if (selectAlls.length && allBoxes.length) {
        const checked = allBoxes.filter(function (b) { return b.checked; }).length;
        const allOn = checked === allBoxes.length;
        const mixed = checked > 0 && checked < allBoxes.length;
        selectAlls.forEach(function (selectAll) {
          selectAll.checked = allOn;
          selectAll.indeterminate = mixed;
        });
      }
    }

    if (canWrite) {
      body.addEventListener('change', function (ev) {
        const tEl = ev.target;
        if (!tEl) return;
        if (tEl.matches && tEl.matches('[data-crm-review-select-all]')) {
          body.querySelectorAll('[data-crm-review-select]').forEach(function (box) {
            const row = box.closest('li.crm-review-row');
            if (row && row.hidden) {
              box.checked = false;
              return;
            }
            box.checked = !!tEl.checked;
          });
          syncAssignBulkBar();
          return;
        }
        if (tEl.matches && tEl.matches('[data-crm-review-select]')) {
          syncAssignBulkBar();
        }
      });
      if (bulkBar) {
        const clearBtn = bulkBar.querySelector('[data-crm-review-bulk-clear]');
        if (clearBtn) {
          clearBtn.addEventListener('click', function () {
            body.querySelectorAll('[data-crm-review-select]').forEach(function (box) {
              box.checked = false;
            });
            body.querySelectorAll('[data-crm-review-select-all]').forEach(function (selectAll) {
              selectAll.checked = false;
              selectAll.indeterminate = false;
            });
            syncAssignBulkBar();
          });
        }
        const removeBtn = bulkBar.querySelector('[data-crm-review-bulk-remove]');
        if (removeBtn) {
          removeBtn.addEventListener('click', async function () {
            const ids = selectedAssignIds();
            if (!ids.length) {
              showAlert(mount, t('Select at least one message to delete.'));
              return;
            }
            const C = window.CustomerCheckComponents || window.CrmComponents;
            if (!(C && typeof C.confirmDialog === 'function')) {
              showAlert(mount, t('Confirmation UI is unavailable. Reload the page and try again.'));
              return;
            }
            const ok = await C.confirmDialog({
                title: t('Delete selected forever?'),
                message: t('Shared-mailbox messages are permanently deleted on the mail server. Other items only leave Assign. Prefer Mark as spam when you want the Spam folder.'),
                confirmLabel: t('Delete selected'),
                cancelLabel: t('Cancel'),
                variant: 'danger',
              });
            if (!ok || !ok.confirmed) return;
            removeBtn.disabled = true;
            try {
              const result = await apiPost('/api/v1/workspaces/' + ws + '/review/items/discard-bulk', { ids: ids });
              const n = (result && result.discarded) || 0;
              const failed = (result && result.failed) || 0;
              const live = document.getElementById('crm-live-region');
              if (live) {
                live.textContent = n === 1
                  ? t('1 message deleted or removed')
                  : t('%s messages deleted or removed', [String(n)]);
              }
              if (failed > 0) {
                const firstErr = result.errors && result.errors[0] && result.errors[0].message
                  ? String(result.errors[0].message)
                  : t('Some messages could not be deleted on the mail server.');
                showAlert(mount, firstErr);
              }
              await loadReview(mount);
              const liveAfter = document.getElementById('crm-live-region');
              if (liveAfter && n > 0) {
                const left = mount.querySelector('.crm-review-count');
                if (left && left.textContent) {
                  liveAfter.textContent = (liveAfter.textContent ? (liveAfter.textContent + ' ') : '') +
                    t('Inbox refreshed — next messages in the queue are shown.');
                }
              }
            } catch (e) {
              removeBtn.disabled = false;
              showAlert(mount, e.message || String(e));
            }
          });
        }
        const spamBulkBtn = bulkBar.querySelector('[data-crm-review-bulk-spam]');
        if (spamBulkBtn) {
          spamBulkBtn.addEventListener('click', async function () {
            const ids = selectedAssignIds();
            if (!ids.length) {
              showAlert(mount, t('Select at least one message to mark as spam.'));
              return;
            }
            const C = window.CustomerCheckComponents || window.CrmComponents;
            if (!(C && typeof C.confirmDialog === 'function')) {
              showAlert(mount, t('Confirmation UI is unavailable. Reload the page and try again.'));
              return;
            }
            const ok = await C.confirmDialog({
              title: t('Mark selected as spam?'),
              message: t('Moves shared-mailbox messages into the server Spam/Junk folder and removes them from Assign. Non-mail items are skipped.'),
              confirmLabel: t('Mark as spam'),
              cancelLabel: t('Cancel'),
              variant: 'warning',
            });
            if (!ok || !ok.confirmed) return;
            spamBulkBtn.disabled = true;
            try {
              const result = await apiPost('/api/v1/workspaces/' + ws + '/review/items/mark-spam-bulk', { ids: ids });
              const n = (result && result.discarded) || 0;
              const failed = (result && result.failed) || 0;
              const live = document.getElementById('crm-live-region');
              if (live) {
                live.textContent = n === 1
                  ? t('1 message marked as spam')
                  : t('%s messages marked as spam', [String(n)]);
              }
              if (failed > 0) {
                const firstErr = result.errors && result.errors[0] && result.errors[0].message
                  ? String(result.errors[0].message)
                  : t('Some messages could not be marked as spam.');
                showAlert(mount, firstErr);
              }
              await loadReview(mount);
            } catch (e) {
              spamBulkBtn.disabled = false;
              showAlert(mount, e.message || String(e));
            }
          });
        }
      }
      syncAssignBulkBar();
    }

    // Failed SMTP outbox belongs with shared mail Assign (mail-triage redirects here).
    if (!kind || kind === 'shared_mail' || kind === 'mail' || kind === 'personal_mail_shared_unmatched') {
      await renderFailedSendsSection(mount, ws, function () { return loadReview(mount); });
    }
  }

  /**
   * Failed shared-mailbox SMTP sends — visible on Assign (shared mail) and legacy mail-triage.
   * @param {HTMLElement} mount
   * @param {number} ws
   * @param {() => Promise<void>} reload
   */
  async function renderFailedSendsSection(mount, ws, reload) {
    let drafts = { items: [], canMailSend: false };
    try {
      drafts = await apiGet('/api/v1/workspaces/' + ws + '/mail/drafts');
    } catch (e) {
      drafts = { items: [], canMailSend: false, error: e.message || String(e) };
    }
    const draftsHost = document.createElement('section');
    draftsHost.className = 'crm-card';
    draftsHost.setAttribute('aria-labelledby', 'crm-mail-drafts-title');
    draftsHost.innerHTML =
      '<header class="crm-card__header"><h2 id="crm-mail-drafts-title" class="crm-card__title">' +
      escapeHtml(t('Failed sends')) + '</h2></header>' +
      '<div class="crm-card__body">' +
      '<p class="crm-muted" role="status">' +
      escapeHtml(t('Messages that could not leave the shared mailbox. Everyone who can read mail in this workspace can see them.')) +
      '</p>' +
      '<div data-crm-mail-drafts-body></div></div>';
    mount.appendChild(draftsHost);
    const draftsBody = draftsHost.querySelector('[data-crm-mail-drafts-body]');
    const draftItems = drafts.items || [];
    const canSendDrafts = !!drafts.canMailSend;
    if (drafts.error) {
      draftsBody.innerHTML = '<p class="crm-muted" role="alert">' + escapeHtml(drafts.error) + '</p>';
      return;
    }
    renderSuiteTable(
      draftsBody,
      draftItems.map(function (d) {
        const did = d.id;
        let actions = '';
        if (canSendDrafts) {
          actions =
            '<div class="crm-form-actions crm-form-actions--start">' +
            '<button type="button" class="crm-btn crm-btn--primary" data-crm-draft-resend="' + escapeHtml(String(did)) + '">' +
            escapeHtml(t('Send again')) + '</button>' +
            '<button type="button" class="crm-btn crm-btn--secondary" data-crm-draft-delete="' + escapeHtml(String(did)) + '">' +
            escapeHtml(t('Discard')) + '</button></div>';
        } else {
          actions = '<p class="crm-muted">' + escapeHtml(t('Ask a teammate with send permission to retry or discard.')) + '</p>';
        }
        return {
          title: d.subject || t('Failed send'),
          meta: (d.to ? d.to : '') + (d.updatedAt ? ' · ' + d.updatedAt : ''),
          actionHtml: actions,
        };
      }),
      t('No failed sends waiting.'),
      t('Failed sends'),
    );
    draftsBody.querySelectorAll('[data-crm-draft-resend]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        if (btn.disabled) return;
        const C = window.CustomerCheckComponents || window.CrmComponents;
        const id = btn.getAttribute('data-crm-draft-resend');
        const ok = C && typeof C.confirmDialog === 'function'
          ? await C.confirmDialog({
            title: t('Send again?'),
            message: t('This sends from the shared mailbox. Everyone who can read mail can see it.'),
            confirmLabel: t('Send'),
            cancelLabel: t('Cancel'),
            variant: 'info',
          })
          : null;
        if (!ok || !ok.confirmed) return;
        btn.disabled = true;
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/mail/drafts/' + id + '/send', {
            idempotencyKey: makeDraftSendKey(),
          });
          const live = document.getElementById('crm-live-region');
          if (live) live.textContent = t('Message sent from the shared mailbox.');
          await reload();
        } catch (e) {
          btn.disabled = false;
          showAlert(draftsHost, e.message || String(e));
        }
      });
    });
    draftsBody.querySelectorAll('[data-crm-draft-delete]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        if (btn.disabled) return;
        const C = window.CustomerCheckComponents || window.CrmComponents;
        const id = btn.getAttribute('data-crm-draft-delete');
        const ok = C && typeof C.confirmDialog === 'function'
          ? await C.confirmDialog({
            title: t('Discard this failed send?'),
            message: t('The message will be removed from this workspace inbox. This cannot be undone.'),
            confirmLabel: t('Discard'),
            cancelLabel: t('Cancel'),
            variant: 'danger',
          })
          : null;
        if (!ok || !ok.confirmed) return;
        btn.disabled = true;
        try {
          await apiDelete('/api/v1/workspaces/' + ws + '/mail/drafts/' + id);
          await reload();
        } catch (e) {
          btn.disabled = false;
          showAlert(draftsHost, e.message || String(e));
        }
      });
    });
  }

  async function loadConflicts(mount) {
    const ws = await resolveWorkspaceId();
    const data = await apiGet('/api/v1/workspaces/' + ws + '/conflicts');
    clearMountBusy(mount);
    mount.innerHTML = '';

    const lead = document.getElementById('crm-conflicts-lead');
    if (lead) {
      lead.textContent = t('Two people changed the same thing. Pick what to keep, write a short reason, then Resolve.');
    }

    const items = data.items || [];
    if (items.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'crm-empty-state';
      empty.setAttribute('role', 'status');
      empty.innerHTML =
        '<h3 class="crm-empty-state__title">' + escapeHtml(t('No open conflicts')) + '</h3>' +
        '<p class="crm-empty-state__text">' + escapeHtml(t('When two people edit the same field at once, the choice shows up here.')) + '</p>' +
        '<p class="crm-empty-state__actions"><a class="crm-btn crm-btn--primary" href="' +
        escapeHtml(OC.generateUrl('/apps/customercheck/')) + '">' + escapeHtml(t('Back to Today')) + '</a></p>';
      mount.appendChild(empty);
      return;
    }

    function entityLabel(c) {
      const name = String(c.entityLabel || '').trim();
      const type = c.entityType || 'record';
      const typeLabels = {
        company: t('Company'),
        deal: t('Deal'),
        person: t('Person'),
        agreement: t('Agreement'),
      };
      const typeLabel = typeLabels[type] || type;
      if (name !== '') {
        return typeLabel + ': ' + name;
      }
      return typeLabel + ' #' + String(c.entityId || '');
    }

    function fieldLabel(field) {
      const map = {
        name: t('Name'),
        status: t('Status'),
        accountOwnerUserId: t('Owner'),
        kamTier: t('Customer priority'),
        touchIntervalDays: t('Follow-up every (days)'),
        nextTouchOn: t('Next touch'),
        filesFolderId: t('Files folder'),
        title: t('Title'),
        stage: t('Stage'),
        label: t('Label'),
      };
      return map[field] || field;
    }

    items.forEach(function (c) {
      const card = document.createElement('section');
      card.className = 'crm-card';
      card.id = 'crm-conflict-' + String(c.id);
      card.setAttribute('data-crm-conflict-id', String(c.id));
      const title = document.createElement('h3');
      title.className = 'crm-card__title';
      const fields = c.fields || [];
      title.textContent = entityLabel(c) + (fields.length ? (' · ' + fields.map(fieldLabel).join(', ')) : '');
      card.appendChild(title);

      if (c.entityType === 'company' && c.entityId) {
        const openLink = document.createElement('p');
        openLink.className = 'crm-inline-actions';
        openLink.innerHTML = '<a class="crm-btn crm-btn--secondary crm-btn--sm" href="' +
          escapeHtml(OC.generateUrl('/apps/customercheck/companies/' + c.entityId)) + '">' +
          escapeHtml(t('Open company')) + '</a>';
        card.appendChild(openLink);
      }

      const body = document.createElement('div');
      body.className = 'crm-card__body';
      fields.forEach(function (field) {
        const fs = document.createElement('fieldset');
        fs.className = 'crm-form';
        const legend = document.createElement('legend');
        legend.textContent = fieldLabel(field);
        fs.appendChild(legend);
        const base = document.createElement('p');
        base.className = 'crm-muted';
        base.textContent = t('Before both edits') + ': ' + String((c.base && c.base[field]) != null ? c.base[field] : '—');
        fs.appendChild(base);

        const manualWrap = document.createElement('div');
        manualWrap.className = 'crm-conflict-manual';
        manualWrap.hidden = true;

        ['keep_current', 'take_incoming', 'manual'].forEach(function (choice) {
          const id = 'crm-cf-' + c.id + '-' + field + '-' + choice;
          const label = document.createElement('label');
          label.className = 'crm-switch';
          label.setAttribute('for', id);
          const input = document.createElement('input');
          input.type = 'radio';
          input.name = 'crm-cf-' + c.id + '-' + field;
          input.id = id;
          input.value = choice;
          if (choice === 'keep_current') input.checked = true;
          label.appendChild(input);
          const span = document.createElement('span');
          if (choice === 'keep_current') {
            span.textContent = t('Keep what’s saved now') + ': ' + String((c.current && c.current[field]) != null ? c.current[field] : '—');
          } else if (choice === 'take_incoming') {
            span.textContent = t('Take the other edit') + ': ' + String((c.incoming && c.incoming[field]) != null ? c.incoming[field] : '—');
          } else {
            span.textContent = t('Type a different value');
          }
          label.appendChild(span);
          fs.appendChild(label);
          input.addEventListener('change', function () {
            manualWrap.hidden = choice !== 'manual' || !input.checked;
            if (!manualWrap.hidden) {
              const manualInput = manualWrap.querySelector('input');
              if (manualInput) manualInput.focus();
            }
          });
        });

        const manualId = 'crm-cf-manual-' + c.id + '-' + field;
        const manualLabel = document.createElement('label');
        manualLabel.className = 'form-label';
        manualLabel.setAttribute('for', manualId);
        manualLabel.textContent = t('Your value for %s', [fieldLabel(field)]);
        const manual = document.createElement('input');
        manual.type = 'text';
        manual.id = manualId;
        manual.className = 'form-input crm-input';
        manual.setAttribute('data-crm-cf-manual', field);
        manual.autocomplete = 'off';
        manualWrap.appendChild(manualLabel);
        manualWrap.appendChild(manual);
        fs.appendChild(manualWrap);
        body.appendChild(fs);
      });
      const reasonLabel = document.createElement('label');
      reasonLabel.className = 'form-label';
      reasonLabel.setAttribute('for', 'crm-cf-reason-' + c.id);
      reasonLabel.textContent = t('Short reason');
      body.appendChild(reasonLabel);
      const reason = document.createElement('textarea');
      reason.id = 'crm-cf-reason-' + c.id;
      reason.className = 'form-input crm-input';
      reason.rows = 2;
      reason.required = true;
      reason.minLength = 3;
      reason.setAttribute('aria-required', 'true');
      reason.placeholder = t('Why this choice is right…');
      reason.value = t('Kept what was already saved.');
      body.appendChild(reason);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'crm-btn crm-btn--primary';
      btn.textContent = t('Resolve');
      btn.addEventListener('click', async function () {
        const choices = {};
        let allKeep = true;
        fields.forEach(function (field) {
          const picked = body.querySelector('input[name="crm-cf-' + c.id + '-' + field + '"]:checked');
          const choice = picked ? picked.value : 'keep_current';
          if (choice !== 'keep_current') allKeep = false;
          const entry = { choice: choice };
          if (choice === 'manual') {
            const manual = body.querySelector('[data-crm-cf-manual="' + field + '"]');
            entry.value = manual ? manual.value : '';
          }
          choices[field] = entry;
        });
        let reasonText = (reason.value || '').trim();
        if (!reasonText && allKeep) {
          reasonText = t('Kept what was already saved.');
        }
        try {
          await apiPost('/api/v1/workspaces/' + ws + '/conflicts/' + c.id + '/resolve', {
            choices: choices,
            reason: reasonText,
            rowVersion: c.rowVersion || 1,
          });
          await loadConflicts(mount);
        } catch (e) {
          showAlert(card, e.message || String(e));
        }
      });
      body.appendChild(btn);
      card.appendChild(body);
      mount.appendChild(card);
    });

    const focusId = new URLSearchParams(window.location.search).get('conflictId');
    if (focusId && /^\d+$/.test(String(focusId))) {
      const focusCard = mount.querySelector('[data-crm-conflict-id="' + focusId + '"]');
      if (focusCard) {
        focusCard.classList.add('crm-card--focus');
        focusCard.setAttribute('tabindex', '-1');
        try {
          focusCard.focus({ preventScroll: false });
        } catch (e) {
          focusCard.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      }
    }
  }

  function showMountLoadError(mount, err) {
    clearMountBusy(mount);
    const msg = (err && err.message) ? String(err.message) : String(err || t('Something went wrong.'));
    mount.innerHTML = '';
    const callout = document.createElement('div');
    callout.className = 'crm-callout crm-callout--danger';
    callout.setAttribute('role', 'alert');
    const p = document.createElement('p');
    p.textContent = msg;
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'crm-btn crm-btn--primary';
    retry.textContent = t('Try again');
    retry.addEventListener('click', function () {
      mount.innerHTML = '<p class="crm-muted">' + escapeHtml(t('Loading…')) + '</p>';
      mount.classList.add('crm-skeleton');
      mount.setAttribute('aria-busy', 'true');
      boot();
    });
    callout.appendChild(p);
    callout.appendChild(retry);
    mount.appendChild(callout);
  }

  async function boot() {
    const mount = qs('[data-crm-persona-mount]');
    if (!mount) return;
    if (mount.getAttribute('data-crm-defer-boot') === '1') {
      return;
    }
    const kind = mount.getAttribute('data-crm-persona-mount');
    try {
      if (kind === 'pulse') await loadPulse(mount);
      else if (kind === 'brief') await loadBrief(mount);
      else if (kind === 'my-accounts') await loadAccounts(mount);
      else if (kind === 'team') await loadTeam(mount);
      else if (kind === 'strategy') await loadStrategy(mount);
      else if (kind === 'mail-triage') await loadMail(mount);
      else if (kind === 'review') await loadReview(mount);
      else if (kind === 'conflicts') await loadConflicts(mount);
      else {
        clearMountBusy(mount);
        mount.innerHTML = '<p class="crm-muted">' + t('Open a company to continue.') + '</p>';
      }
    } catch (e) {
      showMountLoadError(mount, e);
    }
  }

  window.CustomerCheckReviewBoard = {
    mount: function (el) {
      if (!el) {
        return Promise.resolve();
      }
      el.removeAttribute('data-crm-defer-boot');
      el.setAttribute('aria-busy', 'true');
      if (!el.classList.contains('crm-skeleton')) {
        el.classList.add('crm-skeleton');
      }
      return loadReview(el).catch(function (e) {
        showMountLoadError(el, e);
      });
    },
    abort: function () {
      reviewLoadGeneration += 1;
    },
  };

  document.addEventListener('DOMContentLoaded', function () {
    // Workspace switcher lives in common/navigation.js (all pages).
    boot();
    window.addEventListener('hashchange', focusCapacityIfHash);
  });
})();
