/**
 * Pure helpers for deal-checklist extreme simplicity (vitest + browser bootstrap).
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/** Matches companion mobile default evidence note. */
export const DEFAULT_CHECKLIST_DONE_NOTE = 'Done';

/**
 * Typed REPLACE confirm is forbidden — open rows are deleted and replaced; done history is kept.
 * @returns {false}
 */
export function requireTypedReplaceConfirm() {
  return false;
}

/**
 * @param {Array<{ id?: number|string, name?: string }>} templates
 * @param {{ t: (msg: string, args?: string[]) => string, escapeHtml: (s: string) => string }} helpers
 * @returns {string}
 */
export function buildTemplateStarterButtonsHtml(templates, helpers) {
  const t = helpers.t;
  const escapeHtml = helpers.escapeHtml;
  const list = Array.isArray(templates) ? templates : [];
  if (list.length === 0) {
    return '';
  }
  let html =
    '<div class="crm-deal-checklist-starters" role="group" aria-label="' +
    escapeHtml(t('Start from a template')) +
    '">';
  list.slice(0, 8).forEach((tm) => {
    const id = String(tm.id ?? '');
    if (!id) return;
    const name = String(tm.name || tm.id || '');
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

/**
 * @param {{
 *   id: number|string,
 *   title?: string,
 *   status?: string,
 *   statusLabel?: string,
 *   assigneeUserId?: string|null,
 *   dueOn?: string|null,
 *   files?: Array<{ name?: string, fileId?: number|string, available?: boolean }>,
 *   updatedAt?: string|null,
 * }} item
 * @param {{
 *   t: (msg: string, args?: string[]) => string,
 *   escapeHtml: (s: string) => string,
 *   readOnly?: boolean,
 *   dealTitle?: string,
 * }} helpers
 * @returns {string}
 */
export function buildChecklistItemRowHtml(item, helpers) {
  const t = helpers.t;
  const escapeHtml = helpers.escapeHtml;
  const readOnly = !!helpers.readOnly;
  const dealTitle = String(helpers.dealTitle || '');
  const open = String(item.status || '') === 'open';
  const files = Array.isArray(item.files) ? item.files : [];
  const fileLabels = files
    .map((f) => {
      if (f && f.available === false) {
        return t('File unavailable');
      }
      return String((f && (f.name || f.fileId)) || '');
    })
    .filter(Boolean);
  const fileBits = fileLabels.length ? escapeHtml(t('Files: %s', [fileLabels.join(', ')])) : '';
  const assignee = item.assigneeUserId ? String(item.assigneeUserId) : '';
  const doneAria =
    dealTitle !== ''
      ? t('Done: %s on deal %s', [String(item.title || ''), dealTitle]) +
        (assignee ? ' — ' + t('Assigned to %s', [assignee]) : '')
      : t('Done: %s', [String(item.title || '')]) +
        (assignee ? ' — ' + t('Assigned to %s', [assignee]) : '');
  let html =
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
    (assignee
      ? '<p class="crm-muted">' + escapeHtml(t('Assigned to %s', [assignee])) + '</p>'
      : '') +
    (item.dueOn ? '<p class="crm-muted">' + escapeHtml(t('Due %s', [String(item.dueOn)])) + '</p>' : '') +
    (fileBits ? '<p class="crm-muted">' + fileBits + '</p>' : '') +
    '</div>';
  if (open && !readOnly) {
    html +=
      '<div class="crm-deal-checklist__actions">' +
      '<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-cl-done="' +
      escapeHtml(String(item.id)) +
      '" aria-label="' +
      escapeHtml(doneAria) +
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

/**
 * @param {{
 *   dealId: number|string,
 *   itemsHtml: string,
 *   startersHtml?: string,
 *   empty: boolean,
 *   readOnly?: boolean,
 * }} opts
 * @param {{ t: (msg: string, args?: string[]) => string, escapeHtml: (s: string) => string }} helpers
 * @returns {string}
 */
export function buildChecklistPanelHtml(opts, helpers) {
  const t = helpers.t;
  const escapeHtml = helpers.escapeHtml;
  const readOnly = !!opts.readOnly;
  const emptyHint = opts.empty
    ? '<div class="crm-empty-state" role="status">' +
      '<p class="crm-empty-state__title">' +
      escapeHtml(t('No tasks yet')) +
      '</p>' +
      '<p class="crm-empty-state__text">' +
      escapeHtml(
        readOnly
          ? t('No checklist tasks on this deal.')
          : t('Tap a template below, or type one task.'),
      ) +
      '</p></div>'
    : '';
  let html =
    '<div class="crm-deal-checklist-panel' +
    (readOnly ? ' crm-deal-checklist-panel--readonly' : '') +
    '" data-deal-id="' +
    escapeHtml(String(opts.dealId)) +
    '"' +
    (readOnly ? ' data-cl-readonly="1"' : '') +
    '>' +
    '<p class="crm-muted">' +
    escapeHtml(
      readOnly
        ? t('Checklist tasks on this deal (view only).')
        : t('Who does what on this deal — tap Done when finished.'),
    ) +
    '</p>' +
    emptyHint +
    (readOnly ? '' : opts.startersHtml || '') +
    opts.itemsHtml;
  if (!readOnly) {
    html +=
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
      '</div>';
  } else {
    html +=
      '<div class="crm-form-actions crm-deal-checklist-footer">' +
      '<button type="button" class="crm-btn crm-btn--ghost" data-cl-close>' +
      escapeHtml(t('Close')) +
      '</button>' +
      '</div>';
  }
  html += '</div>';
  return html;
}
