/**
 * Browser bootstrap for deal-checklist-ux utils.
 * Keep aligned with js/utils/deal-checklist-ux.js.
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

  var DEFAULT_CHECKLIST_DONE_NOTE = 'Done';

  function requireTypedReplaceConfirm() {
    return false;
  }

  function buildTemplateStarterButtonsHtml(templates, helpers) {
    var t = sprintfT(helpers.t);
    var escapeHtml = helpers.escapeHtml;
    var list = Array.isArray(templates) ? templates : [];
    if (list.length === 0) {
      return '';
    }
    var html =
      '<div class="crm-deal-checklist-starters" role="group" aria-label="' +
      escapeHtml(t('Start from a template')) +
      '">';
    list.slice(0, 8).forEach(function (tm) {
      var id = String(tm.id == null ? '' : tm.id);
      if (!id) return;
      var name = String(tm.name || tm.id || '');
      html +=
        '<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg crm-deal-checklist-starter" data-cl-apply-tmpl="' +
        escapeHtml(id) +
        '" aria-label="' +
        escapeHtml(t('Use template: %s', [name])) +
        '">' +
        escapeHtml(name) +
        '</button>';
    });
    html += '</div>';
    return html;
  }

  function buildChecklistItemRowHtml(item, helpers) {
    var t = sprintfT(helpers.t);
    var escapeHtml = helpers.escapeHtml;
    var open = String(item.status || '') === 'open';
    var files = Array.isArray(item.files) ? item.files : [];
    var fileBits = files
      .map(function (f) {
        return escapeHtml(String(f.name || f.fileId || ''));
      })
      .filter(Boolean)
      .join(', ');
    var html =
      '<li class="crm-deal-checklist__item' +
      (open ? ' crm-deal-checklist__item--open' : ' crm-deal-checklist__item--closed') +
      '" data-item-id="' +
      escapeHtml(String(item.id)) +
      '" data-updated-at="' +
      escapeHtml(String(item.updatedAt || '')) +
      '">' +
      '<div class="crm-deal-checklist__main">' +
      '<strong class="crm-deal-checklist__title">' +
      escapeHtml(String(item.title || '')) +
      '</strong> ' +
      '<span class="crm-badge">' +
      escapeHtml(String(item.statusLabel || item.status || '')) +
      '</span>' +
      (item.assigneeUserId
        ? '<p class="crm-muted">' + escapeHtml(t('Assigned to %s', [String(item.assigneeUserId)])) + '</p>'
        : '') +
      (item.dueOn ? '<p class="crm-muted">' + escapeHtml(t('Due %s', [String(item.dueOn)])) + '</p>' : '') +
      (fileBits ? '<p class="crm-muted">' + escapeHtml(t('Files: %s', [fileBits])) + '</p>' : '') +
      '</div>';
    if (open) {
      html +=
        '<div class="crm-deal-checklist__actions">' +
        '<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-cl-done="' +
        escapeHtml(String(item.id)) +
        '" aria-label="' +
        escapeHtml(t('Done: %s', [String(item.title || '')])) +
        '">' +
        escapeHtml(t('Done')) +
        '</button>' +
        '<details class="crm-more-menu">' +
        '<summary class="crm-btn crm-btn--ghost crm-btn--sm">' +
        escapeHtml(t('More')) +
        '</summary>' +
        '<div class="crm-more-menu__panel" role="group" aria-label="' +
        escapeHtml(t('More checklist actions')) +
        '">' +
        '<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-cl-done-note="' +
        escapeHtml(String(item.id)) +
        '">' +
        escapeHtml(t('Done with note')) +
        '</button>' +
        '<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-cl-assign="' +
        escapeHtml(String(item.id)) +
        '">' +
        escapeHtml(t('Assign')) +
        '</button>' +
        '<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-cl-file="' +
        escapeHtml(String(item.id)) +
        '">' +
        escapeHtml(t('Attach file')) +
        '</button>' +
        '</div></details></div>';
    }
    html += '</li>';
    return html;
  }

  function buildChecklistPanelHtml(opts, helpers) {
    var t = sprintfT(helpers.t);
    var escapeHtml = helpers.escapeHtml;
    var emptyHint = opts.empty
      ? '<div class="crm-empty-state" role="status">' +
        '<p class="crm-empty-state__title">' +
        escapeHtml(t('No tasks yet')) +
        '</p>' +
        '<p class="crm-empty-state__text">' +
        escapeHtml(t('Tap a template below, or type one task.')) +
        '</p></div>'
      : '';
    return (
      '<div class="crm-deal-checklist-panel" data-deal-id="' +
      escapeHtml(String(opts.dealId)) +
      '">' +
      '<p class="crm-muted">' +
      escapeHtml(t('Who does what on this deal — tap Done when finished.')) +
      '</p>' +
      emptyHint +
      (opts.startersHtml || '') +
      opts.itemsHtml +
      '<form class="crm-deal-checklist-quickadd" data-cl-quickadd autocomplete="off">' +
      '<label class="crm-sr-only" for="crm-cl-quick-' +
      escapeHtml(String(opts.dealId)) +
      '">' +
      escapeHtml(t('New task')) +
      '</label>' +
      '<input id="crm-cl-quick-' +
      escapeHtml(String(opts.dealId)) +
      '" class="crm-input" name="title" type="text" maxlength="160" required ' +
      'placeholder="' +
      escapeHtml(t('What needs doing?')) +
      '" aria-required="true" />' +
      '<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg">' +
      escapeHtml(t('Add')) +
      '</button>' +
      '</form>' +
      '<div class="crm-form-actions crm-deal-checklist-footer">' +
      '<button type="button" class="crm-btn crm-btn--secondary" data-cl-template>' +
      escapeHtml(t('More templates')) +
      '</button> ' +
      '<button type="button" class="crm-btn crm-btn--ghost" data-cl-close>' +
      escapeHtml(t('Close')) +
      '</button>' +
      '</div></div>'
    );
  }

  global.CustomerCheckDealChecklistUx = {
    DEFAULT_CHECKLIST_DONE_NOTE: DEFAULT_CHECKLIST_DONE_NOTE,
    requireTypedReplaceConfirm: requireTypedReplaceConfirm,
    buildTemplateStarterButtonsHtml: buildTemplateStarterButtonsHtml,
    buildChecklistItemRowHtml: buildChecklistItemRowHtml,
    buildChecklistPanelHtml: buildChecklistPanelHtml,
  };
})(typeof window !== 'undefined' ? window : globalThis);
