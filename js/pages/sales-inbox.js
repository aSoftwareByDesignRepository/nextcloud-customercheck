/**
 * Inbox — Personal | Team | Unassigned (one chrome: list → read → Reply / Assign).
 */
(function () {
  'use strict';

  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const shellCan = window.CustomerCheckShellCan || function () { return false; };
  const t = (msg, args) => {
    const list = Array.isArray(args) ? args : [];
    let out = window.t ? window.t('customercheck', msg, list) : msg;
    // Nextcloud t() sometimes leaves %s / %1$s — fill locally (same as settings.js / import.js).
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

  if (C && C.init) {
    C.init();
  }

  const root = document.getElementById('crm-sales-inbox-root');
  if (!root || !Api) {
    return;
  }

  /** @type {'mine'|'team'|'unmatched'} */
  let activeTab = 'mine';
  /** Assign source filter for Unassigned (virtual `mail` = team + personal shared unmatched). */
  let assignKind = 'mail';
  /** @type {'sent'|'from'|'subject'} */
  let sortKey = 'sent';
  /** @type {'asc'|'desc'} */
  let sortDir = 'desc';
  /** @type {Array<Record<string, mixed>>} */
  let items = [];
  /** Raw rows before client search/sort (Personal/Team). */
  /** @type {Array<Record<string, mixed>>} */
  let itemsRaw = [];
  /** @type {Array<Record<string, mixed>>} */
  let personalBoxes = [];
  /** @type {Array<Record<string, mixed>>} */
  let accounts = [];
  /** Prevents overlapping Update-mail clicks (race with tab switch). */
  let syncInFlight = false;
  /** @type {Array<string>} */
  let sharedFolders = [];
  let personalMailEnabled = false;
  let canWriteAssign = false;
  let selectedId = 0;
  let mailboxFilter = 0;
  let loadGeneration = 0;
  let previewGeneration = 0;
  let autoSyncedOnce = false;
  let searchTimer = null;
  let companyPanel = null;
  /** @type {Array<{id:number,name:string}>} */
  let composeAttachments = [];
  let composeApi = null;
  /** @type {Record<string, mixed>|null} */
  let composeItem = null;
  /** Last successful preview plain text (never scrape DOM — avoids MIME debris / truncated notes). */
  let lastPreviewPlain = '';
  let composeGeneration = 0;
  let assignBusy = false;
  /** @type {'none'|'assign'|'reply'} */
  let readerMode = 'none';

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function workspaceId() {
    if (Api.activeWorkspaceId) {
      const n = parseInt(String(Api.activeWorkspaceId()), 10);
      if (n > 0) return n;
    }
    const switcher = document.querySelector('[data-crm-active-workspace]');
    if (switcher) {
      const n = parseInt(String(switcher.getAttribute('data-crm-active-workspace') || '0'), 10);
      if (n > 0) return n;
    }
    try {
      const q = parseInt(String(new URL(window.location.href).searchParams.get('workspaceId') || '0'), 10);
      if (q > 0) return q;
    } catch (e) { /* ignore */ }
    return 0;
  }

  function settingsMailboxUrl() {
    const urls = (window.OC && OC.generateUrl)
      ? OC.generateUrl('/apps/customercheck/settings/my-email')
      : '/apps/customercheck/settings/my-email';
    const ws = workspaceId();
    const base = ws > 0 ? (urls + (urls.indexOf('?') >= 0 ? '&' : '?') + 'workspaceId=' + ws) : urls;
    // Bachus: land on My email reconnect strip — no scavenger hunt.
    return base + '#crm-ws-personal-mail-mine';
  }

  function setStatus(msg) {
    const el = document.getElementById('crm-sales-inbox-status');
    if (!el) return;
    const text = msg || '';
    el.textContent = text;
    el.hidden = text === '';
  }

  function setListBusy(busy) {
    const list = document.getElementById('crm-sales-inbox-list');
    const tools = document.getElementById('crm-sales-inbox-list-tools');
    if (list) {
      if (busy) list.setAttribute('aria-busy', 'true');
      else list.removeAttribute('aria-busy');
      list.classList.toggle('is-busy', !!busy);
    }
    if (tools) tools.classList.toggle('is-busy', !!busy);
  }

  function setActionBusy(busy) {
    ['crm-sales-inbox-open-assign', 'crm-sales-inbox-reply', 'crm-sales-inbox-spam', 'crm-sales-inbox-discard', 'crm-sales-inbox-assign-submit', 'crm-sales-inbox-quick-assign', 'crm-sales-inbox-no-relation'].forEach(function (id) {
      const btn = document.getElementById(id);
      if (!btn) return;
      if (busy) {
        btn.setAttribute('aria-busy', 'true');
        if (!btn.disabled) btn.dataset.crmWasEnabled = '1';
        btn.disabled = true;
      } else {
        btn.removeAttribute('aria-busy');
        if (btn.dataset.crmWasEnabled === '1') {
          btn.disabled = false;
          delete btn.dataset.crmWasEnabled;
        }
        // Assign submit still gated by company selection.
        if (id === 'crm-sales-inbox-assign-submit') syncAssignSubmitEnabled();
      }
    });
  }

  function syncActionPressed() {
    const assignBtn = document.getElementById('crm-sales-inbox-open-assign');
    const replyBtn = document.getElementById('crm-sales-inbox-reply');
    if (assignBtn) {
      const on = readerMode === 'assign';
      assignBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      assignBtn.classList.toggle('crm-btn--primary', on);
      assignBtn.classList.toggle('crm-btn--secondary', !on);
    }
    if (replyBtn) {
      const on = readerMode === 'reply';
      replyBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      replyBtn.classList.toggle('crm-btn--primary', on);
      replyBtn.classList.toggle('crm-btn--secondary', !on);
    }
  }

  function syncActionBar(it) {
    const actions = document.getElementById('crm-sales-inbox-actions');
    const assignBtn = document.getElementById('crm-sales-inbox-open-assign');
    const replyBtn = document.getElementById('crm-sales-inbox-reply');
    const spamBtn = document.getElementById('crm-sales-inbox-spam');
    const discardBtn = document.getElementById('crm-sales-inbox-discard');
    if (!actions) return;
    const unmatched = activeTab === 'unmatched';
    const hasRow = !!(it && parseInt(String(it.id || 0), 10) > 0);
    const hasReview = !!(it && parseInt(String(it.reviewItemId || 0), 10) > 0);
    const kind = it ? String(it.sourceKind || '') : '';
    const canMutate = unmatched ? canWriteAssign : !!shellCan('write');
    const M = window.CustomerCheckInboxAssignMap;
    const vis = (M && typeof M.mutateActionVisibility === 'function')
      ? M.mutateActionVisibility({
        tab: activeTab,
        hasRow: hasRow,
        hasReview: hasReview,
        canWrite: canMutate,
        sourceKind: kind,
      })
      : {
        discard: unmatched && hasReview && canMutate,
        spam: unmatched && hasReview && canMutate && kind === 'shared_mail',
        mode: unmatched ? 'assign' : 'none',
        discardIsMail: kind === 'shared_mail',
      };
    if (assignBtn) assignBtn.hidden = !(unmatched && hasReview);
    if (replyBtn) replyBtn.hidden = !hasRow;
    if (spamBtn) {
      spamBtn.hidden = !vis.spam;
      if (vis.spam) {
        spamBtn.title = unmatched
          ? t('Moves this message into the server Spam/Junk folder and leaves Assign.')
          : t('Moves this message into the Spam/Junk folder on the mail server.');
        spamBtn.setAttribute('data-crm-mutate-mode', vis.mode);
      }
    }
    if (discardBtn) {
      discardBtn.hidden = !vis.discard;
      if (vis.discard) {
        const isMail = !!vis.discardIsMail;
        discardBtn.textContent = isMail ? t('Delete forever') : t('Remove from Assign');
        if (vis.mode === 'inbox' && activeTab === 'mine') {
          discardBtn.title = t('Deletes this message forever from your mailbox.');
        } else if (isMail) {
          discardBtn.title = t('Deletes this message forever from the shared mailbox.');
        } else {
          discardBtn.title = t('Removes this item from Assign.');
        }
        discardBtn.setAttribute('data-crm-discard-mail', isMail ? '1' : '0');
        discardBtn.setAttribute('data-crm-mutate-mode', vis.mode);
      }
    }
    actions.hidden = !(hasRow && (unmatched ? hasReview : true));
    syncActionPressed();
  }

  function closeComposeQuiet() {
    composeGeneration += 1;
    const host = document.getElementById('crm-sales-compose-host');
    if (host) {
      host.hidden = true;
      host.removeAttribute('data-crm-quote');
    }
    if (composeApi && composeApi.destroy) composeApi.destroy();
    composeApi = null;
    composeAttachments = [];
    renderComposeAttachments();
  }

  function openAssignPanel() {
    const it = selectedItem();
    if (!it || !(parseInt(String(it.reviewItemId || 0), 10) > 0)) return;
    closeComposeQuiet();
    readerMode = 'assign';
    updateDecideZone(it);
    syncActionPressed();
    setStatus(t('Choose a company to assign.'));
    const decide = document.getElementById('crm-sales-inbox-decide');
    if (decide && typeof decide.scrollIntoView === 'function') {
      decide.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }

  function emptyCopy(tab) {
    if (tab === 'mine') {
      if (!personalMailEnabled) {
        return {
          title: t('Personal email is off'),
          text: t('Ask a manager to turn it on, then connect your inbox here.'),
          cta: null,
        };
      }
      if (!personalBoxes.length) {
        return {
          title: t('Connect your email'),
          text: t('One step — then your mail shows up here.'),
          cta: t('Connect my email'),
        };
      }
      return {
        title: t('No mail yet'),
        text: t('We update mail automatically. You can also tap Update mail.'),
        cta: null,
      };
    }
    if (tab === 'team') {
      return {
        title: t('No team mail yet'),
        text: t('Shared team mailboxes show up here.'),
        cta: null,
      };
    }
    return {
      title: t('Nothing unassigned'),
      text: t('When shared mail needs a company, it shows up here. Tap a message to read it, reply, or assign.'),
      cta: null,
    };
  }

  function isMailSourceKind(code) {
    const M = window.CustomerCheckInboxAssignMap;
    return M ? M.isMailSourceKind(code) : (code === 'shared_mail' || code === 'personal_mail_shared_unmatched');
  }

  function normalizeAssignKind(raw) {
    const M = window.CustomerCheckInboxAssignMap;
    if (M && typeof M.normalizeAssignKind === 'function') {
      return M.normalizeAssignKind(raw);
    }
    const k = String(raw || '').trim();
    if (k === 'shared_mail' || k === 'personal_mail_shared_unmatched') {
      return 'mail';
    }
    return k || 'mail';
  }

  /**
   * Map Assign API row → Inbox list row.
   * Preview/Reply use mailMsgId; Assign writes use reviewItemId.
   */
  function mapReviewItemToRow(item) {
    const M = window.CustomerCheckInboxAssignMap;
    if (M && typeof M.mapReviewItemToRow === 'function') {
      return M.mapReviewItemToRow(item, {
        personalShared: t('Personal shared'),
        teamMail: t('Team mail'),
      });
    }
    const reviewItemId = parseInt(String(item && item.id || 0), 10);
    const ref = (item && item.sourceRef && typeof item.sourceRef === 'object') ? item.sourceRef : {};
    const mailMsgId = parseInt(String(ref.mailMsgId || 0), 10);
    return {
      id: mailMsgId > 0 ? mailMsgId : 0,
      reviewItemId: reviewItemId,
      from: String(ref.from || '').trim(),
      subject: String(item && item.subject || ''),
      snippet: String(item && item.snippet || ''),
      sentAt: item && item.sentAt ? item.sentAt : (item && item.createdAt ? item.createdAt : null),
      sourceKind: String(item && item.sourceKind || ''),
      suggestions: Array.isArray(item && item.suggestions) ? item.suggestions : [],
      redacted: false,
      private: false,
      visibility: 'shared',
      mailboxLabel: '',
      hasMail: mailMsgId > 0 && isMailSourceKind(String(item && item.sourceKind || '')),
    };
  }

  function selectedItem() {
    return items.find(function (row) {
      return parseInt(String(row.id || 0), 10) === selectedId;
    }) || null;
  }

  function syncAssignSubmitEnabled() {
    const hid = document.getElementById('crm-sales-inbox-company-id');
    const submit = document.getElementById('crm-sales-inbox-assign-submit');
    if (!submit) return;
    const cid = hid ? parseInt(String(hid.value || '0'), 10) : 0;
    submit.disabled = !(cid > 0);
  }

  function destroyCompanyPanel() {
    if (companyPanel && typeof companyPanel.destroy === 'function') {
      companyPanel.destroy();
    }
    companyPanel = null;
  }

  function clearAltSuggests() {
    const alt = document.getElementById('crm-sales-inbox-alt-suggests');
    if (!alt) return;
    alt.innerHTML = '';
    alt.hidden = true;
  }

  function paintAltSuggests(suggestions, topId) {
    const alt = document.getElementById('crm-sales-inbox-alt-suggests');
    if (!alt) return;
    clearAltSuggests();
    const rest = (Array.isArray(suggestions) ? suggestions : []).filter(function (s) {
      const id = parseInt(String(s && s.companyId || 0), 10);
      return id > 0 && id !== topId;
    }).slice(0, 3);
    if (!rest.length) return;
    const kicker = document.createElement('p');
    kicker.className = 'crm-sales-inbox__alt-kicker';
    kicker.textContent = t('Other matches');
    alt.appendChild(kicker);
    const row = document.createElement('div');
    row.className = 'crm-sales-inbox__alt-row';
    rest.forEach(function (s) {
      const id = parseInt(String(s.companyId || 0), 10);
      const name = String(s.companyName || '').trim() || ('#' + id);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'crm-btn crm-btn--secondary crm-sales-inbox__alt-chip';
      btn.textContent = name;
      btn.setAttribute('data-company-id', String(id));
      btn.setAttribute('aria-label', t('Assign to %s', [name]));
      btn.addEventListener('click', function () {
        void assignToCompany(id);
      });
      row.appendChild(btn);
    });
    alt.appendChild(row);
    alt.hidden = false;
  }

  function mountCompanyPanel() {
    destroyCompanyPanel();
    const listEl = document.getElementById('crm-sales-inbox-company-list');
    const filterEl = document.getElementById('crm-sales-inbox-company-filter');
    const hiddenEl = document.getElementById('crm-sales-inbox-company-id');
    if (!listEl || !filterEl || !hiddenEl) return;
    hiddenEl.value = '';
    filterEl.value = '';
    syncAssignSubmitEnabled();
    const Picker = window.CustomerCheckCompanyPicker;
    if (!Picker || typeof Picker.mountListPanel !== 'function') {
      listEl.innerHTML = '';
      const empty = document.createElement('li');
      empty.className = 'crm-company-list__empty';
      empty.textContent = t('Could not load companies');
      listEl.appendChild(empty);
      return;
    }
    companyPanel = Picker.mountListPanel({
      listEl: listEl,
      filterEl: filterEl,
      filterWrapEl: document.getElementById('crm-sales-inbox-company-filter-wrap'),
      browseEl: document.getElementById('crm-sales-inbox-company-browse'),
      choiceEl: document.getElementById('crm-sales-inbox-company-choice'),
      choiceNameEl: document.getElementById('crm-sales-inbox-company-choice-name'),
      changeBtn: document.getElementById('crm-sales-inbox-company-change'),
      hiddenEl: hiddenEl,
      statusEl: document.getElementById('crm-sales-inbox-company-status'),
      api: Api,
      workspaceIdFn: workspaceId,
      t: t,
      browseLimit: 40,
      filterMinCount: 0,
      onSelect: function () {
        syncAssignSubmitEnabled();
      },
      onChangeRequest: function () {
        syncAssignSubmitEnabled();
      },
    });
  }

  function hideDecideZone() {
    const decide = document.getElementById('crm-sales-inbox-decide');
    if (decide) decide.hidden = true;
    const card = document.getElementById('crm-sales-inbox-suggest-card');
    if (card) card.hidden = true;
    const nameEl = document.getElementById('crm-sales-inbox-suggest-name');
    if (nameEl) nameEl.textContent = '';
    const metaEl = document.getElementById('crm-sales-inbox-suggest-meta');
    if (metaEl) {
      metaEl.hidden = true;
      metaEl.textContent = '';
    }
    const quickBtn = document.getElementById('crm-sales-inbox-quick-assign');
    if (quickBtn) {
      quickBtn.textContent = '';
      quickBtn.removeAttribute('data-company-id');
    }
    clearAltSuggests();
    const heading = document.getElementById('crm-sales-inbox-assign-heading');
    if (heading) heading.textContent = t('Or pick another company');
    destroyCompanyPanel();
    const hid = document.getElementById('crm-sales-inbox-company-id');
    const filter = document.getElementById('crm-sales-inbox-company-filter');
    const list = document.getElementById('crm-sales-inbox-company-list');
    if (hid) hid.value = '';
    if (filter) filter.value = '';
    if (list) list.innerHTML = '';
    syncAssignSubmitEnabled();
  }

  function updateDecideZone(it) {
    const decide = document.getElementById('crm-sales-inbox-decide');
    if (!decide) return;
    if (activeTab !== 'unmatched' || !it || !(parseInt(String(it.reviewItemId || 0), 10) > 0)) {
      hideDecideZone();
      return;
    }
    decide.hidden = false;
    const form = document.getElementById('crm-sales-inbox-assign-form');
    const ro = document.getElementById('crm-sales-inbox-decide-ro');
    const card = document.getElementById('crm-sales-inbox-suggest-card');
    const nameEl = document.getElementById('crm-sales-inbox-suggest-name');
    const metaEl = document.getElementById('crm-sales-inbox-suggest-meta');
    const quickBtn = document.getElementById('crm-sales-inbox-quick-assign');
    const heading = document.getElementById('crm-sales-inbox-assign-heading');
    const suggestions = Array.isArray(it.suggestions) ? it.suggestions : [];
    const top = suggestions.length ? suggestions[0] : null;
    const topId = top ? parseInt(String(top.companyId || 0), 10) : 0;
    const topName = top ? String(top.companyName || '') : '';
    const topScore = top ? parseInt(String(top.score || 0), 10) : 0;

    if (!canWriteAssign) {
      if (form) form.hidden = true;
      if (card) card.hidden = true;
      clearAltSuggests();
      destroyCompanyPanel();
      if (ro) ro.hidden = false;
      return;
    }
    if (form) form.hidden = false;
    if (ro) ro.hidden = true;
    mountCompanyPanel();

    if (topId > 0 && topName && card && nameEl && quickBtn) {
      card.hidden = false;
      nameEl.textContent = topName;
      if (metaEl) {
        metaEl.hidden = !(topScore > 0);
        metaEl.textContent = topScore > 0 ? t('%s percent match', [String(topScore)]) : '';
      }
      quickBtn.textContent = t('Assign to %s', [topName]);
      quickBtn.setAttribute('data-company-id', String(topId));
      if (heading) heading.textContent = t('Or pick another company');
      paintAltSuggests(suggestions, topId);
    } else {
      if (card) card.hidden = true;
      if (nameEl) nameEl.textContent = '';
      if (metaEl) {
        metaEl.hidden = true;
        metaEl.textContent = '';
      }
      if (quickBtn) {
        quickBtn.textContent = '';
        quickBtn.removeAttribute('data-company-id');
      }
      clearAltSuggests();
      if (heading) heading.textContent = t('Search for a company');
    }
  }

  function syncToolbarForTab() {
    const filterWrap = document.getElementById('crm-sales-inbox-mail-filters');
    if (filterWrap) {
      filterWrap.hidden = activeTab !== 'unmatched';
    }
    // Re-scope account filter to the active tab (Personal ≠ Team).
    renderAccountSwitcher();
    syncMailFilterButtons();
    syncSortButtons();
    syncButtonVisibility();
  }

  function syncMailFilterButtons() {
    const wrap = document.getElementById('crm-sales-inbox-mail-filters');
    if (!wrap) return;
    const kind = (window.CustomerCheckInboxAssignMap && window.CustomerCheckInboxAssignMap.reviewApiKind)
      ? window.CustomerCheckInboxAssignMap.reviewApiKind(assignKind)
      : (assignKind || 'mail');
    wrap.querySelectorAll('[data-crm-inbox-mail-filter]').forEach(function (btn) {
      const on = btn.getAttribute('data-crm-inbox-mail-filter') === kind;
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.classList.toggle('crm-btn--primary', on);
      btn.classList.toggle('crm-btn--secondary', !on);
    });
  }

  function syncSortButtons() {
    root.querySelectorAll('[data-crm-inbox-sort]').forEach(function (btn) {
      const col = btn.getAttribute('data-crm-inbox-sort') || 'sent';
      const on = col === sortKey;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      const base = col === 'from' ? t('From') : (col === 'subject' ? t('Subject') : t('Date'));
      const arrow = !on ? '' : (sortDir === 'asc' ? ' ↑' : ' ↓');
      const hint = on
        ? (sortDir === 'asc' ? t('Sorted ascending') : t('Sorted descending'))
        : t('Click to sort');
      btn.textContent = base + arrow;
      btn.setAttribute('aria-label', base + ' — ' + hint);
    });
  }

  function setColsVisible(show) {
    const cols = document.getElementById('crm-sales-inbox-cols');
    if (cols) cols.hidden = !show;
  }

  function applyListView() {
    const M = window.CustomerCheckInboxAssignMap;
    const qEl = document.getElementById('crm-sales-inbox-q');
    const q = qEl ? String(qEl.value || '').trim() : '';
    let next = itemsRaw.slice();
    if (activeTab !== 'unmatched' && M && typeof M.matchMailQuery === 'function') {
      next = next.filter(function (row) { return M.matchMailQuery(row, q); });
    }
    if (M && typeof M.sortMailRows === 'function') {
      next = M.sortMailRows(next, sortKey, sortDir);
    }
    items = next;
    const still = items.some(function (row) {
      return parseInt(String(row.id || 0), 10) === selectedId;
    });
    if (!still && selectedId > 0) {
      closeSalesPreview(0, { silent: true });
      return;
    }
    renderList();
    parkSalesReaderUnderSelection();
  }

  function formatWhen(sentAt) {
    if (!sentAt) return '';
    try {
      const d = new Date(String(sentAt).replace(' ', 'T'));
      if (Number.isNaN(d.getTime())) return String(sentAt);
      const now = new Date();
      const locale = (document.documentElement && document.documentElement.lang) || undefined;
      const sameDay = d.toDateString() === now.toDateString();
      if (sameDay) {
        return new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }).format(d);
      }
      const sameYear = d.getFullYear() === now.getFullYear();
      return new Intl.DateTimeFormat(locale, sameYear
        ? { month: 'short', day: 'numeric' }
        : { year: 'numeric', month: 'short', day: 'numeric' }).format(d);
    } catch (e) {
      return String(sentAt);
    }
  }

  /** Collapse the under-row preview (second click / Enter on the open row). */
  function closeSalesPreview(focusMailId, opts) {
    const silent = !!(opts && opts.silent);
    previewGeneration += 1;
    selectedId = 0;
    lastPreviewPlain = '';
    readerMode = 'none';
    hideDecideZone();
    closeComposeQuiet();
    const reader = document.getElementById('crm-sales-inbox-reader');
    if (reader) reader.hidden = true;
    const actions = document.getElementById('crm-sales-inbox-actions');
    if (actions) actions.hidden = true;
    syncActionPressed();
    detachSalesReader();
    renderList();
    if (silent) return;
    window.requestAnimationFrame(function () {
      const list = document.getElementById('crm-sales-inbox-list');
      if (!list) return;
      const focusId = parseInt(String(focusMailId || 0), 10);
      const el = focusId > 0
        ? list.querySelector('[data-crm-mail-id="' + String(focusId) + '"]')
        : list.querySelector('.crm-mail-thread-row');
      if (el instanceof HTMLElement) {
        el.focus();
        if (el.id) list.setAttribute('aria-activedescendant', el.id);
      }
      setStatus(t('Preview closed.'));
    });
  }

  /**
   * Mailboxes the Update-mail control can refresh for the active tab.
   * Personal → personal IMAP; Team → shared/team boxes; Unassigned → none (queue, not a folder).
   * @returns {Array<Record<string, mixed>>}
   */
  function syncableBoxesForActiveTab() {
    const M = window.CustomerCheckInboxAssignMap;
    if (activeTab === 'unmatched') {
      return [];
    }
    if (activeTab === 'mine') {
      if (!personalMailEnabled) {
        return [];
      }
      return (M && typeof M.accountsForInboxTab === 'function')
        ? M.accountsForInboxTab(accounts, 'mine')
        : personalBoxes.slice();
    }
    if (activeTab === 'team') {
      return (M && typeof M.accountsForInboxTab === 'function')
        ? M.accountsForInboxTab(accounts, 'team')
        : accounts.filter(function (a) {
          return String(a.kind || '') !== 'personal' && parseInt(String(a.id || 0), 10) > 0;
        });
    }
    return [];
  }

  function syncButtonVisibility() {
    const btn = document.getElementById('crm-sales-inbox-sync');
    if (!btn) return;
    // Granny UX: same control on Personal and Team. Hidden only on Unassigned
    // (that tab is a decide-queue — sync belongs on Personal/Team).
    const boxes = syncableBoxesForActiveTab();
    const show = boxes.length > 0;
    btn.hidden = !show;
    if (show) {
      btn.title = activeTab === 'team'
        ? t('Fetch new messages from the team inbox')
        : t('Fetch new messages for this tab');
    }
  }

  function renderSharedFoldersBanner() {
    const el = document.getElementById('crm-sales-inbox-shared-folders');
    const disclosure = document.getElementById('crm-sales-inbox-disclosure');
    if (!el) return;
    if (!sharedFolders.length) {
      el.hidden = true;
      el.textContent = '';
      if (disclosure) {
        disclosure.textContent = t('Your mail and the team inbox — one place.');
      }
      return;
    }
    el.hidden = false;
    el.textContent = t('Shared folders: %s', [sharedFolders.join(', ')]);
    if (disclosure) {
      disclosure.textContent = t('Your mail and the team inbox — one place.');
    }
  }

  function renderAuthAlert() {
    const box = document.getElementById('crm-sales-inbox-auth-alert');
    const text = document.getElementById('crm-sales-inbox-auth-alert-text');
    const link = document.getElementById('crm-sales-inbox-reconnect');
    if (!box || !text) return;
    const alerts = Array.isArray(arguments[0]) ? arguments[0] : [];
    if (!alerts.length) {
      box.hidden = true;
      text.textContent = '';
      return;
    }
    const first = alerts[0] || {};
    const label = String(first.label || first.email || t('Your mailbox'));
    text.textContent = t('%s needs a quick reconnect. Tap Reconnect — your synced mail stays.', [label]);
    box.hidden = false;
    if (link) link.href = settingsMailboxUrl();
  }

  function renderAccountSwitcher() {
    const sel = document.getElementById('crm-sales-inbox-account');
    const wrap = document.getElementById('crm-sales-inbox-account-wrap');
    if (!sel) return;
    const M = window.CustomerCheckInboxAssignMap;
    const scoped = (M && typeof M.accountsForInboxTab === 'function')
      ? M.accountsForInboxTab(accounts, activeTab)
      : (activeTab === 'unmatched' ? [] : accounts.slice());
    const show = (M && typeof M.accountFilterVisible === 'function')
      ? M.accountFilterVisible(accounts, activeTab)
      : (activeTab !== 'unmatched' && scoped.length > 1);

    // Drop a filter that belongs to the other tab (e.g. Team box while on Personal).
    if (mailboxFilter > 0 && !scoped.some(function (acc) {
      return parseInt(String(acc.id || 0), 10) === mailboxFilter;
    })) {
      mailboxFilter = 0;
    }

    const prev = String(mailboxFilter || '0');
    sel.textContent = '';
    const all = document.createElement('option');
    all.value = '0';
    // Prefer PHP-baked labels so the first paint stays native even if JS t() lags.
    const allPersonal = sel.getAttribute('data-crm-all-personal') || t('All personal accounts');
    const allTeam = sel.getAttribute('data-crm-all-team') || t('All team accounts');
    all.textContent = activeTab === 'team' ? allTeam : allPersonal;
    sel.appendChild(all);
    scoped.forEach((acc) => {
      const id = parseInt(String(acc.id || 0), 10);
      if (!(id > 0)) return;
      const opt = document.createElement('option');
      opt.value = String(id);
      // No "(Personal)/(Team)" suffix — the tab already says that.
      opt.textContent = String(acc.label || acc.email || '#' + id);
      sel.appendChild(opt);
    });
    sel.value = prev;
    if (sel.value !== prev) {
      mailboxFilter = 0;
      sel.value = '0';
    }
    if (wrap) {
      wrap.hidden = !show;
    }
    // Aria: clarify what the filter does in this tab (PHP data-* first, then t()).
    const ariaPersonal = sel.getAttribute('data-crm-aria-personal') || t('Filter by personal account');
    const ariaTeam = sel.getAttribute('data-crm-aria-team') || t('Filter by team account');
    sel.setAttribute('aria-label', activeTab === 'team' ? ariaTeam : ariaPersonal);
  }

  function syncUrlState() {
    try {
      const url = new URL(window.location.href);
      if (activeTab === 'mine') {
        url.searchParams.delete('tab');
      } else {
        url.searchParams.set('tab', activeTab);
      }
      if (activeTab === 'unmatched') {
        const M = window.CustomerCheckInboxAssignMap;
        const kind = (M && typeof M.reviewApiKind === 'function')
          ? M.reviewApiKind(assignKind)
          : (assignKind || 'mail');
        if (kind) url.searchParams.set('kind', kind);
        else url.searchParams.delete('kind');
      } else {
        url.searchParams.delete('kind');
      }
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    } catch (e) { /* ignore */ }
  }

  function selectTab(tab) {
    activeTab = tab === 'team' || tab === 'unmatched' ? tab : 'mine';
    loadGeneration += 1;
    const tabs = root.querySelectorAll('[data-crm-inbox-tab]');
    tabs.forEach((btn) => {
      const id = btn.getAttribute('data-crm-inbox-tab');
      const on = id === activeTab;
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
      btn.setAttribute('tabindex', on ? '0' : '-1');
      btn.classList.toggle('crm-btn--primary', on);
      btn.classList.toggle('crm-btn--secondary', !on);
      if (on) {
        const panel = document.getElementById('crm-sales-inbox-panel');
        if (panel) panel.setAttribute('aria-labelledby', btn.id);
      }
    });
    syncToolbarForTab();
    syncUrlState();
    closeSalesPreview(0, { silent: true });
    const disclosure = document.getElementById('crm-sales-inbox-disclosure');
    if (activeTab === 'unmatched') {
      if (disclosure) {
        disclosure.textContent = t('Messages that still need a company. Search, filter, sort — then tap a message to read, reply, or assign.');
      }
      void loadAssignInbox();
      return;
    }
    if (disclosure && !sharedFolders.length) {
      disclosure.textContent = t('Your mail, the team inbox, and items that still need a company — one place.');
    }
    void loadInbox();
  }

  function detachSalesReader() {
    const list = document.getElementById('crm-sales-inbox-list');
    const reader = document.getElementById('crm-sales-inbox-reader');
    const host = document.getElementById('crm-sales-inbox-reader-host');
    const thread = list && list.closest
      ? list.closest('.crm-mail-3pane__thread')
      : (list ? list.parentElement : null);
    if (!reader || !thread) return;
    if (host && host.contains(reader)) {
      thread.appendChild(reader);
    } else if (list && list.contains(reader)) {
      thread.appendChild(reader);
    }
    if (host && host.parentNode) {
      host.remove();
    }
  }

  /** Park the preview panel directly under the selected message (company Talk pattern). */
  function parkSalesReaderUnderSelection() {
    const list = document.getElementById('crm-sales-inbox-list');
    const reader = document.getElementById('crm-sales-inbox-reader');
    const thread = list && list.closest
      ? list.closest('.crm-mail-3pane__thread')
      : (list ? list.parentElement : null);
    if (!list || !reader || !thread) return;

    let host = document.getElementById('crm-sales-inbox-reader-host');
    if (!host) {
      host = document.createElement('li');
      host.id = 'crm-sales-inbox-reader-host';
      host.className = 'crm-mail-thread-row crm-mail-thread-row--detail crm-sales-inbox__detail';
      host.setAttribute('role', 'presentation');
    } else {
      host.classList.add('crm-mail-thread-row', 'crm-mail-thread-row--detail', 'crm-sales-inbox__detail');
      host.classList.remove('crm-sales-inbox__row');
    }
    if (!host.contains(reader)) {
      host.appendChild(reader);
    }

    if (reader.hidden || !(selectedId > 0)) {
      if (host.parentNode) host.remove();
      thread.appendChild(reader);
      return;
    }

    const row = list.querySelector('[data-crm-mail-id="' + String(selectedId) + '"]');
    if (row && row.parentNode === list) {
      // Insert directly under the clicked message — full-width detail, not a grid cell.
      row.after(host);
      reader.hidden = false;
      return;
    }
    if (host.parentNode) host.remove();
    thread.appendChild(reader);
  }

  function renderList() {
    const list = document.getElementById('crm-sales-inbox-list');
    const empty = document.getElementById('crm-sales-inbox-empty');
    const emptyText = document.getElementById('crm-sales-inbox-empty-text');
    const reader = document.getElementById('crm-sales-inbox-reader');
    if (!list || !empty) return;

    detachSalesReader();
    list.textContent = '';
    if (!items.length) {
      empty.hidden = false;
      setColsVisible(false);
      const copy = emptyCopy(activeTab);
      const emptyTitle = document.getElementById('crm-sales-inbox-empty-title');
      if (emptyTitle) emptyTitle.textContent = copy.title;
      if (emptyText) emptyText.textContent = copy.text;
      const ctaWrap = document.getElementById('crm-sales-inbox-empty-cta-wrap');
      const cta = document.getElementById('crm-sales-inbox-empty-cta');
      if (ctaWrap && cta) {
        if (copy.cta) {
          ctaWrap.hidden = false;
          cta.textContent = copy.cta;
          cta.href = settingsMailboxUrl();
        } else {
          ctaWrap.hidden = true;
        }
      }
      if (reader) reader.hidden = true;
      selectedId = 0;
      parkSalesReaderUnderSelection();
      return;
    }
    empty.hidden = true;
    setColsVisible(true);

    items.forEach((it, idx) => {
      const id = parseInt(String(it.id || 0), 10);
      const li = document.createElement('li');
      li.className = 'crm-mail-thread-row crm-sales-inbox__row';
      li.id = 'crm-sales-mail-' + String(id);
      li.setAttribute('role', 'option');
      // Roving tabindex: only selected (or first) row is tab-stop.
      const focusable = id === selectedId || (selectedId === 0 && idx === 0);
      li.setAttribute('tabindex', focusable ? '0' : '-1');
      li.setAttribute('data-crm-mail-id', String(id));
      li.setAttribute('aria-selected', id === selectedId ? 'true' : 'false');
      if (id === selectedId) {
        li.classList.add('is-selected');
      }

      const redacted = !!it.redacted;
      const from = String(it.from || '');
      const subject = String(it.subject || t('(No subject)'));
      const snippetRaw = String(it.snippet || '').trim();
      const snippetUseful = snippetRaw !== ''
        && snippetRaw.toLowerCase() !== subject.trim().toLowerCase();
      const label = String(it.mailboxLabel || it.mailboxEmail || '');
      const isPrivate = !!it.private || String(it.visibility || '') === 'private';
      const whenLabel = formatWhen(it.sentAt);
      const whenRaw = String(it.sentAt || '');
      const open = id === selectedId && selectedId > 0;

      let badges = '';
      if (redacted) {
        badges += '<span class="crm-badge crm-badge--warning">' + escapeHtml(t('Needs triage')) + '</span>';
      } else if (activeTab === 'unmatched') {
        badges += '<span class="crm-badge crm-badge--warning">' + escapeHtml(t('Needs company')) + '</span>';
      } else if (isPrivate) {
        badges += '<span class="crm-badge crm-badge--neutral crm-sales-inbox__private" title="' +
          escapeHtml(t('Only you can see this message')) + '">' + escapeHtml(t('Only you')) + '</span>';
      } else if (String(it.visibility || '') === 'shared') {
        badges += '<span class="crm-badge crm-badge--info">' + escapeHtml(t('Shared with workspace')) + '</span>';
      }

      const accountBits = [];
      const M = window.CustomerCheckInboxAssignMap;
      const multiAccount = (M && typeof M.accountFilterVisible === 'function')
        ? M.accountFilterVisible(accounts, activeTab)
        : false;
      // Only name the mailbox when this tab has more than one — otherwise the tab is enough.
      if (multiAccount && label) {
        accountBits.push('<span class="crm-muted crm-mail-thread-row__account">' + escapeHtml(label) + '</span>');
      }
      if (badges) accountBits.push(badges);

      const fromText = from || (redacted ? t('Hidden until triage') : '');
      li.setAttribute('aria-expanded', open ? 'true' : 'false');
      li.setAttribute('aria-label', [
        fromText,
        subject,
        label,
        whenLabel,
      ].filter(Boolean).join(' — '));
      li.innerHTML =
        '<span class="crm-sales-inbox__cell crm-sales-inbox__cell--from" data-crm-col="' + escapeHtml(t('From')) + '">' +
        '<span class="crm-mail-thread-row__from">' + escapeHtml(fromText) + '</span></span>' +
        '<span class="crm-sales-inbox__cell crm-sales-inbox__cell--subject" data-crm-col="' + escapeHtml(t('Subject')) + '">' +
        '<span class="crm-mail-thread-row__subject">' + escapeHtml(subject) + '</span>' +
        (snippetUseful
          ? ('<span class="crm-muted crm-mail-thread-row__snippet">' + escapeHtml(snippetRaw) + '</span>')
          : '') +
        '</span>' +
        '<span class="crm-sales-inbox__cell crm-sales-inbox__cell--account" data-crm-col="' + escapeHtml(t('Account')) + '">' +
        (accountBits.length ? accountBits.join(' ') : '<span class="crm-muted">—</span>') +
        '</span>' +
        '<span class="crm-sales-inbox__cell crm-sales-inbox__cell--when" data-crm-col="' + escapeHtml(t('Date')) + '">' +
        (whenLabel
          ? ('<time class="crm-mail-thread-row__when" datetime="' + escapeHtml(whenRaw) + '">' +
            escapeHtml(whenLabel) + '</time>')
          : '<span class="crm-mail-thread-row__when crm-muted" aria-hidden="true">—</span>') +
        '</span>';

      const toggleOrOpen = () => {
        // Second click / Enter on the open row collapses the reader (company Talk pattern).
        if (id === selectedId && selectedId > 0) {
          closeSalesPreview(id);
          return;
        }
        void openPreview(it);
      };
      li.addEventListener('click', toggleOrOpen);
      li.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ') {
          ev.preventDefault();
          toggleOrOpen();
          return;
        }
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
          ev.preventDefault();
          const delta = ev.key === 'ArrowDown' ? 1 : -1;
          const nextIdx = Math.max(0, Math.min(items.length - 1, idx + delta));
          const next = items[nextIdx];
          if (!next) return;
          void openPreview(next);
          window.requestAnimationFrame(() => {
            const nextId = parseInt(String(next.id || 0), 10);
            const nextEl = list.querySelector('[data-crm-mail-id="' + String(nextId) + '"]');
            if (nextEl instanceof HTMLElement) {
              nextEl.focus();
              list.setAttribute('aria-activedescendant', nextEl.id);
            }
          });
          return;
        }
        if (ev.key === 'Home' || ev.key === 'End') {
          ev.preventDefault();
          const target = ev.key === 'Home' ? items[0] : items[items.length - 1];
          if (!target) return;
          void openPreview(target);
          window.requestAnimationFrame(() => {
            const tid = parseInt(String(target.id || 0), 10);
            const el = list.querySelector('[data-crm-mail-id="' + String(tid) + '"]');
            if (el instanceof HTMLElement) {
              el.focus();
              list.setAttribute('aria-activedescendant', el.id);
            }
          });
        }
      });
      list.appendChild(li);
    });
    parkSalesReaderUnderSelection();
    const active = list.querySelector('[aria-selected="true"]');
    if (active instanceof HTMLElement && active.id) {
      list.setAttribute('aria-activedescendant', active.id);
    } else {
      list.setAttribute('aria-activedescendant', '');
    }
  }

  async function openPreview(it) {
    const id = parseInt(String(it.id || 0), 10);
    selectedId = id;
    const gen = ++previewGeneration;
    // Rebuild list with selection, then reveal + park under the row (order matters).
    renderList();

    const reader = document.getElementById('crm-sales-inbox-reader');
    const wrap = document.getElementById('crm-sales-inbox-preview');
    const fromEl = document.getElementById('crm-sales-preview-from');
    const subjEl = document.getElementById('crm-sales-preview-subject');
    const metaEl = document.getElementById('crm-sales-preview-meta');
    const bodyEl = document.getElementById('crm-sales-preview-body');
    const hint = document.getElementById('crm-sales-preview-hint');
    if (!reader || !wrap || !bodyEl) return;

    reader.hidden = false;
    parkSalesReaderUnderSelection();
    // Re-assert host sits after the selected row (renderList may have raced).
    const list = document.getElementById('crm-sales-inbox-list');
    const host = document.getElementById('crm-sales-inbox-reader-host');
    const row = list && list.querySelector('[data-crm-mail-id="' + String(id) + '"]');
    if (list && host && row && row.parentNode === list && row.nextElementSibling !== host) {
      row.after(host);
    }
    window.requestAnimationFrame(() => {
      const host = document.getElementById('crm-sales-inbox-reader-host');
      if (host && typeof host.scrollIntoView === 'function') {
        host.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      } else if (typeof reader.scrollIntoView === 'function') {
        reader.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    });
    wrap.setAttribute('data-crm-mail-preview', String(id));
    lastPreviewPlain = '';
    if (fromEl) fromEl.textContent = String(it.from || '');
    if (subjEl) subjEl.textContent = String(it.subject || t('(No subject)'));
    if (metaEl) {
      const bits = [];
      if (it.mailboxLabel || it.mailboxEmail) bits.push(String(it.mailboxLabel || it.mailboxEmail));
      if (it.private) bits.push(t('Only you'));
      bits.push(formatWhen(it.sentAt));
      metaEl.textContent = bits.filter(Boolean).join(' · ');
    }
    if (hint) hint.hidden = true;
    bodyEl.hidden = false;
    bodyEl.classList.add('crm-mail-preview__body--pending');
    bodyEl.textContent = t('Loading message…');
    bodyEl.removeAttribute('data-loaded');
    bodyEl.setAttribute('role', 'article');
    const actions = document.getElementById('crm-sales-inbox-actions');
    readerMode = 'none';
    closeComposeQuiet();
    hideDecideZone();
    if (actions) actions.hidden = true;
    syncActionPressed();
    setStatus(t('Loading message…'));

    const ws = workspaceId();
    if (!(ws > 0) || id < 1) {
      bodyEl.textContent = t('Cannot load preview.');
      setStatus(t('Cannot load preview.'));
      return;
    }
    try {
      const res = await Api.post('/workspaces/' + ws + '/mail/' + id + '/preview', {});
      if (gen !== previewGeneration || selectedId !== id) return;
      const data = (res && res.data) ? res.data : res;
      const bodyHtml = data && data.bodyHtml ? String(data.bodyHtml) : '';
      const bodyText = (data && data.preview) || '';
      lastPreviewPlain = String(bodyText || '').trim();
      // Prefer sanitized HTML → clean plain for reply quotes (avoids &gt; / tag residue).
      if (bodyHtml !== '') {
        const fromHtml = htmlToPlainClient(bodyHtml);
        if (fromHtml !== '') {
          lastPreviewPlain = fromHtml;
        }
      } else {
        lastPreviewPlain = cleanQuoteSource(lastPreviewPlain);
      }
      // FAC-16: only render server-sanitized HTML; never trust client-side markup.
      if (bodyHtml !== '') {
        bodyEl.innerHTML = bodyHtml;
        bodyEl.classList.add('crm-mail-preview__body--html');
        bodyEl.classList.remove('crm-mail-preview__body--plain');
      } else {
        bodyEl.classList.remove('crm-mail-preview__body--html');
        bodyEl.classList.add('crm-mail-preview__body--plain');
        bodyEl.textContent = bodyText !== ''
          ? bodyText
          : t('No message text could be loaded.');
      }
      if (data && data.truncated) {
        const note = document.createElement('p');
        note.className = 'crm-muted';
        note.textContent = t('(Preview truncated)');
        bodyEl.appendChild(note);
      }
      bodyEl.setAttribute('data-loaded', '1');
      bodyEl.setAttribute('role', 'article');
      bodyEl.classList.remove('crm-mail-preview__body--pending');
      syncActionBar(it);
      setStatus(activeTab === 'unmatched'
        ? t('Message loaded. Assign it to a company, or reply.')
        : t('Message loaded.'));
      try {
        bodyEl.focus({ preventScroll: true });
      } catch (e) { /* ignore */ }
    } catch (e) {
      if (gen !== previewGeneration || selectedId !== id) return;
      bodyEl.textContent = (e.payload && e.payload.message) || e.message || t('Preview failed.');
      bodyEl.setAttribute('role', 'alert');
      bodyEl.classList.remove('crm-mail-preview__body--pending');
      // Still offer Assign when preview fails (AS: triage must not depend on body fetch).
      syncActionBar(it);
      setStatus((e.payload && e.payload.message) || e.message || t('Preview failed.'));
    }
  }

  function cleanQuoteSource(body) {
    let s = String(body || '');
    s = s.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    // HTML residue → newlines / plain text (preview can leak entities or tags).
    s = s.replace(/&nbsp;/gi, ' ');
    s = s.replace(/&#160;/gi, ' ');
    s = s.replace(/<br\s*\/?\s*>/gi, '\n');
    s = s.replace(/<\/\s*p\s*>/gi, '\n');
    s = s.replace(/<\s*p\b[^>]*>/gi, '');
    s = s.replace(/<\/?\s*[a-z][^>]*>/gi, '');
    s = s.replace(/&gt;/gi, '>').replace(/&lt;/gi, '<').replace(/&quot;/gi, '"').replace(/&#39;/g, "'").replace(/&apos;/gi, "'");
    s = s.replace(/&#(\d+);/g, function (_, n) {
      const code = parseInt(n, 10);
      return Number.isFinite(code) ? String.fromCharCode(code) : '';
    });
    s = s.replace(/&#x([0-9a-f]+);/gi, function (_, h) {
      const code = parseInt(h, 16);
      return Number.isFinite(code) ? String.fromCharCode(code) : '';
    });
    s = s.replace(/&amp;/gi, '&');
    s = s.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
    return s.split('\n').map(function (line) {
      return String(line || '').replace(/^(?:>\s*)+/, '').replace(/^(&gt;\s*)+/i, '').trimEnd();
    }).join('\n').trim();
  }

  function htmlToPlainClient(html) {
    const raw = String(html || '').trim();
    if (!raw) return '';
    try {
      const wrap = document.createElement('div');
      wrap.innerHTML = raw;
      wrap.querySelectorAll('br').forEach(function (br) {
        br.replaceWith(document.createTextNode('\n'));
      });
      wrap.querySelectorAll('p,div,li,h1,h2,h3,h4,blockquote,tr').forEach(function (el) {
        el.appendChild(document.createTextNode('\n'));
      });
      return cleanQuoteSource(wrap.textContent || '');
    } catch (e) {
      return cleanQuoteSource(raw);
    }
  }

  function quotePlain(from, sentAt, body) {
    const when = formatWhen(sentAt) || '';
    const who = String(from || '').trim();
    const header = t('On %s, %s wrote:', [when, who]);
    // Never leave literal %s in outbound mail if l10n lookup fails.
    const safeHeader = (header.indexOf('%s') !== -1)
      ? ('On ' + when + ', ' + who + ' wrote:')
      : header;
    const cleaned = cleanQuoteSource(body)
      // Client-side belt-and-suspenders: strip leaked MIME tails if any slip through.
      .replace(/\n(?:>\s*)?--[A-Za-z0-9'()+_,\-./:=?]{6,}\s*\n(?:>\s*)?Content-(?:Type|Transfer-Encoding|Disposition)\s*:[\s\S]*$/i, '')
      .replace(/\n(?:>\s*)?Content-Type\s*:\s*multipart\/[\s\S]*$/i, '')
      .trim();
    const lines = cleaned.split('\n').map(function (line) {
      return '> ' + line;
    });
    return safeHeader + '\n' + lines.join('\n');
  }

  function renderComposeAttachments() {
    const list = document.getElementById('crm-sales-compose-attach-list');
    const addBtn = document.getElementById('crm-sales-compose-attach-add');
    const countEl = document.getElementById('crm-sales-compose-attach-count');
    if (addBtn) {
      addBtn.textContent = composeAttachments.length
        ? t('Add another file')
        : t('Add file');
      addBtn.disabled = composeAttachments.length >= 5;
    }
    if (countEl) {
      const n = composeAttachments.length;
      countEl.textContent = n === 0
        ? t('No files yet — tap Add file to attach.')
        : (n === 1 ? t('1 file attached (max 5)') : t('%s files attached (max 5)', [String(n)]));
      countEl.hidden = false;
    }
    if (!list) return;
    if (!composeAttachments.length) {
      list.innerHTML = '';
      list.hidden = true;
      return;
    }
    list.hidden = false;
    list.innerHTML = composeAttachments.map(function (a, idx) {
      return '<li class="crm-sales-compose__attach-item" role="listitem">' +
        '<span class="crm-sales-compose__attach-name" title="' + escapeHtml(a.name || ('file ' + a.id)) + '">' +
        escapeHtml(a.name || ('file ' + a.id)) + '</span>' +
        '<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-sales-detach="' + idx + '">' +
        escapeHtml(t('Remove')) + '</button></li>';
    }).join('');
    list.querySelectorAll('[data-crm-sales-detach]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const i = parseInt(String(btn.getAttribute('data-crm-sales-detach') || '-1'), 10);
        if (i >= 0) {
          composeAttachments.splice(i, 1);
          renderComposeAttachments();
          setStatus(t('Attachment removed.'));
        }
      });
    });
  }

  async function openCompose(it, bodyText) {
    const host = document.getElementById('crm-sales-compose-host');
    const editorHost = document.getElementById('crm-sales-compose-editor');
    const toEl = document.getElementById('crm-sales-compose-to');
    const subjEl = document.getElementById('crm-sales-compose-subject');
    if (!host || !editorHost || !toEl || !subjEl) return;
    const gen = ++composeGeneration;
    hideDecideZone();
    readerMode = 'reply';
    syncActionPressed();
    syncActionBar(it);
    composeItem = it;
    composeAttachments = [];
    renderComposeAttachments();
    const from = String(it.from || '');
    const subj = String(it.subject || '');
    toEl.value = from;
    const ccEl = document.getElementById('crm-sales-compose-cc');
    if (ccEl) ccEl.value = '';
    const ccWrap = document.getElementById('crm-sales-compose-cc-wrap');
    const ccToggle = document.getElementById('crm-sales-compose-cc-toggle');
    if (ccWrap) ccWrap.hidden = true;
    if (ccToggle) {
      ccToggle.setAttribute('aria-expanded', 'false');
      ccToggle.textContent = t('Add Cc');
    }
    subjEl.value = /^re:/i.test(subj) ? subj : ('Re: ' + subj);
    host.hidden = false;
    host.removeAttribute('data-crm-quote');
    if (composeApi && composeApi.destroy) composeApi.destroy();
    composeApi = null;
    setStatus(t('Opening reply…'));
    const Rich = window.CustomerCheckRichMailComposer;
    const quoted = quotePlain(from, it.sentAt, bodyText);
    let hasSig = false;
    try {
      const ws = workspaceId();
      if (ws > 0) {
        const res = await Api.get('/workspaces/' + ws + '/mail/send-accounts');
        if (gen !== composeGeneration) return;
        const items = (res && res.data && res.data.items) || (res && res.data) || [];
        if (Array.isArray(items) && items.length) {
          const M = window.CustomerCheckInboxAssignMap;
          const preferred = (M && typeof M.pickComposeSendAccount === 'function')
            ? M.pickComposeSendAccount(items, it, activeTab)
            : (items.find(function (b) {
              return it.mailboxId && parseInt(String(b.id || 0), 10) === parseInt(String(it.mailboxId), 10);
            }) || items[0]);
          hasSig = !!(preferred && preferred.hasSignature);
        }
      }
    } catch (e) { /* keep default */ }
    if (gen !== composeGeneration) return;
    composeApi = Rich && Rich.mount
      ? Rich.mount(editorHost, {
        labelledBy: 'crm-sales-compose-body-label',
        hasSignature: hasSig,
        // Always default ON when a signature exists for the reply mailbox.
        includeSignatureDefault: true,
        initialPlain: '',
        quotedPlain: quoted,
      })
      : null;
    setStatus(t('Write your reply, then send.'));
    // US-010 AC-3: focus the first compose field (To), not Send — keyboard flow starts at the top.
    const toFocus = document.getElementById('crm-sales-compose-to');
    if (toFocus) {
      try {
        toFocus.focus();
      } catch (e) { /* ignore */ }
    }
    if (host && typeof host.scrollIntoView === 'function') {
      host.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }

  async function sendCompose() {
    const Send = window.CustomerCheckSharedMail || window.CustomerCheckSharedMailSend;
    const toEl = document.getElementById('crm-sales-compose-to');
    const ccEl = document.getElementById('crm-sales-compose-cc');
    const subjEl = document.getElementById('crm-sales-compose-subject');
    const host = document.getElementById('crm-sales-compose-host');
    if (!Send || !Send.sendViaSharedMailbox || !toEl || !subjEl || !composeApi) return;
    const quoted = composeApi.getQuotedPlain
      ? String(composeApi.getQuotedPlain() || '').trim()
      : '';
    const quotedHtml = composeApi.getQuotedHtml
      ? String(composeApi.getQuotedHtml() || '').trim()
      : '';
    const bodyHtml = composeApi.getHtml();
    const bodyText = composeApi.getPlain();
    const includeSignature = composeApi.includeSignature();
    const attachmentFileIds = composeAttachments.map(function (a) { return a.id; });
    const ccRaw = ccEl ? String(ccEl.value || '') : '';
    const cc = ccRaw.split(/[,;]+/).map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    const ok = await Send.sendViaSharedMailbox(
      {
        to: toEl.value,
        cc: cc,
        subject: subjEl.value,
        bodyText: bodyText,
        bodyHtml: bodyHtml,
        quotedBody: quoted,
        quotedHtml: quotedHtml,
      },
      {
        includeSignature: includeSignature,
        attachmentFileIds: attachmentFileIds,
        companyId: composeItem && composeItem.companyId ? parseInt(String(composeItem.companyId), 10) : 0,
        mailboxId: composeItem && composeItem.mailboxId ? parseInt(String(composeItem.mailboxId), 10) : 0,
      },
    );
    if (ok) {
      host.hidden = true;
      composeGeneration += 1;
      if (composeApi.destroy) composeApi.destroy();
      composeApi = null;
      composeAttachments = [];
      renderComposeAttachments();
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Reply sent.');
    }
  }

  async function loadPersonalMeta() {
    const ws = workspaceId();
    if (!(ws > 0)) return;
    try {
      const res = await Api.get('/workspaces/' + ws + '/personal-mailboxes');
      const data = res.data || {};
      personalMailEnabled = !!data.personalMailEnabled;
      personalBoxes = Array.isArray(data.items) ? data.items : [];
    } catch (e) {
      personalMailEnabled = false;
      personalBoxes = [];
    }
    syncButtonVisibility();
  }

  async function loadAssignInbox() {
    const ws = workspaceId();
    const gen = ++loadGeneration;
    if (!(ws > 0)) {
      setStatus(t('No workspace selected.'));
      itemsRaw = [];
      items = [];
      canWriteAssign = false;
      renderList();
      return;
    }
    setListBusy(true);
    setStatus(t('Loading unassigned mail…'));
    try {
      const qEl = document.getElementById('crm-sales-inbox-q');
      const q = qEl ? String(qEl.value || '').trim() : '';
      const M = window.CustomerCheckInboxAssignMap;
      const kind = (M && typeof M.reviewApiKind === 'function')
        ? M.reviewApiKind(assignKind)
        : (assignKind || 'mail');
      let url = '/workspaces/' + ws + '/review/items?kind=' + encodeURIComponent(kind);
      if (q !== '') {
        url += '&q=' + encodeURIComponent(q);
      }
      const res = await Api.get(url);
      if (gen !== loadGeneration || activeTab !== 'unmatched') return;
      const data = res.data || {};
      const raw = Array.isArray(data.items) ? data.items : [];
      canWriteAssign = data.canWrite !== false && !!shellCan('write');
      itemsRaw = raw
        .map(mapReviewItemToRow)
        .filter(function (row) {
          if (M && typeof M.isUnifiedMailAssignRow === 'function') {
            return M.isUnifiedMailAssignRow(row);
          }
          return row.hasMail && row.id > 0 && row.reviewItemId > 0;
        });
      selectedId = 0;
      readerMode = 'none';
      hideDecideZone();
      closeComposeQuiet();
      const reader = document.getElementById('crm-sales-inbox-reader');
      if (reader) reader.hidden = true;
      const actions = document.getElementById('crm-sales-inbox-actions');
      if (actions) actions.hidden = true;
      applyListView();
      const n = items.length;
      setStatus(n === 0
        ? t('Nothing unassigned')
        : (n === 1 ? t('1 message') : t('%s messages', [String(n)])));
      syncToolbarForTab();
    } catch (e) {
      if (gen !== loadGeneration || activeTab !== 'unmatched') return;
      setStatus((e.payload && e.payload.message) || e.message || t('Could not load unassigned mail.'));
      itemsRaw = [];
      items = [];
      canWriteAssign = false;
      renderList();
    } finally {
      if (gen === loadGeneration) setListBusy(false);
    }
  }

  async function postAssignAction(pathSuffix, body) {
    const it = selectedItem();
    const rid = it ? parseInt(String(it.reviewItemId || 0), 10) : 0;
    const ws = workspaceId();
    if (!(ws > 0) || !(rid > 0) || assignBusy) return false;
    if (!canWriteAssign) {
      setStatus(t('View only — ask a member to assign this.'));
      return false;
    }
    assignBusy = true;
    setActionBusy(true);
    setStatus(t('Saving…'));
    try {
      await Api.post('/workspaces/' + ws + '/review/items/' + rid + '/' + pathSuffix, body || {});
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Saved.');
      setStatus(t('Saved. Updating list…'));
      await loadAssignInbox();
      return true;
    } catch (e) {
      const msg = (e.payload && e.payload.message) || e.message || t('Could not save.');
      setStatus(msg);
      if (C && C.alertDialog) {
        await C.alertDialog({
          title: t('Could not save'),
          message: msg,
          variant: 'danger',
        });
      }
      // Concurrent triage: peer may have won — refresh so the row does not stay assignable.
      if (/handled this message|not open for triage|Reload Assign|already/i.test(msg)) {
        try { await loadAssignInbox(); } catch (reloadErr) { /* keep alert */ }
      }
      return false;
    } finally {
      assignBusy = false;
      setActionBusy(false);
    }
  }

  async function assignToCompany(companyId) {
    const cid = parseInt(String(companyId || 0), 10);
    if (!(cid > 0)) {
      setStatus(t('Pick a company from the search results first.'));
      return false;
    }
    return postAssignAction('assign', { companyId: cid });
  }

  async function loadInbox() {
    if (activeTab === 'unmatched') {
      return loadAssignInbox();
    }
    const ws = workspaceId();
    const gen = ++loadGeneration;
    if (!(ws > 0)) {
      setStatus(t('No workspace selected.'));
      items = [];
      renderList();
      return;
    }
    setListBusy(true);
    setStatus(t('Loading inbox…'));
    try {
      let url = '/workspaces/' + ws + '/mail/inbox?tab=' + encodeURIComponent(activeTab) + '&limit=50';
      if (mailboxFilter > 0) {
        url += '&mailboxId=' + encodeURIComponent(String(mailboxFilter));
      }
      const res = await Api.get(url);
      if (gen !== loadGeneration || activeTab === 'unmatched') return;
      const data = res.data || {};
      if (typeof data.personalMailEnabled === 'boolean') {
        personalMailEnabled = data.personalMailEnabled;
      }
      itemsRaw = Array.isArray(data.items) ? data.items : [];
      sharedFolders = Array.isArray(data.sharedFolders) ? data.sharedFolders.map(String) : [];
      accounts = Array.isArray(data.accounts) ? data.accounts : [];
      personalBoxes = accounts.filter((a) => String(a.kind || '') === 'personal');
      selectedId = 0;
      readerMode = 'none';
      hideDecideZone();
      closeComposeQuiet();
      const reader = document.getElementById('crm-sales-inbox-reader');
      if (reader) reader.hidden = true;
      const actions = document.getElementById('crm-sales-inbox-actions');
      if (actions) actions.hidden = true;
      renderAccountSwitcher();
      renderSharedFoldersBanner();
      renderAuthAlert(Array.isArray(data.authAlerts) ? data.authAlerts : []);
      applyListView();
      const n = items.length;
      setStatus(n === 0
        ? t('No messages')
        : (n === 1 ? t('1 message') : t('%s messages', [String(n)])));
      syncToolbarForTab();
      // Bachus: one silent auto-update on first Mine visit — Sync stays available.
      if (!autoSyncedOnce && activeTab === 'mine' && personalMailEnabled && personalBoxes.length > 0) {
        void syncPersonal({ quiet: true, tab: 'mine' }).then(function (ok) {
          if (ok) autoSyncedOnce = true;
        });
      }
    } catch (e) {
      if (gen !== loadGeneration || activeTab === 'unmatched') return;
      setStatus((e.payload && e.payload.message) || e.message || t('Could not load inbox.'));
      items = [];
      renderList();
    } finally {
      if (gen === loadGeneration) setListBusy(false);
    }
  }

  /**
   * Refresh IMAP for the active tab (Personal or Team). Unassigned has no sync target.
   * @param {{quiet?: boolean, tab?: string}} [opts]
   * @returns {Promise<boolean>}
   */
  async function syncPersonal(opts) {
    const quiet = !!(opts && opts.quiet);
    const tab = (opts && opts.tab) || activeTab;
    const ws = workspaceId();
    const btn = document.getElementById('crm-sales-inbox-sync');
    if (!(ws > 0) || tab === 'unmatched') return false;
    if (syncInFlight) return false;

    const M = window.CustomerCheckInboxAssignMap;
    let boxes = (M && typeof M.accountsForInboxTab === 'function')
      ? M.accountsForInboxTab(accounts, tab)
      : (tab === 'mine' ? personalBoxes.slice() : []);
    if (tab === 'mine' && !personalMailEnabled) {
      boxes = [];
    }
    if (mailboxFilter > 0) {
      boxes = boxes.filter(function (b) {
        return parseInt(String(b.id || 0), 10) === mailboxFilter;
      });
    }
    if (!boxes.length) return false;

    syncInFlight = true;
    if (btn) {
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }
    if (!quiet) setStatus(t('Updating mail…'));
    try {
      for (let i = 0; i < boxes.length; i++) {
        const id = parseInt(String(boxes[i].id || 0), 10);
        if (!(id > 0)) continue;
        const isPersonal = String(boxes[i].kind || '') === 'personal';
        if (isPersonal) {
          await Api.post('/workspaces/' + ws + '/personal-mailboxes/' + id + '/sync', {});
        } else {
          await Api.post('/workspaces/' + ws + '/mailboxes/' + id + '/sync', {});
        }
      }
      const live = document.getElementById('crm-live-region');
      if (live && !quiet) {
        live.textContent = tab === 'team'
          ? t('Team mail sync finished.')
          : t('Personal mail sync finished.');
      }
      // Only reload if the user did not switch tabs mid-sync.
      if (activeTab === tab) {
        await loadInbox();
      }
      return true;
    } catch (e) {
      // Always announce failures (even quiet auto-sync) so screen-reader users are not left guessing.
      const msg = (e.payload && e.payload.message) || e.message || t('Sync failed.');
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = msg;
      if (!quiet) {
        setStatus(msg);
        if (C && C.alertDialog) {
          await C.alertDialog({
            title: t('Sync failed'),
            message: msg,
            variant: 'danger',
          });
        }
      }
      return false;
    } finally {
      syncInFlight = false;
      if (btn) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
      }
    }
  }

  const settingsLink = document.getElementById('crm-sales-inbox-settings');
  if (settingsLink) {
    settingsLink.href = settingsMailboxUrl();
  }

  root.querySelectorAll('[data-crm-inbox-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      selectTab(btn.getAttribute('data-crm-inbox-tab') || 'mine');
    });
  });

  const syncBtn = document.getElementById('crm-sales-inbox-sync');
  if (syncBtn) {
    syncBtn.addEventListener('click', () => {
      // Capture tab at click — user may switch tabs while IMAP is still running.
      void syncPersonal({ tab: activeTab });
    });
  }

  const accountSel = document.getElementById('crm-sales-inbox-account');
  if (accountSel) {
    accountSel.addEventListener('change', () => {
      mailboxFilter = parseInt(String(accountSel.value || '0'), 10) || 0;
      if (activeTab !== 'unmatched') {
        void loadInbox();
      }
    });
  }

  const searchQ = document.getElementById('crm-sales-inbox-q');
  if (searchQ) {
    searchQ.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        if (activeTab === 'unmatched') {
          void loadAssignInbox();
        } else {
          applyListView();
          const n = items.length;
          setStatus(n === 0
            ? t('No messages')
            : (n === 1 ? t('1 message') : t('%s messages', [String(n)])));
        }
      }, 280);
    });
  }

  root.querySelectorAll('[data-crm-inbox-mail-filter]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (activeTab !== 'unmatched') return;
      const next = btn.getAttribute('data-crm-inbox-mail-filter') || 'mail';
      const M = window.CustomerCheckInboxAssignMap;
      assignKind = (M && typeof M.reviewApiKind === 'function') ? M.reviewApiKind(next) : next;
      syncUrlState();
      syncMailFilterButtons();
      void loadAssignInbox();
    });
  });

  root.querySelectorAll('[data-crm-inbox-sort]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const col = btn.getAttribute('data-crm-inbox-sort') || 'sent';
      const M = window.CustomerCheckInboxAssignMap;
      const next = (M && typeof M.nextSortState === 'function')
        ? M.nextSortState(sortKey, sortDir, col)
        : { key: col, dir: sortKey === col && sortDir === 'desc' ? 'asc' : 'desc' };
      sortKey = next.key;
      sortDir = next.dir;
      applyListView();
      syncSortButtons();
      setStatus(t('Sorted.'));
    });
  });

  const assignForm = document.getElementById('crm-sales-inbox-assign-form');
  if (assignForm) {
    assignForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      const hid = document.getElementById('crm-sales-inbox-company-id');
      const cid = hid ? parseInt(String(hid.value || '0'), 10) : 0;
      void assignToCompany(cid);
    });
  }
  const quickAssign = document.getElementById('crm-sales-inbox-quick-assign');
  if (quickAssign) {
    quickAssign.addEventListener('click', function () {
      const cid = parseInt(String(quickAssign.getAttribute('data-company-id') || '0'), 10);
      void assignToCompany(cid);
    });
  }
  const noRelationBtn = document.getElementById('crm-sales-inbox-no-relation');
  if (noRelationBtn) {
    noRelationBtn.addEventListener('click', function () {
      void postAssignAction('no-relation', { note: '' });
    });
  }

  const ccToggle = document.getElementById('crm-sales-compose-cc-toggle');
  if (ccToggle) {
    ccToggle.addEventListener('click', () => {
      const wrap = document.getElementById('crm-sales-compose-cc-wrap');
      if (!wrap) return;
      const open = wrap.hidden;
      wrap.hidden = !open;
      ccToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      ccToggle.textContent = open ? t('Hide Cc') : t('Add Cc');
      if (open) {
        const cc = document.getElementById('crm-sales-compose-cc');
        if (cc) cc.focus();
      }
    });
  }

  const reconnect = document.getElementById('crm-sales-inbox-reconnect');
  if (reconnect) {
    reconnect.href = settingsMailboxUrl();
  }

  const emptyCta = document.getElementById('crm-sales-inbox-empty-cta');
  if (emptyCta) {
    emptyCta.href = settingsMailboxUrl();
  }

  const openAssignBtn = document.getElementById('crm-sales-inbox-open-assign');
  if (openAssignBtn) {
    openAssignBtn.addEventListener('click', function () {
      if (readerMode === 'assign') {
        readerMode = 'none';
        hideDecideZone();
        syncActionPressed();
        setStatus(t('Assign closed.'));
        return;
      }
      openAssignPanel();
    });
  }
  const replyBtn = document.getElementById('crm-sales-inbox-reply');
  if (replyBtn) {
    replyBtn.addEventListener('click', () => {
      if (readerMode === 'reply') {
        readerMode = 'none';
        closeComposeQuiet();
        syncActionPressed();
        setStatus(t('Reply closed.'));
        return;
      }
      const it = items.find(function (row) {
        return parseInt(String(row.id || 0), 10) === selectedId;
      });
      // Prefer last API preview (sanitized); never scrape reader DOM (truncated notes / HTML textContent).
      const bodyText = lastPreviewPlain !== '' ? lastPreviewPlain : '';
      if (it) openCompose(it, bodyText);
    });
  }
  async function postInboxMutate(pathSuffix, opts) {
    opts = opts || {};
    const pinnedMid = parseInt(String(opts.mailId || 0), 10);
    const it = selectedItem();
    const mid = pinnedMid > 0 ? pinnedMid : (it ? parseInt(String(it.id || 0), 10) : 0);
    const ws = workspaceId();
    if (!(ws > 0) || !(mid > 0) || assignBusy) return false;
    if (!shellCan('write')) {
      setStatus(t('View only — ask a member to manage this mail.'));
      return false;
    }
    assignBusy = true;
    setActionBusy(true);
    setStatus(t('Saving…'));
    // Aristoteles: when handing off to a busy-retry call, skip finally unlock so the
    // nested attempt owns assignBusy (avoids double-click delete/spam during retry).
    let handOffRetry = false;
    try {
      await Api.post('/workspaces/' + ws + '/mail/' + mid + '/' + pathSuffix, {});
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Saved.');
      setStatus(t('Saved. Updating list…'));
      selectedId = 0;
      readerMode = 'none';
      hideDecideZone();
      closeComposeQuiet();
      await loadInbox();
      return true;
    } catch (e) {
      const msg = (e.payload && e.payload.message) || e.message || t('Could not save.');
      const failTitle = pathSuffix === 'mark-spam'
        ? t('Could not mark as spam')
        : (pathSuffix === 'delete' ? t('Could not delete') : t('Could not save'));
      const busy = /mailbox is updating/i.test(String(msg));
      setStatus(msg);
      if (busy && !opts._busyRetried && C && typeof C.confirmDialog === 'function') {
        assignBusy = false;
        setActionBusy(false);
        const retry = await C.confirmDialog({
          title: failTitle,
          message: msg,
          confirmLabel: t('Try again'),
          cancelLabel: t('Not now'),
          variant: 'warning',
        });
        const wantsRetry = retry === true || (retry && retry.confirmed);
        if (wantsRetry) {
          handOffRetry = true;
          // Pin mail id — selection may change while the busy dialog is open.
          return postInboxMutate(pathSuffix, { _busyRetried: true, mailId: mid });
        }
        return false;
      }
      if (C && C.alertDialog) {
        await C.alertDialog({
          title: failTitle,
          message: msg,
          variant: busy ? 'warning' : 'danger',
        });
      }
      return false;
    } finally {
      if (!handOffRetry) {
        assignBusy = false;
        setActionBusy(false);
      }
    }
  }

  const discardBtn = document.getElementById('crm-sales-inbox-discard');
  if (discardBtn) {
    discardBtn.addEventListener('click', async function () {
      const it = selectedItem();
      if (!it || assignBusy) return;
      const mode = discardBtn.getAttribute('data-crm-mutate-mode') || 'assign';
      const isMail = discardBtn.getAttribute('data-crm-discard-mail') === '1';
      if (mode === 'assign') {
        const rid = parseInt(String(it.reviewItemId || 0), 10);
        if (!(rid > 0)) return;
      } else if (mode === 'inbox') {
        const mid = parseInt(String(it.id || 0), 10);
        if (!(mid > 0)) return;
      } else {
        return;
      }
      if (!(C && typeof C.confirmDialog === 'function')) {
        setStatus(t('Confirmation UI is unavailable. Reload the page and try again.'));
        return;
      }
      const inboxPersonal = mode === 'inbox' && activeTab === 'mine';
      const ok = await C.confirmDialog({
        title: isMail ? t('Delete this mail forever?') : t('Remove from Assign?'),
        message: isMail
          ? (inboxPersonal
            ? t('Permanently deletes the message from your mailbox. Prefer Mark as spam when you want the Spam folder.')
            : t('Permanently deletes the message from the shared mailbox. It also leaves Assign. Prefer Mark as spam when you want the mail server’s Spam folder.'))
          : t('Removes this item from Assign.'),
        confirmLabel: isMail ? t('Delete forever') : t('Remove from Assign'),
        cancelLabel: t('Cancel'),
        variant: 'danger',
      });
      if (!ok) return;
      setStatus(isMail ? t('Deleting…') : t('Removing…'));
      const done = mode === 'inbox'
        ? await postInboxMutate('delete')
        : await postAssignAction('discard', {});
      if (done) {
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = isMail ? t('Mail deleted.') : t('1 item removed from Assign');
      }
    });
  }
  const spamBtn = document.getElementById('crm-sales-inbox-spam');
  if (spamBtn) {
    spamBtn.addEventListener('click', async function () {
      const it = selectedItem();
      if (!it || assignBusy) return;
      const mode = spamBtn.getAttribute('data-crm-mutate-mode') || 'assign';
      if (mode === 'assign') {
        const rid = parseInt(String(it.reviewItemId || 0), 10);
        if (!(rid > 0)) return;
      } else if (mode === 'inbox') {
        const mid = parseInt(String(it.id || 0), 10);
        if (!(mid > 0)) return;
      } else {
        return;
      }
      if (!(C && typeof C.confirmDialog === 'function')) {
        setStatus(t('Confirmation UI is unavailable. Reload the page and try again.'));
        return;
      }
      const ok = await C.confirmDialog({
        title: t('Mark as spam?'),
        message: mode === 'inbox'
          ? t('Moves this message into the Spam/Junk folder on the mail server.')
          : t('Moves this message into the server Spam/Junk folder and leaves Assign.'),
        confirmLabel: t('Mark as spam'),
        cancelLabel: t('Cancel'),
        variant: 'danger',
      });
      if (!ok) return;
      setStatus(t('Marking as spam…'));
      const done = mode === 'inbox'
        ? await postInboxMutate('mark-spam')
        : await postAssignAction('mark-spam', {});
      if (done) {
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = t('Marked as spam.');
      }
    });
  }
  const cancelCompose = document.getElementById('crm-sales-compose-cancel');
  if (cancelCompose) {
    cancelCompose.addEventListener('click', () => {
      readerMode = 'none';
      closeComposeQuiet();
      syncActionPressed();
      const actions = document.getElementById('crm-sales-inbox-actions');
      if (actions) actions.hidden = false;
      setStatus(t('Reply closed.'));
    });
  }
  const attachAddBtn = document.getElementById('crm-sales-compose-attach-add');
  if (attachAddBtn) {
    attachAddBtn.addEventListener('click', async () => {
      if (composeAttachments.length >= 5) {
        if (C && C.alertDialog) {
          await C.alertDialog({
            title: t('Too many files'),
            message: t('You can attach at most 5 files.'),
            variant: 'warning',
          });
        }
        return;
      }
      const Picker = window.CustomerCheckFolderPicker;
      if (!Picker || typeof Picker.pickFile !== 'function') {
        if (C && C.alertDialog) {
          await C.alertDialog({
            title: t('Files picker unavailable'),
            message: t('Open Files, then try again.'),
            variant: 'warning',
          });
        }
        return;
      }
      attachAddBtn.disabled = true;
      attachAddBtn.setAttribute('aria-busy', 'true');
      setStatus(t('Choose a file…'));
      let picked = null;
      try {
        picked = await Picker.pickFile({
          // Empty filter: show all Files; server rejects disallowed MIME on send.
          mimeTypes: [],
          title: t('Select attachment'),
        });
      } finally {
        attachAddBtn.removeAttribute('aria-busy');
        attachAddBtn.disabled = composeAttachments.length >= 5;
      }
      if (!picked || picked.unavailable) {
        if (picked && picked.unavailable && C && C.alertDialog) {
          await C.alertDialog({
            title: t('Files picker unavailable'),
            message: t('Open Files, then try again.'),
            variant: 'warning',
          });
        } else {
          setStatus(t('No file added.'));
        }
        renderComposeAttachments();
        return;
      }
      const fid = parseInt(String(picked.fileId || 0), 10);
      if (!(fid > 0)) {
        if (C && C.alertDialog) {
          await C.alertDialog({
            title: t('Could not open that file'),
            message: t('Pick the file again from Files, or choose another file.'),
            variant: 'warning',
          });
        }
        setStatus(t('Could not attach that file.'));
        renderComposeAttachments();
        return;
      }
      if (composeAttachments.some(function (a) { return a.id === fid; })) {
        setStatus(t('That file is already attached.'));
        renderComposeAttachments();
        return;
      }
      const name = String(picked.name || picked.fileName || '').trim() || ('file ' + fid);
      composeAttachments.push({ id: fid, name: name });
      renderComposeAttachments();
      setStatus(t('Attached: %s', [name]));
    });
  }
  const sendComposeBtn = document.getElementById('crm-sales-compose-send');
  if (sendComposeBtn) {
    sendComposeBtn.addEventListener('click', () => {
      void sendCompose();
    });
  }

  // Keyboard: Left/Right between tabs (roving tabindex on selected)
  const tablist = document.getElementById('crm-sales-inbox-tabs');
  if (tablist) {
    tablist.querySelectorAll('[role="tab"]').forEach((btn) => {
      const on = btn.getAttribute('aria-selected') === 'true';
      btn.setAttribute('tabindex', on ? '0' : '-1');
    });
    tablist.addEventListener('keydown', (ev) => {
      const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
      const idx = tabs.indexOf(document.activeElement);
      if (idx < 0) return;
      if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
        ev.preventDefault();
        const next = ev.key === 'ArrowRight'
          ? tabs[(idx + 1) % tabs.length]
          : tabs[(idx - 1 + tabs.length) % tabs.length];
        next.focus();
        selectTab(next.getAttribute('data-crm-inbox-tab') || 'mine');
      }
    });
  }

  void (async function boot() {
    let initialTab = 'mine';
    try {
      const params = new URLSearchParams(window.location.search);
      const tab = String(params.get('tab') || '').trim();
      if (tab === 'team' || tab === 'unmatched' || tab === 'unassigned') {
        initialTab = tab === 'unassigned' ? 'unmatched' : tab;
      }
      const kindRaw = String(params.get('kind') || '').trim();
      assignKind = normalizeAssignKind(kindRaw);
      if (kindRaw === 'shared_mail' || kindRaw === 'personal_mail_shared_unmatched') {
        assignKind = kindRaw;
      }
    } catch (e) { /* ignore */ }
    await loadPersonalMeta();
    if (initialTab === 'unmatched') {
      selectTab('unmatched');
    } else if (initialTab === 'team') {
      selectTab('team');
    } else {
      syncToolbarForTab();
      await loadInbox();
    }
  })();
})();
