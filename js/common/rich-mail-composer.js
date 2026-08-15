/**
 * Accessible rich HTML mail composer (MH-16 / WCAG 2.1 AA).
 * Toolbar uses document.execCommand on a labeled contenteditable region.
 * No image insert in message body (AS-18) — Files attachments only.
 * Signature editors may insert https logos via allowSignatureLogo (AS-18 / Argus SF-01).
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

  function insertHtmlAtCursor(editor, html) {
    if (!editor || !html) return;
    editor.focus();
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

  /**
   * Create a public read link for a Files path so email clients can load the logo.
   * @param {string} path
   * @returns {Promise<string>} absolute image src or ''
   */
  function createPublicLogoSrc(path) {
    const filePath = String(path || '').trim();
    if (!filePath) {
      return Promise.resolve('');
    }
    const Logo = logoApi();
    const ocsBase = (window.OC && typeof OC.linkToOCS === 'function')
      ? OC.linkToOCS('apps/files_sharing/api/v1', 2)
      : '/ocs/v2.php/apps/files_sharing/api/v1';
    const url = String(ocsBase).replace(/\/?$/, '/') + 'shares';
    const body = {
      path: filePath.charAt(0) === '/' ? filePath : ('/' + filePath),
      shareType: 3,
      permissions: 1,
    };
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'OCS-APIREQUEST': 'true',
        requesttoken: requestToken(),
      },
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) {
          throw new Error((data && data.ocs && data.ocs.meta && data.ocs.meta.message) || t('Could not create a public link for this file.'));
        }
        const shareUrl = data && data.ocs && data.ocs.data ? String(data.ocs.data.url || '') : '';
        let src = Logo && Logo.publicShareToImageSrc
          ? Logo.publicShareToImageSrc(shareUrl)
          : (shareUrl ? (shareUrl.replace(/\/+$/, '') + '/download') : '');
        if (src && src.indexOf('http') !== 0 && window.location && window.location.origin) {
          src = window.location.origin + (src.charAt(0) === '/' ? src : '/' + src);
        }
        return src;
      });
    });
  }

  /**
   * @param {HTMLElement} editor
   * @returns {Promise<void>}
   */
  function promptInsertLogoLink(editor) {
    const Logo = logoApi();
    const C = window.CustomerCheckComponents;
    const finish = function (rawUrl) {
      const html = Logo && Logo.buildSignatureLogoHtml
        ? Logo.buildSignatureLogoHtml(rawUrl, { alt: t('Logo'), width: 180 })
        : '';
      if (!html) {
        if (C && C.alertDialog) {
          return C.alertDialog({
            title: t('Could not add logo'),
            message: t('Use a public https:// image address (not http or data:).'),
            variant: 'danger',
          });
        }
        return Promise.resolve();
      }
      insertHtmlAtCursor(editor, html);
      return Promise.resolve();
    };
    if (C && typeof C.confirmDialog === 'function') {
      return C.confirmDialog({
        title: t('Add logo'),
        message: t('Paste a public https image link. Recipients load this image when they open your email.'),
        requireReason: true,
        reasonLabel: t('Image address (https://…)'),
        confirmLabel: t('Insert logo'),
        cancelLabel: t('Cancel'),
        variant: 'info',
      }).then(function (result) {
        if (!result || !result.confirmed) return;
        return finish(result.reason || '');
      });
    }
    const url = window.prompt(t('Image address (https://…)'), 'https://');
    if (!url) return Promise.resolve();
    return finish(url);
  }

  /**
   * @param {HTMLElement} editor
   * @returns {Promise<void>}
   */
  function pickLogoFromFiles(editor) {
    const Picker = window.CustomerCheckFolderPicker;
    const Logo = logoApi();
    const C = window.CustomerCheckComponents;
    if (!Picker || typeof Picker.pickFile !== 'function') {
      return promptInsertLogoLink(editor);
    }
    // One ack dialog → Files picker. Paste-link stays on the Logo toolbar button.
    const ack = C && typeof C.confirmDialog === 'function'
      ? C.confirmDialog({
        title: t('Add logo'),
        message: t('Pick an image from Files (creates a public https link) or paste a public https image address afterward if the picker is unavailable.'),
        requireCheckbox: true,
        checkboxLabel: t('I understand this creates a public link to the image.'),
        confirmLabel: t('Choose from Files'),
        cancelLabel: t('Cancel'),
        variant: 'info',
      })
      : Promise.resolve({ confirmed: true });
    return ack.then(function (ok) {
      if (ok === false) return null;
      if (ok && typeof ok === 'object' && !ok.confirmed) return null;
      return Picker.pickFile({
        mimeTypes: ['image/png', 'image/jpeg', 'image/jpg'],
        title: t('Choose a logo image'),
      });
    }).then(function (picked) {
      if (picked == null) return null;
      if (!picked || picked.unavailable) {
        return promptInsertLogoLink(editor);
      }
      const path = String(picked.path || '').trim();
      if (!path) return null;
      return createPublicLogoSrc(path).then(function (src) {
        const html = Logo && Logo.buildSignatureLogoHtml
          ? Logo.buildSignatureLogoHtml(src, { alt: t('Logo'), width: 180 })
          : '';
        if (!html) {
          throw new Error(t('Logo links must use https. Serve Nextcloud over HTTPS, or paste an https:// image address.'));
        }
        insertHtmlAtCursor(editor, html);
      });
    }).catch(function (err) {
      const msg = (err && err.message) ? String(err.message) : t('Could not add logo from Files.');
      if (C && C.alertDialog) {
        return C.alertDialog({ title: t('Could not add logo'), message: msg, variant: 'danger' });
      }
      return undefined;
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
      toolbarHtml =
        toolbarBtn(uid + '-b', 'bold', t('Bold'), null, 'B') +
        toolbarBtn(uid + '-i', 'italic', t('Italic'), null, 'I') +
        toolbarBtn(uid + '-u', 'underline', t('Underline'), null, 'U') +
        toolbarBtn(uid + '-ul', 'insertUnorderedList', t('Bullet list'), null, '•') +
        toolbarBtn(uid + '-link', 'createLink', t('Link'), null, 'URL') +
        toolbarBtn(uid + '-logo', 'insertLogo', t('Add logo'), null, t('Logo')) +
        toolbarBtn(uid + '-logo-file', 'insertLogoFile', t('Logo from Files'), null, t('File')) +
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
          ? (
            toolbarBtn(uid + '-logo', 'insertLogo', t('Add logo'), null, t('Logo')) +
            toolbarBtn(uid + '-logo-file', 'insertLogoFile', t('Logo from Files'), null, t('File'))
          )
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
          void promptInsertLogoLink(editor);
          return;
        }
        if (cmd === 'insertLogoFile') {
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
