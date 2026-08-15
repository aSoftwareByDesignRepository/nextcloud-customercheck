/**
 * Shared/personal mailbox SMTP send helper — product §14.3 + CUC-PERSONAL-MAIL-1.
 * Used by company Talk Answer, Assign Answer, Sales Inbox, agreements, and money.
 *
 * Idempotency: one key per confirmed send attempt (reused on retry / double-click).
 * Multi-account: operator picks personal or team From when more than one is available.
 */
(function () {
  'use strict';

  /** @type {Map<string, string>} */
  var inflightKeys = new Map();
  /** @type {Map<number, number>} */
  var lastMailboxByWs = new Map();

  function t(msg, args) {
    const list = Array.isArray(args) ? args : [];
    let out = window.t ? window.t('customercheck', msg, list) : msg;
    // Nextcloud t() sometimes leaves %s / %1$s — fill locally (same as sales-inbox / settings).
    if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
      let i = 0;
      out = out.replace(/%(?:\d+\$)?s/g, function () {
        const v = list[i];
        i += 1;
        return v == null ? '' : String(v);
      });
    }
    return out;
  }

  function esc(s) {
    return String(s || '').replace(/[<>&"]/g, function (ch) {
      return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' })[ch];
    });
  }

  function activeWorkspaceId() {
    const switcher = document.querySelector('[data-crm-active-workspace]');
    if (switcher) {
      const n = parseInt(String(switcher.getAttribute('data-crm-active-workspace') || '0'), 10);
      if (n > 0) return n;
    }
    try {
      const u = new URL(window.location.href);
      const q = parseInt(String(u.searchParams.get('workspaceId') || '0'), 10);
      if (q > 0) return q;
    } catch (e) { /* ignore */ }
    return 0;
  }

  function makeIdempotencyKey() {
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
    return 'crm-send-' + Date.now() + '-' + rand;
  }

  function attemptFingerprint(wsId, mailboxId, to, subject, companyId) {
    return [wsId, mailboxId, to, subject, companyId || 0].join('|');
  }

  function boxLabel(box) {
    const kind = String((box && box.kind) || 'team');
    const base = String((box && (box.label || box.emailAddress)) || 'mailbox');
    if (kind === 'personal') {
      return t('Personal') + ': ' + base;
    }
    return t('Team') + ': ' + base;
  }

  function dialogRoot(dialogId) {
    return document.getElementById(dialogId);
  }

  function queryInDialog(dialogId, selector) {
    const root = dialogRoot(dialogId);
    return root ? root.querySelector(selector) : null;
  }

  /**
   * @param {Array<{id?:number,label?:string,emailAddress?:string,kind?:string,hasSignature?:boolean}>} boxes
   * @param {number} wsId
   * @param {number} [preferredMailboxId]
   * @returns {Promise<{id:number,label:string,kind:string,hasSignature:boolean}|null>}
   */
  function pickMailbox(boxes, wsId, preferredMailboxId) {
    const C = window.CustomerCheckComponents || window.CrmComponents;
    if (!C) return Promise.resolve(null);
    if (boxes.length === 1) {
      const only = boxes[0];
      return Promise.resolve({
        id: parseInt(String(only.id || 0), 10),
        label: boxLabel(only),
        kind: String(only.kind || 'team'),
        hasSignature: !!only.hasSignature,
      });
    }
    const preferred = (preferredMailboxId > 0 ? preferredMailboxId : 0) || lastMailboxByWs.get(wsId) || 0;
    const dialogId = 'crm-pick-mailbox-' + Date.now();
    const options = boxes.map(function (b) {
      const id = parseInt(String(b.id || 0), 10);
      const label = boxLabel(b);
      const selected = id === preferred ? ' selected' : '';
      return '<option value="' + esc(String(id)) + '"' + selected + '>' + esc(label) + '</option>';
    }).join('');
    return new Promise(function (resolve) {
      C.openDialog({
        id: dialogId,
        title: t('Send as'),
        content:
          '<form id="' + dialogId + '-form" class="crm-form crm-form--stack">' +
          '<p class="crm-muted" id="' + dialogId + '-hint">' +
          esc(t('Pick which connected account this message should send as.')) + '</p>' +
          '<div class="form-group"><label class="form-label" for="' + dialogId + '-select">' + esc(t('Send as')) + '</label>' +
          '<select id="' + dialogId + '-select" class="crm-input form-select" required ' +
          'aria-describedby="' + dialogId + '-hint">' + options + '</select></div>' +
          '<div class="crm-form-actions">' +
          '<button type="submit" class="crm-btn crm-btn--primary">' + esc(t('Continue')) + '</button> ' +
          '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + esc(t('Cancel')) + '</button></div></form>',
        size: 'md',
      });
      const form = document.getElementById(dialogId + '-form');
      const close = function () {
        C.closeModal(document.getElementById(dialogId));
      };
      const cancelBtn = form && form.querySelector('[data-cancel]');
      if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
          close();
          resolve(null);
        });
      }
      if (form) {
        form.addEventListener('submit', function (ev) {
          ev.preventDefault();
          const sel = document.getElementById(dialogId + '-select');
          const id = parseInt(String(sel && sel.value), 10);
          const box = boxes.find(function (b) { return parseInt(String(b.id || 0), 10) === id; });
          close();
          if (!box || id < 1) {
            resolve(null);
            return;
          }
          lastMailboxByWs.set(wsId, id);
          resolve({
            id: id,
            label: boxLabel(box),
            kind: String(box.kind || 'team'),
            hasSignature: !!box.hasSignature,
          });
        });
      }
      const sel = document.getElementById(dialogId + '-select');
      if (sel && typeof sel.focus === 'function') {
        try { sel.focus(); } catch (e) { /* ignore */ }
      }
    });
  }

  /**
   * @param {{to?:string,cc?:string[]|string,subject?:string,bodyText?:string,bodyHtml?:string,quotedBody?:string,quotedHtml?:string}} pack
   * @param {{companyId?:number,idempotencyKey?:string,attachmentFileIds?:number[],includeSignature?:boolean,mailboxId?:number,sourceVisibility?:string,sourceMailboxKind?:string}} [opts]
   * @returns {Promise<boolean>}
   */
  async function sendViaSharedMailbox(pack, opts) {
    const C = window.CustomerCheckComponents || window.CrmComponents;
    const Api = window.CustomerCheckApi;
    const companyId = (opts && opts.companyId) ? parseInt(String(opts.companyId), 10) : 0;
    const wsId = activeWorkspaceId();
    const to = String((pack && pack.to) || '').trim();
    const subject = String((pack && pack.subject) || '').trim();
    const body = String((pack && pack.bodyText) || '');
    const bodyHtml = pack && pack.bodyHtml != null ? String(pack.bodyHtml) : '';
    const quotedBody = pack && pack.quotedBody != null ? String(pack.quotedBody) : '';
    const quotedHtml = pack && pack.quotedHtml != null ? String(pack.quotedHtml) : '';
    let cc = [];
    if (pack && Array.isArray(pack.cc)) {
      cc = pack.cc.map(function (s) { return String(s || '').trim().toLowerCase(); }).filter(Boolean);
    } else if (pack && typeof pack.cc === 'string' && pack.cc.trim()) {
      cc = pack.cc.split(/[,;]+/).map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    }
    const includeSignature = !(opts && Object.prototype.hasOwnProperty.call(opts, 'includeSignature'))
      || !!(opts && opts.includeSignature);
    const attachmentFileIds = (opts && Array.isArray(opts.attachmentFileIds))
      ? opts.attachmentFileIds.map(function (id) { return parseInt(String(id), 10); }).filter(function (id) { return id > 0; })
      : [];
    if (!C || !Api) return false;
    if (!wsId || !to || !subject) {
      await C.alertDialog({
        title: t('Cannot send'),
        message: !to
          ? t('This message has no usable reply address.')
          : t('Add a contact email and open this company first.'),
        variant: 'danger',
      });
      return false;
    }
    let boxes = [];
    try {
      const res = await Api.get('/workspaces/' + wsId + '/mail/send-accounts');
      boxes = (res.data && res.data.items) || res.data || [];
      if (!Array.isArray(boxes)) boxes = [];
    } catch (e) {
      // Fallback for older servers without send-accounts.
      try {
        const res = await Api.get('/workspaces/' + wsId + '/mailboxes');
        boxes = (res.data && res.data.items) || res.data || [];
        if (!Array.isArray(boxes)) boxes = [];
        boxes = boxes.map(function (b) {
          return Object.assign({}, b, { kind: b.kind || 'team' });
        });
      } catch (e2) {
        await C.alertDialog({
          title: t('Cannot send'),
          message: (e.payload && e.payload.message) || e.message,
          variant: 'danger',
        });
        return false;
      }
    }
    if (boxes.length === 0) {
      await C.alertDialog({
        title: t('No send account'),
        message: t('Connect My email or ask a manager for Team mailbox send rights — or use Open mail instead.'),
        variant: 'info',
      });
      return false;
    }
    const preferredMailboxId = (opts && opts.mailboxId) ? parseInt(String(opts.mailboxId), 10) : 0;
    const preferredSendable = preferredMailboxId > 0
      && boxes.some(function (b) { return parseInt(String(b.id || 0), 10) === preferredMailboxId; });
    const picked = await pickMailbox(boxes, wsId, preferredSendable ? preferredMailboxId : 0);
    if (!picked || picked.id < 1) return false;
    const mailboxId = picked.id;
    const label = picked.label;
    const personal = picked.kind === 'personal';
    const sourceKind = String((opts && opts.sourceMailboxKind) || '').toLowerCase();
    const sourceVis = String((opts && opts.sourceVisibility) || '').toLowerCase();
    const elevatingPrivate = !personal
      && (sourceVis === 'private' || sourceKind === 'personal');
    const attachNote = attachmentFileIds.length
      ? ' ' + t('(%s file(s) attached)', [String(attachmentFileIds.length)])
      : '';
    var confirmMessage = personal
      ? t('This sends as your personal account (%s). Only you see private folders; shared folders follow your folder settings.', [label]) + attachNote
      : t('This sends as %s. Everyone on the team who can read mail can see it.', [label]) + attachNote;
    if (elevatingPrivate) {
      confirmMessage += ' ' + t('This reply quotes private personal mail. Team send makes that quote visible to colleagues who can read mail.');
    }
    const ok = await C.confirmDialog({
      title: personal ? t('Send from personal email?') : t('Send from shared mailbox?'),
      message: confirmMessage,
      confirmLabel: t('Send'),
      cancelLabel: t('Cancel'),
      variant: elevatingPrivate ? 'warning' : 'info',
    });
    if (!ok || !ok.confirmed) return false;

    const fp = attemptFingerprint(wsId, mailboxId, to, subject, companyId);
    var key = (opts && opts.idempotencyKey) ? String(opts.idempotencyKey) : '';
    if (!key) {
      key = inflightKeys.get(fp) || makeIdempotencyKey();
      inflightKeys.set(fp, key);
    }
    try {
      const payload = {
        mailboxId: mailboxId,
        to: to,
        subject: subject,
        body: body,
        includeSignature: includeSignature,
        idempotencyKey: key,
      };
      if (bodyHtml) payload.bodyHtml = bodyHtml;
      if (quotedBody) payload.quotedBody = quotedBody;
      if (quotedHtml) payload.quotedHtml = quotedHtml;
      if (cc.length) payload.cc = cc;
      if (companyId > 0) payload.companyId = companyId;
      if (attachmentFileIds.length) payload.attachmentFileIds = attachmentFileIds;
      await Api.post('/workspaces/' + wsId + '/mail/send', payload);
      inflightKeys.delete(fp);
      const live = document.getElementById('crm-live-region');
      if (live) {
        live.textContent = personal
          ? t('Message sent from your personal email.')
          : t('Message sent from the shared mailbox.');
      }
      return true;
    } catch (e) {
      await C.alertDialog({
        title: t('Send failed'),
        message: (e.payload && e.payload.message) || e.message,
        variant: 'danger',
      });
      return false;
    }
  }

  /**
   * Offer shared-send after a mailto/mailApp prepare, with Open mail still available.
   * @param {{to?:string,subject?:string,bodyText?:string,bodyHtml?:string,quotedBody?:string,mailAppUrl?:string,mailtoUrl?:string,mailboxId?:number,companyId?:number,visibility?:string,mailboxKind?:string}} pack
   * @param {{companyId?:number,title?:string,mailboxId?:number,replyMode?:boolean}} [opts]
   */
  async function offerPreparedMail(pack, opts) {
    const C = window.CustomerCheckComponents || window.CrmComponents;
    if (!C) {
      const url = (pack && (pack.mailAppUrl || pack.mailtoUrl)) || '';
      if (url) window.open(url, '_blank', 'noopener,noreferrer');
      return;
    }
    const title = (opts && opts.title) || t('Prepared message');
    const companyId = parseInt(String(
      (opts && opts.companyId) || (pack && pack.companyId) || 0
    ), 10) || 0;
    const preferredMailboxId = parseInt(String(
      (opts && opts.mailboxId) || (pack && pack.mailboxId) || 0
    ), 10) || 0;
    const replyMode = !!(opts && opts.replyMode);
    const sourceVisibility = String((pack && pack.visibility) || '');
    const sourceMailboxKind = String((pack && pack.mailboxKind) || '');
    const toAddr = String((pack && pack.to) || '').trim();
    const subjectLine = String((pack && pack.subject) || '').trim();
    if (!toAddr) {
      await C.alertDialog({
        title: t('Cannot answer'),
        message: t('This message has no usable reply address.'),
        variant: 'warning',
      });
      return;
    }
    const openHref = (typeof C.safeExternalHref === 'function')
      ? C.safeExternalHref((pack && (pack.mailAppUrl || pack.mailtoUrl)) || '#')
      : '#';
    const dialogKey = makeIdempotencyKey();
    const dialogId = 'crm-prepared-mail-' + Date.now();
    /** @type {Array<{id:number,name:string}>} */
    var attachments = [];
    var sendInFlight = false;

    var accounts = [];
    try {
      const Api = window.CustomerCheckApi;
      const wsId = activeWorkspaceId();
      if (Api && wsId > 0) {
        const res = await Api.get('/workspaces/' + wsId + '/mail/send-accounts');
        accounts = (res.data && res.data.items) || res.data || [];
        if (!Array.isArray(accounts)) accounts = [];
      }
    } catch (e) { accounts = []; }

    const canSendInApp = accounts.length > 0;
    const preferredInAccounts = preferredMailboxId > 0
      && accounts.some(function (b) { return parseInt(String(b.id || 0), 10) === preferredMailboxId; });
    const sendBtnHtml = canSendInApp
      ? ('<button type="button" class="crm-btn crm-btn--primary" data-crm-shared-send ' +
        'id="' + dialogId + '-send">' +
        esc(t('Send from connected account')) + '</button> ')
      : '';
    const openBtnClass = canSendInApp ? 'crm-btn crm-btn--secondary' : 'crm-btn crm-btn--primary';
    const noAccountHint = canSendInApp
      ? ''
      : ('<div class="crm-callout crm-callout--warning" role="status"><p>' +
        esc(t('No connected send account yet. Connect My email or ask a manager for Team mailbox send rights — or open your mail program.')) +
        '</p></div>');
    const hintId = dialogId + '-hint';
    const statusId = dialogId + '-status';

    await C.openDialog({
      id: dialogId,
      title: title,
      content:
        '<div class="crm-prepared-mail" data-crm-prepared-mail>' +
        '<div class="crm-callout crm-callout--info" role="status" id="' + hintId + '">' +
        '<p>' + esc(t('Write your reply here. Send from a connected personal or team account, or open your mail program.')) + '</p>' +
        '<p class="crm-muted">' + esc(t('Team sends are visible to colleagues who can read mail.')) + '</p>' +
        '</div>' +
        noAccountHint +
        '<div class="crm-prepared-mail__meta" aria-label="' + esc(t('Recipients')) + '">' +
        '<p class="crm-prepared-mail__subject"><span class="crm-prepared-mail__label">' + esc(t('Subject')) +
        '</span> <strong>' + esc(subjectLine) + '</strong></p>' +
        '<p class="crm-prepared-mail__to"><span class="crm-prepared-mail__label">' + esc(t('To')) +
        '</span> ' + esc(toAddr) + '</p>' +
        '</div>' +
        '<div class="form-group crm-prepared-mail__body">' +
        '<label class="form-label" id="' + dialogId + '-body-label" for="' + dialogId + '-rich-focus">' +
        esc(t('Message')) + '</label>' +
        '<div id="' + dialogId + '-rich" class="crm-prepared-mail__editor" ' +
        'aria-labelledby="' + dialogId + '-body-label" aria-describedby="' + hintId + '"></div>' +
        '</div>' +
        '<div class="form-group crm-prepared-mail__attach">' +
        '<p class="form-label" id="' + dialogId + '-attach-label">' + esc(t('Attachments (optional)')) + '</p>' +
        '<p class="crm-muted" id="' + dialogId + '-attach-hint">' +
        esc(t('Choose any file in Files. Only PDF, PNG, JPEG or plain text (max 5 files, 10 MiB each) can be sent.')) + '</p>' +
        '<ul id="' + dialogId + '-attach-list" class="crm-list crm-prepared-mail__attach-list" role="list" ' +
        'aria-labelledby="' + dialogId + '-attach-label" aria-describedby="' + dialogId + '-attach-hint" ' +
        'aria-live="polite"></ul>' +
        '<div class="crm-form-actions crm-form-actions--start">' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-crm-attach-file ' +
        'id="' + dialogId + '-attach" aria-describedby="' + dialogId + '-attach-hint">' +
        esc(t('Add file')) + '</button></div>' +
        '</div>' +
        '<p class="visually-hidden" id="' + statusId + '" role="status" aria-live="polite"></p>' +
        '<div class="crm-form-actions crm-prepared-mail__actions">' +
        sendBtnHtml +
        '<a class="' + openBtnClass + '" href="' + esc(openHref) + '" rel="noopener noreferrer" ' +
        'id="' + dialogId + '-open">' + esc(t('Open mail')) + '</a></div>' +
        '</div>',
      size: 'lg',
    });

    var richApi = null;
    const richHost = document.getElementById(dialogId + '-rich');
    const Rich = window.CustomerCheckRichMailComposer;
    const preferred = (preferredInAccounts
      ? accounts.find(function (b) { return parseInt(String(b.id || 0), 10) === preferredMailboxId; })
      : null)
      || accounts.find(function (b) {
        return parseInt(String(b.id || 0), 10) === (lastMailboxByWs.get(activeWorkspaceId()) || 0);
      })
      || accounts[0]
      || null;
    const hasSig = preferred ? !!preferred.hasSignature : true;
    const quotedSeed = (pack && pack.quotedBody != null && String(pack.quotedBody) !== '')
      ? String(pack.quotedBody)
      : (replyMode ? String((pack && pack.bodyText) || '') : '');
    const initialSeed = replyMode
      ? ''
      : String((pack && pack.bodyText) || '');
    if (richHost && Rich && Rich.mount) {
      richApi = Rich.mount(richHost, {
        labelledBy: dialogId + '-body-label',
        hasSignature: hasSig,
        includeSignatureDefault: true,
        initialPlain: initialSeed,
        initialHtml: replyMode ? '' : ((pack && pack.bodyHtml) || ''),
        quotedPlain: quotedSeed,
      });
      // Focus trap target for label[for] — rich editors often use contenteditable.
      const focusProxy = document.createElement('button');
      focusProxy.type = 'button';
      focusProxy.id = dialogId + '-rich-focus';
      focusProxy.className = 'visually-hidden';
      focusProxy.setAttribute('aria-label', t('Message'));
      focusProxy.addEventListener('focus', function () {
        const editable = richHost.querySelector('[contenteditable="true"], textarea, .ProseMirror');
        if (editable && typeof editable.focus === 'function') {
          try { editable.focus(); } catch (e) { /* ignore */ }
        }
      });
      richHost.insertBefore(focusProxy, richHost.firstChild);
      try { focusProxy.focus(); } catch (e) { /* ignore */ }
    } else if (richHost) {
      const fallback = initialSeed || quotedSeed || ((pack && pack.bodyText) || '');
      richHost.innerHTML =
        '<textarea id="' + dialogId + '-rich-focus" class="crm-input form-input crm-prepared-mail__fallback" ' +
        'rows="8" aria-labelledby="' + dialogId + '-body-label">' + esc(fallback) + '</textarea>';
      const ta = document.getElementById(dialogId + '-rich-focus');
      if (ta && typeof ta.focus === 'function') {
        try { ta.focus(); } catch (e) { /* ignore */ }
      }
      richApi = {
        getPlain: function () { return ta ? String(ta.value || '') : fallback; },
        getHtml: function () { return ''; },
        getQuotedPlain: function () { return replyMode ? quotedSeed : ''; },
        getQuotedHtml: function () { return ''; },
        // Default ON — server skips when the chosen mailbox has no signature.
        includeSignature: function () { return true; },
        destroy: function () { /* noop */ },
      };
    }

    function setStatus(msg) {
      const el = document.getElementById(statusId);
      if (el) el.textContent = msg;
    }

    function renderAttachList() {
      const list = document.getElementById(dialogId + '-attach-list');
      if (!list) return;
      if (!attachments.length) {
        list.innerHTML = '<li class="crm-muted" role="listitem">' + esc(t('No files yet — tap Add file to attach.')) + '</li>';
        return;
      }
      list.innerHTML = attachments.map(function (a, idx) {
        return '<li class="crm-prepared-mail__attach-item" role="listitem">' +
          '<span class="crm-prepared-mail__attach-name" title="' + esc(a.name || ('file ' + a.id)) + '">' +
          esc(a.name || ('file ' + a.id)) + '</span>' +
          ' <button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-detach="' + idx + '">' +
          esc(t('Remove')) + '</button></li>';
      }).join('');
      list.querySelectorAll('[data-crm-detach]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const i = parseInt(String(btn.getAttribute('data-crm-detach') || '-1'), 10);
          if (i >= 0) {
            attachments.splice(i, 1);
            renderAttachList();
            setStatus(t('Attachment removed.'));
          }
        });
      });
    }
    renderAttachList();

    const attachBtn = queryInDialog(dialogId, '[data-crm-attach-file]');
    if (attachBtn) {
      attachBtn.addEventListener('click', async function () {
        if (attachments.length >= 5) {
          await C.alertDialog({
            title: t('Too many files'),
            message: t('You can attach at most 5 files.'),
            variant: 'warning',
          });
          return;
        }
        const Picker = window.CustomerCheckFolderPicker;
        if (!Picker || typeof Picker.pickFile !== 'function') {
          await C.alertDialog({
            title: t('Files picker unavailable'),
            message: t('Open Files, then try again.'),
            variant: 'warning',
          });
          return;
        }
        const picked = await Picker.pickFile({
          // Empty filter: show all Files; server rejects disallowed MIME on send.
          mimeTypes: [],
          title: t('Select attachment'),
        });
        if (!picked || picked.unavailable) {
          if (picked && picked.unavailable) {
            await C.alertDialog({
              title: t('Files picker unavailable'),
              message: t('Open Files, then try again.'),
              variant: 'warning',
            });
          } else {
            setStatus(t('No file added.'));
          }
          return;
        }
        const fid = parseInt(String(picked.fileId || 0), 10);
        if (!(fid > 0)) {
          await C.alertDialog({
            title: t('Could not open that file'),
            message: t('Pick the file again from Files, or choose another file.'),
            variant: 'warning',
          });
          setStatus(t('Could not attach that file.'));
          return;
        }
        if (attachments.some(function (a) { return a.id === fid; })) {
          setStatus(t('That file is already attached.'));
          return;
        }
        attachments.push({ id: fid, name: String(picked.name || ('file ' + fid)) });
        renderAttachList();
        setStatus(t('File attached.'));
      });
    }

    const sendBtn = queryInDialog(dialogId, '[data-crm-shared-send]');
    if (sendBtn) {
      sendBtn.addEventListener('click', async function () {
        if (sendInFlight || sendBtn.disabled) return;
        sendInFlight = true;
        sendBtn.disabled = true;
        sendBtn.setAttribute('aria-busy', 'true');
        try {
          const bodyText = richApi ? richApi.getPlain() : String((pack && pack.bodyText) || '');
          const bodyHtml = richApi ? richApi.getHtml() : String((pack && pack.bodyHtml) || '');
          const includeSignature = richApi ? richApi.includeSignature() : true;
          const quotedBody = richApi && richApi.getQuotedPlain
            ? String(richApi.getQuotedPlain() || '').trim()
            : String((pack && pack.quotedBody) || (replyMode ? ((pack && pack.bodyText) || '') : '') || '').trim();
          const quotedHtml = richApi && richApi.getQuotedHtml
            ? String(richApi.getQuotedHtml() || '').trim()
            : '';
          const sent = await sendViaSharedMailbox({
            to: toAddr,
            subject: subjectLine,
            bodyText: bodyText,
            bodyHtml: bodyHtml,
            quotedBody: quotedBody,
            quotedHtml: quotedHtml,
          }, {
            companyId: companyId,
            mailboxId: preferredInAccounts ? preferredMailboxId : 0,
            idempotencyKey: dialogKey,
            attachmentFileIds: attachments.map(function (a) { return a.id; }),
            includeSignature: includeSignature,
            sourceVisibility: sourceVisibility,
            sourceMailboxKind: sourceMailboxKind,
          });
          if (!sent) {
            sendBtn.disabled = false;
            sendBtn.removeAttribute('aria-busy');
            return;
          }
          sendBtn.textContent = t('Sent');
          setStatus(t('Message sent.'));
          if (richApi && typeof richApi.destroy === 'function') {
            try { richApi.destroy(); } catch (e) { /* ignore */ }
          }
          C.closeModal(dialogRoot(dialogId));
        } finally {
          sendInFlight = false;
        }
      });
    }
  }

  window.CustomerCheckSharedMail = {
    activeWorkspaceId: activeWorkspaceId,
    makeIdempotencyKey: makeIdempotencyKey,
    sendViaSharedMailbox: sendViaSharedMailbox,
    offerPreparedMail: offerPreparedMail,
    pickMailbox: pickMailbox,
  };
})();
