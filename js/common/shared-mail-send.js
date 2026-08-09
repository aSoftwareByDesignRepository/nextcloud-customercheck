/**
 * Shared-mailbox SMTP send helper — product §14.3.
 * Used by company 360, agreements, and money compose surfaces.
 *
 * Idempotency: one key per confirmed send attempt (reused on retry / double-click).
 * Multi-mailbox: operator picks which shared From when more than one is connected.
 */
(function () {
  'use strict';

  /** @type {Map<string, string>} */
  var inflightKeys = new Map();
  /** @type {Map<number, number>} */
  var lastMailboxByWs = new Map();

  function t(msg, args) {
    return window.t ? window.t('customercheck', msg, args || []) : msg;
  }

  function esc(s) {
    return String(s || '').replace(/[<>&]/g, function (ch) {
      return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[ch];
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
    return String((box && (box.label || box.emailAddress)) || 'shared');
  }

  /**
   * @param {Array<{id?:number,label?:string,emailAddress?:string}>} boxes
   * @param {number} wsId
   * @returns {Promise<{id:number,label:string}|null>}
   */
  function pickMailbox(boxes, wsId) {
    const C = window.CustomerCheckComponents || window.CrmComponents;
    if (!C) return Promise.resolve(null);
    if (boxes.length === 1) {
      const only = boxes[0];
      return Promise.resolve({
        id: parseInt(String(only.id || 0), 10),
        label: boxLabel(only),
      });
    }
    const preferred = lastMailboxByWs.get(wsId) || 0;
    const dialogId = 'crm-pick-mailbox-' + Date.now();
    const options = boxes.map(function (b) {
      const id = parseInt(String(b.id || 0), 10);
      const label = boxLabel(b);
      const selected = id === preferred ? ' selected' : '';
      return '<option value="' + id + '"' + selected + '>' + esc(label) + '</option>';
    }).join('');
    return new Promise(function (resolve) {
      C.openDialog({
        id: dialogId,
        title: t('Which shared mailbox?'),
        content:
          '<form id="' + dialogId + '-form" class="crm-form">' +
          '<p class="crm-muted">' + esc(t('This workspace has more than one shared mailbox. Pick who the message should send as.')) + '</p>' +
          '<div class="form-group"><label class="form-label" for="' + dialogId + '-select">' + esc(t('Send as')) + '</label>' +
          '<select id="' + dialogId + '-select" class="crm-input form-select" required>' + options + '</select></div>' +
          '<div class="crm-form-actions">' +
          '<button type="submit" class="crm-btn crm-btn--primary">' + esc(t('Continue')) + '</button> ' +
          '<button type="button" class="crm-btn crm-btn--secondary" data-cancel>' + esc(t('Cancel')) + '</button></div></form>',
        size: 'sm',
      });
      const form = document.getElementById(dialogId + '-form');
      const close = function () {
        C.closeModal(document.getElementById(dialogId));
      };
      form.querySelector('[data-cancel]').addEventListener('click', function () {
        close();
        resolve(null);
      });
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
        resolve({ id: id, label: boxLabel(box) });
      });
    });
  }

  /**
   * @param {{to?:string,subject?:string,bodyText?:string}} pack
   * @param {{companyId?:number,idempotencyKey?:string,attachmentFileIds?:number[]}} [opts]
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
    const attachmentFileIds = (opts && Array.isArray(opts.attachmentFileIds))
      ? opts.attachmentFileIds.map(function (id) { return parseInt(String(id), 10); }).filter(function (id) { return id > 0; })
      : [];
    if (!C || !Api) return false;
    if (!wsId || !to || !subject) {
      await C.alertDialog({
        title: t('Cannot send'),
        message: t('Add a contact email and open this company in a workspace first.'),
        variant: 'danger',
      });
      return false;
    }
    let boxes = [];
    try {
      const res = await Api.get('/workspaces/' + wsId + '/mailboxes');
      boxes = (res.data && res.data.items) || res.data || [];
      if (!Array.isArray(boxes)) boxes = [];
    } catch (e) {
      await C.alertDialog({
        title: t('Cannot send'),
        message: (e.payload && e.payload.message) || e.message,
        variant: 'danger',
      });
      return false;
    }
    if (boxes.length === 0) {
      await C.alertDialog({
        title: t('No shared mailbox'),
        message: t('Connect a shared mailbox in Workspace settings, or use Open mail instead.'),
        variant: 'info',
      });
      return false;
    }
    const picked = await pickMailbox(boxes, wsId);
    if (!picked || picked.id < 1) return false;
    const mailboxId = picked.id;
    const label = picked.label;
    const attachNote = attachmentFileIds.length
      ? ' ' + t('(%s file(s) attached)', [String(attachmentFileIds.length)])
      : '';
    const ok = await C.confirmDialog({
      title: t('Send from shared mailbox?'),
      message: t('This sends as %s. Everyone in the workspace who can read mail can see it.', [label]) + attachNote,
      confirmLabel: t('Send'),
      cancelLabel: t('Cancel'),
      variant: 'info',
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
        idempotencyKey: key,
      };
      if (companyId > 0) payload.companyId = companyId;
      if (attachmentFileIds.length) payload.attachmentFileIds = attachmentFileIds;
      await Api.post('/workspaces/' + wsId + '/mail/send', payload);
      inflightKeys.delete(fp);
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Message sent from the shared mailbox.');
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
   * @param {{to?:string,subject?:string,bodyText?:string,mailAppUrl?:string,mailtoUrl?:string}} pack
   * @param {{companyId?:number,title?:string}} [opts]
   */
  async function offerPreparedMail(pack, opts) {
    const C = window.CustomerCheckComponents || window.CrmComponents;
    if (!C) {
      const url = (pack && (pack.mailAppUrl || pack.mailtoUrl)) || '';
      if (url) window.open(url, '_blank', 'noopener,noreferrer');
      return;
    }
    const title = (opts && opts.title) || t('Prepared message');
    const companyId = (opts && opts.companyId) || 0;
    const openHref = (typeof C.safeExternalHref === 'function')
      ? C.safeExternalHref((pack && (pack.mailAppUrl || pack.mailtoUrl)) || '#')
      : '#';
    const dialogKey = makeIdempotencyKey();
    const dialogId = 'crm-prepared-mail-' + Date.now();
    /** @type {Array<{id:number,name:string}>} */
    var attachments = [];
    await C.openDialog({
      id: dialogId,
      title: title,
      content:
        '<div class="crm-callout crm-callout--info" role="status">' +
        '<p>' + esc(t('Shared mailbox: everyone in this workspace who can read mail can see messages you send from here.')) + '</p>' +
        '</div>' +
        '<p><strong>' + esc((pack && pack.subject) || '') +
        '</strong></p><pre class="crm-pre">' +
        esc((pack && pack.bodyText) || '') +
        '</pre>' +
        '<div class="form-group">' +
        '<span class="form-label">' + esc(t('Attachments (optional)')) + '</span>' +
        '<p class="crm-muted">' + esc(t('PDF, PNG, JPEG or plain text — max 5 files, 10 MiB each.')) + '</p>' +
        '<ul id="' + dialogId + '-attach-list" class="crm-list" role="list" aria-live="polite"></ul>' +
        '<div class="crm-form-actions crm-form-actions--start">' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-crm-attach-file>' + esc(t('Add file')) + '</button></div>' +
        '</div>' +
        '<div class="crm-form-actions">' +
        '<a class="crm-btn crm-btn--primary" href="' + esc(openHref) + '" rel="noopener noreferrer">' + esc(t('Open mail')) + '</a> ' +
        '<button type="button" class="crm-btn crm-btn--secondary" data-crm-shared-send>' + esc(t('Send from shared mailbox')) + '</button></div>',
      size: 'md',
    });

    function renderAttachList() {
      const list = document.getElementById(dialogId + '-attach-list');
      if (!list) return;
      if (!attachments.length) {
        list.innerHTML = '<li class="crm-muted">' + esc(t('No files attached.')) + '</li>';
        return;
      }
      list.innerHTML = attachments.map(function (a, idx) {
        return '<li role="listitem">' + esc(a.name || ('file ' + a.id)) +
          ' <button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-detach="' + idx + '">' +
          esc(t('Remove')) + '</button></li>';
      }).join('');
      list.querySelectorAll('[data-crm-detach]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const i = parseInt(String(btn.getAttribute('data-crm-detach') || '-1'), 10);
          if (i >= 0) {
            attachments.splice(i, 1);
            renderAttachList();
          }
        });
      });
    }
    renderAttachList();

    const attachBtn = document.querySelector('#' + dialogId + ' [data-crm-attach-file], [data-crm-attach-file]');
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
          mimeTypes: ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'],
          title: t('Select attachment'),
        });
        if (!picked || picked.unavailable) {
          if (picked && picked.unavailable) {
            await C.alertDialog({
              title: t('Files picker unavailable'),
              message: t('Open Files, then try again.'),
              variant: 'warning',
            });
          }
          return;
        }
        const fid = parseInt(String(picked.fileId || 0), 10);
        if (fid < 1) return;
        if (attachments.some(function (a) { return a.id === fid; })) return;
        attachments.push({ id: fid, name: String(picked.name || ('file ' + fid)) });
        renderAttachList();
      });
    }

    const sendBtn = document.querySelector('#' + dialogId + ' [data-crm-shared-send], [data-crm-shared-send]');
    if (sendBtn) {
      sendBtn.addEventListener('click', async () => {
        if (sendBtn.disabled) return;
        sendBtn.disabled = true;
        const sent = await sendViaSharedMailbox(pack, {
          companyId: companyId,
          idempotencyKey: dialogKey,
          attachmentFileIds: attachments.map(function (a) { return a.id; }),
        });
        if (!sent) sendBtn.disabled = false;
        else sendBtn.textContent = t('Sent');
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
