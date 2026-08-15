/**
 * Accessible rich HTML mail composer (MH-16 / WCAG 2.1 AA).
 * Toolbar uses document.execCommand on a labeled contenteditable region.
 * No image insert in message body (AS-18) — Files attachments only.
 * Signature editors: Logo opens Files picker (WYSIWYG); public https share under the hood (AS-18 / Argus SF-01).
 * Reply quotes live in a separate editable region (US-010 AC-5 / AS-19).
 */
(function () {
  'use strict';

  function t(msg, args) {
    const list = Array.isArray(args) ? args : [];
    let out = window.t ? window.t('customercheck', msg, list) : msg;
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
    return String(s || '').replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }

  function plainToEditorHtml(plain) {
    const text = String(plain || '');
    if (text === '') {
      return '<p><br></p>';
    }
    return '<p>' + esc(text).replace(/\n/g, '<br>') + '</p>';
  }

  function plainToQuoteHtml(plain) {
    const text = String(plain || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (text === '') {
      return '';
    }
    const lines = text.split('\n');
    const parts = [];
    for (let i = 0; i < lines.length; i += 1) {
      // Strip plain-text quote markers — outer <blockquote> already marks the quote (AS-19).
      let line = String(lines[i] || '')
        .replace(/^(?:>\s*)+/, '')
        .replace(/^(&gt;\s*)+/i, '')
        .replace(/\u00a0/g, ' ');
      if (line === '') {
        parts.push('<br>');
      } else {
        parts.push('<p>' + esc(line) + '</p>');
      }
    }
    // No outer <blockquote> here — MailSendService wraps quotedHtml once (AS-19).
    return parts.join('');
  }

  function nodeToPlain(root) {
    if (!root) return '';
    const clone = root.cloneNode(true);
    clone.querySelectorAll('br').forEach(function (br) {
      br.replaceWith(document.createTextNode('\n'));
    });
    clone.querySelectorAll('p,div,li,h2,h3,blockquote').forEach(function (el) {
      el.appendChild(document.createTextNode('\n'));
    });
    return String(clone.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
  }

  function logoApi() {
    return window.CustomerCheckSignatureLogo || null;
  }

  function saveEditorSelection(editor) {
    if (!editor || !window.getSelection) return null;
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return null;
    const range = sel.getRangeAt(0);
    if (!editor.contains(range.commonAncestorContainer)) return null;
    return range.cloneRange();
  }

  function restoreEditorSelection(editor, range) {
    if (!editor) return;
    editor.focus();
    const sel = window.getSelection && window.getSelection();
    if (!sel) return;
    sel.removeAllRanges();
    if (range) {
      try {
        sel.addRange(range);
        return;
      } catch (_e) { /* fall through to end */ }
    }
    // Caret at end so insertHTML still lands in the signature.
    const end = document.createRange();
    end.selectNodeContents(editor);
    end.collapse(false);
    sel.addRange(end);
  }

  function insertHtmlAtCursor(editor, html, savedRange) {
    if (!editor || !html) return;
    restoreEditorSelection(editor, savedRange || null);
    try {
      if (document.queryCommandSupported && document.queryCommandSupported('insertHTML')) {
        document.execCommand('insertHTML', false, html);
        return;
      }
    } catch (_e) { /* fall through */ }
    editor.insertAdjacentHTML('beforeend', html);
  }

  function requestToken() {
    const meta = document.querySelector('head[data-requesttoken], meta[name="requesttoken"]');
    if (meta) {
      const fromHead = meta.getAttribute('data-requesttoken');
      if (fromHead) return fromHead;
      if (meta.getAttribute('content')) return meta.getAttribute('content');
    }
    return (window.OC && OC.requestToken) ? String(OC.requestToken) : '';
  }

  function sharesApiUrl(query) {
    const ocsBase = (window.OC && typeof OC.linkToOCS === 'function')
      ? OC.linkToOCS('apps/files_sharing/api/v1', 2)
      : '/ocs/v2.php/apps/files_sharing/api/v1';
    const base = String(ocsBase).replace(/\/?$/, '/') + 'shares';
    const q = query ? String(query).replace(/^\?/, '') : 'format=json';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + q;
  }

  function ocsHeaders() {
    return {
      'OCS-APIREQUEST': 'true',
      requesttoken: requestToken(),
      Accept: 'application/json',
    };
  }

  function absoluteShareSrc(shareUrl) {
    const Logo = logoApi();
    const origin = (window.location && window.location.origin) ? String(window.location.origin) : '';
    const opts = { pageOrigin: origin };
    let src = Logo && Logo.publicShareToImageSrc
      ? Logo.publicShareToImageSrc(shareUrl, opts)
      : '';
    if (!src && shareUrl) {
      let abs = String(shareUrl).trim().replace(/\/+$/, '');
      if (abs && abs.indexOf('http') !== 0 && origin) {
        abs = origin + (abs.charAt(0) === '/' ? abs : '/' + abs);
      }
      if (abs && !/\/download$/i.test(abs)) {
        abs += '/download';
      }
      src = Logo && Logo.publicShareToImageSrc
        ? Logo.publicShareToImageSrc(abs, opts)
        : (Logo && Logo.isPublicImageUrl && Logo.isPublicImageUrl(abs, opts) ? abs : '');
    }
    return src;
  }

  function shareUrlFromOcsPayload(data) {
    const Logo = logoApi();
    if (Logo && typeof Logo.shareUrlFromOcsData === 'function') {
      return Logo.shareUrlFromOcsData(data);
    }
    if (!data || !data.ocs || data.ocs.data == null) return '';
    const payload = data.ocs.data;
    if (Array.isArray(payload)) {
      for (let i = 0; i < payload.length; i += 1) {
        const row = payload[i];
        if (row && Number(row.share_type) === 3 && row.url) {
          return String(row.url);
        }
      }
      return '';
    }
    return String(payload.url || '');
  }

  /**
   * Public read link for a Files path so email clients can load the logo.
   * OCS Share create expects form-urlencoded (not JSON).
   * @param {string} path
   * @returns {Promise<string>} absolute image src or ''
   */
  function createPublicLogoSrc(path) {
    const filePath = String(path || '').trim();
    if (!filePath) {
      return Promise.resolve('');
    }
    const normalized = filePath.charAt(0) === '/' ? filePath : ('/' + filePath);
    const listUrl = sharesApiUrl(
      'format=json&path=' + encodeURIComponent(normalized) + '&reshares=true',
    );
    return fetch(listUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: ocsHeaders(),
    }).then(function (listRes) {
      return listRes.json().catch(function () { return null; }).then(function (listData) {
        const existing = listRes.ok ? shareUrlFromOcsPayload(listData) : '';
        if (existing) {
          return absoluteShareSrc(existing);
        }
        const body = new URLSearchParams();
        body.set('path', normalized);
        body.set('shareType', '3');
        body.set('permissions', '1');
        return fetch(sharesApiUrl('format=json'), {
          method: 'POST',
          credentials: 'same-origin',
          headers: Object.assign({}, ocsHeaders(), {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          }),
          body: body.toString(),
        }).then(function (res) {
          return res.json().then(function (data) {
            const status = data && data.ocs && data.ocs.meta ? Number(data.ocs.meta.statuscode) : 0;
            if (!res.ok || (status && status >= 400)) {
              throw new Error(
                (data && data.ocs && data.ocs.meta && data.ocs.meta.message)
                  || t('Could not create a public link for this file.'),
              );
            }
            const shareUrl = shareUrlFromOcsPayload(data);
            if (!shareUrl) {
              throw new Error(t('Could not create a public link for this file.'));
            }
            return absoluteShareSrc(shareUrl);
          });
        });
      });
    });
  }

  function setLogoInsertBusy(editor, busy) {
    const shell = editor && editor.closest ? editor.closest('[data-crm-rich-mail]') : null;
    if (!shell) return;
    shell.setAttribute('data-crm-logo-busy', busy ? '1' : '0');
    const btn = shell.querySelector('[data-crm-rich-cmd="insertLogo"]');
    if (btn) {
      btn.disabled = !!busy;
      btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
    const live = shell.querySelector('[data-crm-logo-live]');
    // Only announce "Adding…" while busy — success text is set by the insert path.
    if (live && busy) {
      live.textContent = t('Adding logo…');
    }
  }

  /**
   * WYSIWYG logo insert: open Files picker, public-share the image, insert <img>.
   * Single-flight: ignores re-entry while a pick/share is in progress (race/double-click).
   * @param {HTMLElement} editor
   * @returns {Promise<void>}
   */
  function pickLogoFromFiles(editor) {
    const Picker = window.CustomerCheckFolderPicker;
    const Logo = logoApi();
    const C = window.CustomerCheckComponents;
    const shell = editor && editor.closest ? editor.closest('[data-crm-rich-mail]') : null;
    if (shell && shell.getAttribute('data-crm-logo-busy') === '1') {
      return Promise.resolve();
    }
    const savedRange = saveEditorSelection(editor);
    if (!Picker || typeof Picker.pickFile !== 'function') {
      if (C && C.alertDialog) {
        return C.alertDialog({
          title: t('Could not add logo'),
          message: t('The Files picker is not available. Upload your logo to Files, then try again.'),
          variant: 'danger',
        });
      }
      return Promise.resolve();
    }
    setLogoInsertBusy(editor, true);
    // Real editor flow: click Logo → pick file → image appears at caret (no URL dialog).
    return Picker.pickFile({
      mimeTypes: ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
      title: t('Choose a logo image'),
    }).then(function (picked) {
      if (picked == null) return null;
      if (!picked || picked.unavailable) {
        throw new Error(t('The Files picker is not available. Upload your logo to Files, then try again.'));
      }
      const path = String(picked.path || '').trim();
      if (!path) return null;
      return createPublicLogoSrc(path).then(function (src) {
        const origin = (window.location && window.location.origin) ? String(window.location.origin) : '';
        const html = Logo && Logo.buildSignatureLogoHtml
          ? Logo.buildSignatureLogoHtml(src, { alt: t('Logo'), width: 180, pageOrigin: origin })
          : '';
        if (!html) {
          throw new Error(t('Could not use this image as a logo. Pick a Files image on this Nextcloud (creates a public link).'));
        }
        insertHtmlAtCursor(editor, html, savedRange);
        const live = shell && shell.querySelector('[data-crm-logo-live]');
        if (live) live.textContent = t('Logo added');
      });
    }).catch(function (err) {
      const msg = (err && err.message) ? String(err.message) : t('Could not add logo from Files.');
      if (C && C.alertDialog) {
        return C.alertDialog({ title: t('Could not add logo'), message: msg, variant: 'danger' });
      }
      return undefined;
    }).finally(function () {
      setLogoInsertBusy(editor, false);
    });
  }

  /**
   * @param {HTMLElement} host
   * @param {{initialHtml?: string, initialPlain?: string, quotedPlain?: string, labelledBy?: string, includeSignatureDefault?: boolean, hasSignature?: boolean, toolbar?: 'full'|'signature', allowSignatureLogo?: boolean}} [opts]
   * @returns {{getHtml: Function, getPlain: Function, getQuotedPlain: Function, getQuotedHtml: Function, includeSignature: Function, setHtml: Function, destroy: Function}}
   */
  function mount(host, opts) {
    opts = opts || {};
    if (!host) {
      return {
        getHtml: function () { return ''; },
        getPlain: function () { return ''; },
        getQuotedPlain: function () { return ''; },
        getQuotedHtml: function () { return ''; },
        includeSignature: function () { return true; },
        setHtml: function () {},
        destroy: function () {},
      };
    }

    const uid = 'crm-rich-' + Date.now() + '-' + Math.floor(Math.random() * 1e5);
    const hasSig = !!opts.hasSignature;
    const includeDefault = opts.includeSignatureDefault !== false && hasSig;
    const quotedPlain = String(opts.quotedPlain || '').trim();
    const hasQuote = quotedPlain !== '';
    const toolbarMode = opts.toolbar === 'signature' ? 'signature' : 'full';
    const allowLogo = !!opts.allowSignatureLogo || toolbarMode === 'signature';
    const shellClass = 'crm-rich-mail' + (toolbarMode === 'signature' ? ' crm-rich-mail--signature' : '');

    let toolbarHtml = '';
    if (toolbarMode === 'signature') {
      // Signature: essentials only — name/title/link/logo (Q12), not mail-reply chrome.
      // Logo = Files picker (WYSIWYG), not a paste-URL dialog.
      toolbarHtml =
        toolbarBtn(uid + '-b', 'bold', t('Bold'), null, 'B') +
        toolbarBtn(uid + '-i', 'italic', t('Italic'), null, 'I') +
        toolbarBtn(uid + '-u', 'underline', t('Underline'), null, 'U') +
        toolbarBtn(uid + '-ul', 'insertUnorderedList', t('Bullet list'), null, '•') +
        toolbarBtn(uid + '-link', 'createLink', t('Link'), null, 'URL') +
        toolbarBtn(uid + '-logo', 'insertLogo', t('Add logo from Files'), null, t('Logo')) +
        toolbarBtn(uid + '-clear', 'removeFormat', t('Clear formatting'), null, '×');
    } else {
      toolbarHtml =
        toolbarBtn(uid + '-b', 'bold', t('Bold'), null, 'B') +
        toolbarBtn(uid + '-i', 'italic', t('Italic'), null, 'I') +
        toolbarBtn(uid + '-u', 'underline', t('Underline'), null, 'U') +
        toolbarBtn(uid + '-ul', 'insertUnorderedList', t('Bullet list'), null, '•') +
        toolbarBtn(uid + '-ol', 'insertOrderedList', t('Numbered list'), null, '1.') +
        toolbarBtn(uid + '-q', 'formatBlock', t('Quote'), 'blockquote', '“') +
        toolbarBtn(uid + '-h2', 'formatBlock', t('Heading'), 'h2', 'H') +
        toolbarBtn(uid + '-h3', 'formatBlock', t('Subheading'), 'h3', 'h') +
        toolbarBtn(uid + '-link', 'createLink', t('Link'), null, 'URL') +
        (allowLogo
          ? toolbarBtn(uid + '-logo', 'insertLogo', t('Add logo from Files'), null, t('Logo'))
          : '') +
        toolbarBtn(uid + '-clear', 'removeFormat', t('Clear formatting'), null, '×');
    }

    host.innerHTML =
      '<div class="' + shellClass + '" data-crm-rich-mail="1" data-crm-rich-toolbar="' + esc(toolbarMode) + '"' +
      (allowLogo ? ' data-crm-signature-logo="1"' : '') + '>' +
      '<div class="crm-rich-mail__toolbar" role="toolbar" aria-label="' + esc(t('Formatting')) + '">' +
      toolbarHtml +
      '</div>' +
      '<div id="' + uid + '-editor" class="crm-rich-mail__editor" contenteditable="true" role="textbox" ' +
      'aria-multiline="true" ' +
      (opts.labelledBy ? 'aria-labelledby="' + esc(opts.labelledBy) + '" ' : 'aria-label="' + esc(t('Message')) + '" ') +
      'spellcheck="true"></div>' +
      (allowLogo
        ? '<span class="crm-sr-only" data-crm-logo-live="1" aria-live="polite"></span>'
        : '') +
      (hasSig
        ? '<label class="crm-check crm-rich-mail__sig"><input type="checkbox" id="' + uid + '-sig"' +
          (includeDefault ? ' checked' : '') + '> <span>' + esc(t('Include signature')) + '</span></label>' +
          '<span id="' + uid + '-sig-live" class="crm-sr-only" aria-live="polite"></span>'
        : '') +
      (hasQuote
        ? '<div class="crm-rich-mail__quote-panel">' +
          '<p class="crm-rich-mail__quote-label" id="' + uid + '-quote-label">' + esc(t('Original message')) + '</p>' +
          '<div id="' + uid + '-quote" class="crm-rich-mail__quote" contenteditable="true" role="textbox" ' +
          'aria-multiline="true" aria-labelledby="' + uid + '-quote-label" spellcheck="false"></div></div>'
        : '') +
      '</div>';

    const editor = document.getElementById(uid + '-editor');
    const quoteEl = hasQuote ? document.getElementById(uid + '-quote') : null;
    const sigBox = document.getElementById(uid + '-sig');
    const sigLive = document.getElementById(uid + '-sig-live');
    const initial = opts.initialHtml
      ? String(opts.initialHtml)
      : (opts.initialPlain ? plainToEditorHtml(opts.initialPlain) : '');
    if (editor) {
      editor.innerHTML = initial || '<p><br></p>';
    }
    if (quoteEl) {
      quoteEl.innerHTML = plainToQuoteHtml(quotedPlain);
    }
    if (sigBox && sigLive) {
      sigBox.addEventListener('change', function () {
        sigLive.textContent = sigBox.checked ? t('Signature included') : t('Signature off');
      });
    }

    host.querySelectorAll('[data-crm-rich-cmd]').forEach(function (btn) {
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        const cmd = btn.getAttribute('data-crm-rich-cmd');
        const arg = btn.getAttribute('data-crm-rich-arg') || undefined;
        if (!editor) return;
        editor.focus();
        if (cmd === 'insertLogo') {
          void pickLogoFromFiles(editor);
          return;
        }
        if (cmd === 'createLink') {
          const url = window.prompt(t('Link address (https://…)'), 'https://');
          if (!url) return;
          const trimmed = String(url).trim();
          if (!/^https?:\/\//i.test(trimmed) && !/^mailto:/i.test(trimmed)) {
            return;
          }
          document.execCommand('createLink', false, trimmed);
          return;
        }
        if (cmd === 'formatBlock' && arg) {
          document.execCommand('formatBlock', false, arg);
          return;
        }
        document.execCommand(cmd, false, arg);
      });
    });

    return {
      getHtml: function () {
        return editor ? String(editor.innerHTML || '').trim() : '';
      },
      getPlain: function () {
        return nodeToPlain(editor);
      },
      getQuotedPlain: function () {
        return nodeToPlain(quoteEl);
      },
      getQuotedHtml: function () {
        if (!quoteEl) return '';
        return String(quoteEl.innerHTML || '').trim();
      },
      includeSignature: function () {
        // No checkbox ⇒ default ON. Server no-ops when the chosen mailbox has no signature.
        // Never force false just because a different "preferred" account looked empty —
        // that dropped personal signatures when Team was listed first in send-accounts.
        if (!sigBox) {
          return true;
        }
        return !!sigBox.checked;
      },
      setHtml: function (html) {
        if (editor) editor.innerHTML = html || '<p><br></p>';
      },
      destroy: function () {
        host.textContent = '';
      },
    };
  }

  function toolbarBtn(id, cmd, label, arg, shortLabel) {
    const visible = shortLabel != null ? shortLabel : label;
    return '<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost crm-rich-mail__tool" id="' + esc(id) +
      '" data-crm-rich-cmd="' + esc(cmd) + '"' +
      (arg ? ' data-crm-rich-arg="' + esc(arg) + '"' : '') +
      ' aria-label="' + esc(label) + '" title="' + esc(label) + '">' + esc(visible) + '</button> ';
  }

  window.CustomerCheckRichMailComposer = { mount: mount };
})();
