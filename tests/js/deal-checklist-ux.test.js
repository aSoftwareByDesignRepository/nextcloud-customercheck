/**
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import {
  DEFAULT_CHECKLIST_DONE_NOTE,
  buildChecklistItemRowHtml,
  buildChecklistPanelHtml,
  buildTemplateStarterButtonsHtml,
  requireTypedReplaceConfirm,
} from '../../js/utils/deal-checklist-ux.js';

const escapeHtml = (s) =>
  String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const t = (msg, args = []) => {
  let out = msg;
  args.forEach((a) => {
    out = out.replace('%s', a);
  });
  return out;
};

const helpers = { t, escapeHtml };

describe('deal-checklist extreme simplicity UX', () => {
  it('uses companion-parity default Done note and forbids typed REPLACE', () => {
    expect(DEFAULT_CHECKLIST_DONE_NOTE).toBe('Done');
    expect(requireTypedReplaceConfirm()).toBe(false);
  });

  it('empty panel exposes one-click template starters + inline quick-add', () => {
    const starters = buildTemplateStarterButtonsHtml(
      [
        { id: 1, name: 'License order' },
        { id: 2, name: 'Hardware / goods order' },
      ],
      helpers,
    );
    expect(starters).toContain('data-cl-apply-tmpl="1"');
    expect(starters).toContain('crm-btn--lg');
    expect(starters).toContain('Use template: License order');

    const panel = buildChecklistPanelHtml(
      { dealId: 9, itemsHtml: '', startersHtml: starters, empty: true },
      helpers,
    );
    expect(panel).toContain('data-cl-quickadd');
    expect(panel).toContain('Tap a template below, or type one task.');
    expect(panel).toContain('data-cl-template');
    expect(panel).toContain('More templates');
    expect(panel).not.toContain('data-cl-add');
    expect(panel).toMatch(/min-height|crm-btn--lg|crm-input/);
  });

  it('open item exposes giant Done first; Assign/Attach under More', () => {
    const row = buildChecklistItemRowHtml(
      {
        id: 42,
        title: 'Send invoice',
        status: 'open',
        statusLabel: 'Open',
        updatedAt: '2026-08-11T10:00:00+00:00',
      },
      helpers,
    );
    expect(row).toContain('data-cl-done="42"');
    expect(row).toContain('crm-btn--lg');
    expect(row).toContain('Done: Send invoice');
    expect(row).toContain('data-cl-done-note="42"');
    expect(row).toContain('crm-more-menu');
    expect(row).toContain('data-cl-assign="42"');
    expect(row).toContain('data-cl-file="42"');
    const doneIdx = row.indexOf('data-cl-done="42"');
    const moreIdx = row.indexOf('crm-more-menu');
    expect(doneIdx).toBeGreaterThan(-1);
    expect(moreIdx).toBeGreaterThan(doneIdx);
  });

  it('closed items have no Done action (no dead-end retries)', () => {
    const row = buildChecklistItemRowHtml(
      { id: 7, title: 'Paid', status: 'done', statusLabel: 'Done' },
      helpers,
    );
    expect(row).toContain('crm-deal-checklist__item--closed');
    expect(row).not.toContain('data-cl-done=');
  });

  it('panel a11y tree has labelled quick-add input and live-region-friendly structure', () => {
    const panel = buildChecklistPanelHtml(
      {
        dealId: 3,
        empty: false,
        itemsHtml: '<ul class="crm-deal-checklist" role="list"></ul>',
        startersHtml: '',
      },
      helpers,
    );
    expect(panel).toContain('for="crm-cl-quick-3"');
    expect(panel).toContain('aria-required="true"');
    expect(panel).toContain('role="list"');
    expect(panel).toContain('tap Done when finished');
  });
});
