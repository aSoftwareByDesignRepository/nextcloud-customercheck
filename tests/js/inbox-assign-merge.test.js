/**
 * Inbox ↔ Assign merge — vitest contracts (no browser).
 * Unassigned uses the same 3-pane + Reply compose as Personal/Team (no embedded review board).
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(join(root, rel), 'utf8');

describe('Inbox Assign merge (unified chrome)', () => {
  it('sales-inbox Unassigned stays in the mail 3-pane with decide + Reply', () => {
    const tpl = read('templates/sales-inbox.php');
    expect(tpl).toContain('crm-sales-inbox-list-tools');
    expect(tpl).toContain('crm-sales-inbox-cols');
    expect(tpl).toContain('crm-sales-inbox-q');
    expect(tpl).toContain('data-crm-inbox-sort="sent"');
    expect(tpl).toContain('data-crm-inbox-mail-filter="mail"');
    expect(tpl).not.toContain('crm-sales-inbox__list-tools-sort');
    expect(tpl).toContain('crm-sales-inbox-decide');
    expect(tpl).toContain('crm-sales-inbox-assign-form');
    expect(tpl).toContain('crm-sales-inbox-company-picker');
    expect(tpl).toContain('crm-company-list');
    expect(tpl).toContain('crm-sales-inbox-suggest-card');
    expect(tpl).toContain('crm-sales-inbox-open-assign');
    expect(tpl).toContain('crm-sales-inbox-discard');
    expect(tpl).toContain('crm-sales-inbox__status');
    expect(tpl).toContain('id="crm-sales-inbox-reply"');
    expect(tpl).toContain('Unassigned');
    expect(tpl).not.toContain('crm-sales-inbox-assign-panel');
    expect(tpl).not.toContain('data-crm-persona-mount="review"');
    expect(tpl).not.toContain('crm-sales-inbox-search-wrap');
    expect(tpl).toContain('aria-controls="crm-sales-inbox-panel"');

    const js = read('js/pages/sales-inbox.js');
    expect(js).toContain('loadAssignInbox');
    expect(js).toContain('applyListView');
    expect(js).toContain('sortMailRows');
    expect(js).toContain('data-crm-inbox-sort');
    expect(js).toContain('data-crm-inbox-mail-filter');
    expect(js).toContain('mapReviewItemToRow');
    expect(js).toContain('updateDecideZone');
    expect(js).toContain('mountCompanyPanel');
    expect(js).toContain('CustomerCheckCompanyPicker');
    expect(js).toContain('openAssignPanel');
    expect(js).toContain('syncActionBar');
    expect(js).toContain("postAssignAction('assign'");
    expect(js).toContain("postAssignAction('discard'");
    expect(js).toContain("postAssignAction('mark-spam'");
    expect(js).toContain("postInboxMutate('delete'");
    expect(js).toContain("postInboxMutate('mark-spam'");
    expect(js).toContain("t('Could not delete')");
    expect(js).toContain("t('Could not mark as spam')");
    expect(js).toContain("t('Try again')");
    expect(js).toContain('/mailbox is updating/i');
    expect(js).toContain("variant: 'warning'");
    expect(js).toContain('handOffRetry');
    expect(js).toContain('mailId: mid');
    expect(js).toContain('mutateActionVisibility');
    expect(js).toContain('crm-sales-compose-host');
    expect(js).not.toContain('CustomerCheckReviewBoard');
    expect(js).not.toContain('mountAssignBoard');
    expect(js).not.toContain('showMailPanels');
  });

  it('maps review rows with dual ids (mailMsgId + reviewItemId)', () => {
    const js = read('js/pages/sales-inbox.js');
    expect(js).toContain('reviewItemId');
    expect(js).toContain('mailMsgId');
    expect(js).toContain('hasMail');
    expect(js).toContain("row.hasMail && row.id > 0");
  });

  it('PageController redirects /review into Inbox Unassigned without persona on salesInbox', () => {
    const ctrl = read('lib/Controller/PageController.php');
    expect(ctrl).toMatch(/function review\(\): RedirectResponse/);
    expect(ctrl).toContain('inboxAssignUrl');
    expect(ctrl).toContain('tab=unmatched');
    const salesInboxBlock = ctrl.slice(
      ctrl.indexOf('function salesInbox(): TemplateResponse'),
      ctrl.indexOf('function review(): RedirectResponse'),
    );
		expect(salesInboxBlock).toContain("'common/user-picker-nav'");
    expect(salesInboxBlock).toContain("'common/inbox-assign-map'");
    expect(salesInboxBlock).not.toContain("'pages/persona'");
  });

  it('under-row detail park survives table layout', () => {
    const js = read('js/pages/sales-inbox.js');
    const css = read('css/app.css');
    expect(js).toContain('parkSalesReaderUnderSelection');
    expect(js).toContain('crm-sales-inbox__detail');
    expect(js).toContain('row.after(host)');
    expect(js).toContain('reader.hidden = false');
    expect(js).toMatch(/reader\.hidden = false;\s*\n\s*parkSalesReaderUnderSelection/);
    expect(js).toContain('row.nextElementSibling !== host');
    expect(css).toContain('crm-sales-inbox__table');
    expect(css).toMatch(/crm-sales-inbox__table[\s\S]{0,280}overflow:\s*visible/);
    expect(css).toContain('crm-sales-inbox__detail');
    expect(css).toMatch(/crm-sales-inbox__detail[\s\S]{0,120}display:\s*block\s*!important/);
    expect(css).toMatch(/#crm-sales-inbox-panel\.crm-mail-3pane[\s\S]{0,220}overflow:\s*visible/);
    expect(css).toMatch(
      /#crm-sales-inbox-panel\.crm-mail-3pane \.crm-mail-3pane__thread[\s\S]{0,280}overflow:\s*visible/,
    );
  });

  it('persona ReviewBoard remains available for other surfaces', () => {
    const js = read('js/pages/persona.js');
    expect(js).toContain('window.CustomerCheckReviewBoard');
    expect(js).toContain("id: 'mail', label: t('Mail')");
    expect(js).toContain('isMailSourceKind(item.sourceKind)');
  });
});
