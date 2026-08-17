/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

describe('personal-mail settings UX contracts', () => {
  it('share disclosure requires checkbox ack (NN-07 / FAC-20)', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('requireCheckbox: true');
    expect(js).toContain(
      'I understand these emails will be visible to teammates who can open the linked customer.',
    );
    expect(js).toContain(
      'I understand these existing emails will be visible to teammates who can open the linked customer.',
    );
  });

  it('Sales Inbox compose wires Files attach', () => {
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    const tpl = readFileSync(join(root, 'templates/sales-inbox.php'), 'utf8');
    const picker = readFileSync(join(root, 'js/common/folder-picker.js'), 'utf8');
    const rich = readFileSync(join(root, 'js/common/rich-mail-composer.js'), 'utf8');
    expect(tpl).toContain('crm-sales-compose-attach-add');
    expect(js).toContain('attachmentFileIds');
    expect(js).toContain('CustomerCheckFolderPicker');
    expect(js).toContain('mimeTypes: []');
    expect(js).toContain('pickComposeSendAccount');
    expect(js).toContain('includeSignatureDefault: true');
    expect(picker).toContain('pickNodes');
    expect(picker).toContain('resolveFileIdViaDav');
    // Missing checkbox must NOT force includeSignature false (Team-first send-accounts bug).
    expect(rich).toMatch(/if\s*\(\s*!sigBox\s*\)\s*\{\s*return true;\s*\}/);
    expect(rich).not.toMatch(/if\s*\(\s*!hasSig\s*\)\s*return false/);
  });

  it('rich composer exposes clear + subheading', () => {
    const js = readFileSync(join(root, 'js/common/rich-mail-composer.js'), 'utf8');
    expect(js).toContain('removeFormat');
    expect(js).toContain("'h3'");
  });

  it('signature editors use lean signature toolbar + https logo from Files', () => {
    const settings = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    const rich = readFileSync(join(root, 'js/common/rich-mail-composer.js'), 'utf8');
    const teamTpl = readFileSync(join(root, 'templates/settings/workspace-mailbox.php'), 'utf8');
    const myEmailTpl = readFileSync(join(root, 'templates/settings/my-email.php'), 'utf8');
    const css = readFileSync(join(root, 'css/app.css'), 'utf8');
    expect(settings).toContain("toolbar: 'signature'");
    expect(settings).toContain('allowSignatureLogo: true');
    expect(settings).toContain('lean toolbar + logo');
    expect(settings).not.toContain("toolbar: 'full'");
    expect(rich).toContain("toolbarMode === 'signature'");
    expect(rich).toContain("insertLogo");
    expect(rich).toContain('pickLogoFromFiles');
    expect(rich).toContain('application/x-www-form-urlencoded');
    expect(rich).toContain('saveEditorSelection');
    expect(rich).not.toContain('insertLogoFile');
    expect(rich).not.toContain('promptInsertLogoLink');
    expect(rich).toContain('allowSignatureLogo');
    expect(rich).toContain("title: t('Choose a logo image')");
    expect(myEmailTpl).toContain('crm-signature-editor');
    expect(myEmailTpl).toContain('crm-sales-compose__label');
    expect(myEmailTpl).toContain("\$l->t('Your signature')");
    expect(myEmailTpl).toContain('Use Logo to pick an image from Files');
    expect(teamTpl).toContain('crm-mbox-signature-editor');
    expect(teamTpl).toContain('Use Logo to pick an image from Files');
    expect(css).toMatch(
      /\.crm-signature-editor__mount \.crm-rich-mail__editor\s*\{[\s\S]*?min-height:\s*10rem/,
    );
  });

  it('settings loads signature-logo-core before rich composer (AS-18)', () => {
    const page = readFileSync(join(root, 'lib/Controller/PageController.php'), 'utf8');
    const core = readFileSync(join(root, 'js/common/signature-logo-core.js'), 'utf8');
    const rich = readFileSync(join(root, 'js/common/rich-mail-composer.js'), 'utf8');
    expect(page).toContain(
      "'common/folder-picker', 'common/signature-logo-core', 'common/personal-mail-settings-core', 'common/rich-mail-composer'",
    );
    expect(core).toContain('buildSignatureLogoHtml');
    expect(core).toContain('isPublicImageUrl');
    expect(core).toContain('isNextcloudPublicSharePath');
    expect(core).toContain('pageOrigin');
    expect(core).toContain('^https:\\/\\/');
    expect(rich).toContain('pageOrigin: origin');
    expect(rich).toContain('Could not use this image as a logo');
    expect(rich).toContain('data-crm-logo-busy');
    expect(rich).toContain("getAttribute('data-crm-logo-busy') === '1'");
    expect(rich).toContain('data-crm-logo-live');
    expect(rich).toContain('setLogoInsertBusy');
    expect(core).toContain('shareUrlFromOcsData');
    expect(core).toContain('isReusablePublicShareRow');
  });

  it('signature editors mount rich HTML twin (Q12)', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    const teamTpl = readFileSync(join(root, 'templates/settings/workspace-mailbox.php'), 'utf8');
    const myEmailTpl = readFileSync(join(root, 'templates/settings/my-email.php'), 'utf8');
    expect(js).toContain('mountSignatureEditor');
    expect(js).toContain('remountTeamSignatureEditor');
    expect(js).toContain('signatureHtml');
    expect(myEmailTpl).toContain('crm-personal-mail-signature-editor');
    expect(teamTpl).toContain('crm-mbox-signature-editor');
    expect(myEmailTpl).toContain('crm-advanced__body');
    expect(myEmailTpl).toMatch(
      /id="crm-personal-mail-signature-details"[\s\S]*?crm-advanced__body[\s\S]*?crm-personal-mail-signature-editor/,
    );
  });

  it('My email signature + inputs use full card width', () => {
    const css = readFileSync(join(root, 'css/app.css'), 'utf8');
    expect(css).toContain('#crm-ws-personal-mail-mine .crm-signature-editor__mount');
    expect(css).toContain('#crm-ws-personal-mail-mine .crm-rich-mail__editor');
    expect(css).toContain('#crm-mailbox-form .crm-signature-editor__mount');
    expect(css).toMatch(/\.crm-rich-mail--signature\s*\{[\s\S]*?width:\s*100%/);
    expect(css).toMatch(/\.crm-signature-editor\s*\{[\s\S]*?align-items:\s*stretch/);
    expect(css).toMatch(/\.crm-advanced__body[\s\S]{0,200}width:\s*100%/);
    // Block that lists personal-mail + mailbox-form controls forces full width.
    const block = css.slice(css.indexOf('#crm-ws-personal-mail-mine .crm-input'));
    expect(block.slice(0, 1200)).toMatch(/width:\s*100%/);
    expect(css).toMatch(
      /\.crm-signature-editor__mount \.crm-rich-mail__editor\s*\{[\s\S]*?min-height:\s*10rem/,
    );
  });

  it('Sales Inbox preview renders sanitized bodyHtml (FAC-16)', () => {
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    const tpl = readFileSync(join(root, 'templates/sales-inbox.php'), 'utf8');
    expect(js).toContain('bodyEl.innerHTML = bodyHtml');
    expect(js).toContain('crm-mail-preview__body--html');
    expect(tpl).toContain('role="article"');
  });

  it('Company Talk preview renders sanitized bodyHtml (FAC-16)', () => {
    const js = readFileSync(join(root, 'js/pages/company-detail.js'), 'utf8');
    expect(js).toContain('bodyEl.innerHTML = bodyHtml');
    expect(js).toContain('FAC-16');
    expect(js).not.toContain('Never use innerHTML — mail bodies are untrusted.');
  });

  it('OAuth begin posts member dsgvoAck (MH-01)', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('{ dsgvoAck: true }');
    expect(js).toContain("getElementById('crm-pm-dsgvo')");
  });

  it('quarantined personal mailbox shows reconnect OAuth (US-002 ok⇄error)', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('needsReconnect');
    expect(js).toContain('Needs reconnect — sign in again below. Your synced mail stays.');
    expect(js).toContain('(!personalMailBox || needsReconnect || editConnection)');
    expect(js).toContain('personalMailHealId');
    expect(js).toContain('payload.id = healId');
  });

  it('connected inbox can update password/servers without Disconnect wipe', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    const tpl = readFileSync(join(root, 'templates/settings/my-email.php'), 'utf8');
    const core = readFileSync(join(root, 'js/utils/personal-mail-settings-core.js'), 'utf8');
    expect(tpl).toContain('id="crm-personal-mail-edit-connection"');
    expect(tpl).toContain('id="crm-personal-mail-edit-cancel"');
    expect(tpl).toContain('Leave blank to keep your current password');
    expect(tpl).toContain('Update password or servers');
    expect(js).toContain('personalMailEditMode');
    expect(js).toContain('openPersonalMailEditConnection');
    expect(js).toContain('cancelPersonalMailEditConnection');
    expect(js).toContain('lockEmail: true');
    expect(core).toContain('showPersonalMailConnectForm');
    expect(core).toContain('editConnection');
    // Voluntary edit must NOT hide folders (quarantine still does).
    expect(js).toContain('hidePersonalMailOpsForReconnect');
    expect(js).toMatch(/if \(foldersWrap\) foldersWrap\.hidden = !!hideOps/);
  });

  it('settings loads personal-mail-settings-core before settings page logic', () => {
    const page = readFileSync(join(root, 'lib/Controller/PageController.php'), 'utf8');
    expect(page).toContain("'common/personal-mail-settings-core'");
  });

  it('signature toggle announces live (WCAG)', () => {
    const js = readFileSync(join(root, 'js/common/rich-mail-composer.js'), 'utf8');
    expect(js).toContain('aria-live="polite"');
    expect(js).toContain('Signature included');
    expect(js).toContain('Signature off');
  });

  it('Sales Inbox compose exposes Cc behind progressive disclosure', () => {
    const tpl = readFileSync(join(root, 'templates/sales-inbox.php'), 'utf8');
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    const send = readFileSync(join(root, 'js/common/shared-mail-send.js'), 'utf8');
    expect(tpl).toContain('crm-sales-compose-cc');
    expect(tpl).toContain('crm-sales-compose-cc-toggle');
    expect(tpl).toContain('crm-sales-compose-cc-wrap" hidden');
    expect(tpl).toContain('crm-sales-compose__field-head');
    expect(tpl).toContain('crm-sales-compose__recipients');
    expect(js).toContain('cc: cc');
    expect(js).toContain('crm-sales-compose-cc-toggle');
    expect(js).toContain("t('Hide Cc')");
    expect(send).toContain('payload.cc = cc');
  });

  it('Bachus Sales Inbox: empty Connect CTA, quiet account filter, auto-sync', () => {
    const tpl = readFileSync(join(root, 'templates/sales-inbox.php'), 'utf8');
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    const map = readFileSync(join(root, 'js/common/inbox-assign-map.js'), 'utf8');
    const rich = readFileSync(join(root, 'js/common/rich-mail-composer.js'), 'utf8');
    expect(tpl).toContain('data-crm-bachus-mail="1"');
    expect(tpl).toContain('crm-sales-inbox-empty-cta');
    expect(tpl).toContain('crm-sales-inbox-account-wrap" hidden');
    expect(js).toContain('autoSyncedOnce');
    expect(js).toContain("syncPersonal({ quiet: true, tab: 'mine' })");
    expect(js).toContain('syncableBoxesForActiveTab');
    expect(js).toContain("'/mailboxes/' + id + '/sync'");
    expect(js).toContain('syncInFlight');
    expect(js).toContain('accountsForInboxTab');
    expect(js).toContain('All personal accounts');
    expect(js).toContain('All team accounts');
    expect(js).toContain('pickComposeSendAccount');
    expect(map).toContain('function accountsForInboxTab');
    expect(map).toContain('function accountFilterVisible');
    expect(map).toContain('function pickComposeSendAccount');
    expect(rich).toMatch(/if\s*\(\s*!sigBox\s*\)\s*\{\s*return true;\s*\}/);
  });

  it('Sales Inbox WCAG: roving tabindex + announce quiet sync failures', () => {
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    expect(js).toContain("btn.setAttribute('tabindex', on ? '0' : '-1')");
    expect(js).toContain("li.setAttribute('tabindex', focusable ? '0' : '-1')");
    expect(js).toContain('Always announce failures');
    expect(js).toContain("it.redacted");
    expect(js).toContain('Needs triage');
  });

  it('Sales Inbox compose focuses To first (US-010 AC-3)', () => {
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    expect(js).toContain("getElementById('crm-sales-compose-to')");
    expect(js).toMatch(/crm-sales-compose-to[\s\S]{0,120}toFocus\.focus/);
    expect(js).not.toMatch(/crm-sales-compose-send[\s\S]{0,40}sendBtn\.focus/);
  });

  it('Sales Inbox reply quote is visible+editable and never DOM-attribute soup (US-010 AC-5)', () => {
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    const rich = readFileSync(join(root, 'js/common/rich-mail-composer.js'), 'utf8');
    const tpl = readFileSync(join(root, 'templates/sales-inbox.php'), 'utf8');
    expect(tpl).toContain("\$l->t('Send to')");
    expect(tpl).not.toContain("\$l->t('To')");
    expect(tpl).toContain("\$l->t('Your message')");
    expect(rich).toContain('quotedPlain');
    expect(rich).toContain('crm-rich-mail__quote');
    expect(rich).toContain('getQuotedPlain');
    expect(rich).toContain("replace(/^(?:>\\s*)+/, '')");
    expect(js).toContain('quotedPlain: quoted');
    expect(js).toContain('getQuotedPlain');
    expect(js).toContain('lastPreviewPlain');
    expect(js).toContain('cleanQuoteSource');
    expect(js).toContain('htmlToPlainClient');
    expect(js).not.toContain("setAttribute('data-crm-quote'");
    expect(js).toContain("removeAttribute('data-crm-quote')");
    expect(js).toContain('replace(/%(?:\\d+\\$)?s/g');
    expect(js).toContain('composeGeneration');
  });

  it('rich composer toolbar keeps compact visible labels with full accessible names', () => {
    const js = readFileSync(join(root, 'js/common/rich-mail-composer.js'), 'utf8');
    expect(js).toContain("toolbarBtn(uid + '-b', 'bold', t('Bold'), null, 'B')");
    expect(js).toContain('aria-label');
    expect(js).toContain('crm-rich-mail__tool');
  });

  it('rich mail editor/quote claim full width (no shrink-wrap columns)', () => {
    const css = readFileSync(join(root, 'css/app.css'), 'utf8');
    expect(css).toMatch(/\.crm-rich-mail__editor,\s*\n\s*#app-content\.crm-app \.crm-rich-mail__quote \{[\s\S]*?width:\s*100%/);
    expect(css).toContain('#app-content.crm-app #crm-sales-compose-editor');
    expect(css).toMatch(/#crm-sales-compose-editor[\s\S]{0,180}width:\s*100%/);
  });

  it('Sales Inbox preview keeps role=article on success', () => {
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    expect(js).toContain("bodyEl.setAttribute('role', 'article')");
    expect(js).not.toContain("bodyEl.removeAttribute('role')");
  });

  it('Aristoteles: busy-retry pins mail id and does not unlock during hand-off', () => {
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    expect(js).toContain('handOffRetry');
    expect(js).toContain('mailId: mid');
    expect(js).toContain('if (!handOffRetry)');
    expect(js).toContain("opts.mailId");
    expect(js).toContain('_busyRetried: true');
    expect(js).toContain('/mailbox is updating/i');
  });

  it('Aristoteles: folder auto-save coalesces dirty toggles during SHARE dialogs', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('personalFoldersSaveDirty');
    expect(js).toContain('schedulePersonalFoldersAutoSave');
    expect(js).toContain("shareEl.setAttribute('data-was-shared', '1')");
    expect(js).toContain('if (personalFoldersSaveDirty)');
  });

  it('Bachus My email: OAuth then DSGVO then IMAP, single SHARE dialog, auto-save folders', () => {
    const tpl = readFileSync(join(root, 'templates/settings/my-email.php'), 'utf8');
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(tpl).toContain('data-crm-bachus-pm="1"');
    expect(tpl.indexOf('id="crm-personal-mail-oauth"')).toBeLessThan(tpl.indexOf('id="crm-pm-dsgvo"'));
    expect(tpl.indexOf('id="crm-pm-dsgvo"')).toBeLessThan(tpl.indexOf('id="crm-personal-mail-imap"'));
    expect(tpl.indexOf('id="crm-personal-mail-imap"')).toBeLessThan(tpl.indexOf('id="crm-personal-mail-connect"'));
    expect(tpl).toContain('Use email and app password');
    expect(tpl).toContain('crm-personal-mail-imap__body');
    expect(tpl).toContain('crm-personal-mail-servers__body');
    expect(tpl).toContain('crm-personal-mail-remove');
    expect(tpl).toContain('Remove inbox');
    expect(tpl).toContain('id="crm-personal-mail-folders-save" hidden');
    expect(tpl).not.toMatch(/id="crm-personal-mail-imap"[\s\S]*?<fieldset class="crm-fieldset"/);
    expect(js).toContain('requireTypedConfirm: !!inboxSent');
    expect(js).toContain("typedConfirmPhrase: 'SHARE'");
    expect(js).toContain('schedulePersonalFoldersAutoSave');
    expect(js).toContain('savePersonalFolders');
    expect(js).toContain('changes save automatically');
  });

  it('Sales Inbox reconnect deep-links My email (Bachus)', () => {
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    expect(js).toContain('/settings/my-email');
    expect(js).toContain('#crm-ws-personal-mail-mine');
    expect(js).toContain('needs a quick reconnect');
  });

  it('My email is its own settings section (not Team & mailbox)', () => {
    const catalog = readFileSync(join(root, 'lib/Service/WorkspaceSettingsSectionCatalog.php'), 'utf8');
    const dispatcher = readFileSync(join(root, 'templates/settings.php'), 'utf8');
    const teamTpl = readFileSync(join(root, 'templates/settings/workspace-mailbox.php'), 'utf8');
    const myEmailTpl = readFileSync(join(root, 'templates/settings/my-email.php'), 'utf8');
    expect(catalog).toContain("'my-email'");
    expect(catalog).toMatch(/'my-email', 'preferences', 'integrations' => true/);
    expect(catalog).toMatch(/'mailbox', 'checklists'/);
    expect(dispatcher).toContain("'my-email' => 'my-email.php'");
    expect(myEmailTpl).toContain('id="crm-ws-personal-mail-mine"');
    expect(teamTpl).not.toContain('id="crm-ws-personal-mail-mine"');
    expect(teamTpl).toContain('id="crm-ws-personal-mail-policy"');
  });

  it('Aristoteles: IMAP opens without OAuth; SHARE case-insensitive; OAuth query feedback', () => {
    const settings = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    const components = readFileSync(join(root, 'js/common/components.js'), 'utf8');
    expect(settings).toContain('oauthConfiguredCount === 0');
    expect(settings).toContain('consumePersonalMailQueryFeedback');
    expect(settings).toContain('Connection cancelled. You can try again when ready.');
    expect(components).toMatch(/typedField\.value\.trim\(\)\.toUpperCase\(\)/);
  });

  it('reconnect keeps Disconnect visible (erase / email-switch)', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('if (syncBtn) syncBtn.hidden = !!needsReconnect;');
    expect(js).toContain('if (disconnectBtn) disconnectBtn.hidden = false;');
    expect(js).not.toContain('if (connectedActions) connectedActions.hidden = !!needsReconnect;');
  });

  it('share cancel keeps folder in PUT payload (no sync delete)', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('Keep the folder in the PUT payload');
    expect(js).toMatch(
      /if \(!ack \|\| !ack\.confirmed\) \{[\s\S]{0,200}folder\.shareWithWorkspace = false;[\s\S]{0,80}out\.push\(folder\);/,
    );
  });

  it('Refresh folders rediscovers IMAP subfolders (umlauts)', () => {
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    const tpl = readFileSync(join(root, 'templates/settings/my-email.php'), 'utf8');
    const css = readFileSync(join(root, 'css/app.css'), 'utf8');
    expect(tpl).toContain('crm-personal-mail-folders-refresh');
    expect(js).toContain('/folders/refresh');
    expect(js).toContain('folderDisplayMeta');
    expect(js).toContain('Found %s new folder(s)');
    expect(js).toContain('crm-personal-mail-folder--nested');
    expect(js).toContain('crm-personal-mail-folder__mark');
    expect(js).not.toContain('crm-personal-mail-folder__path');
    expect(css).toContain('.crm-personal-mail-folder--nested');
    expect(css).toMatch(/--crm-folder-depth,\s*0\)\s*\*\s*1\.75rem/);
  });

  it('Sales Inbox uses Personal tabs; My email lives under Tools nav', () => {
    const tpl = readFileSync(join(root, 'templates/sales-inbox.php'), 'utf8');
    const nav = readFileSync(join(root, 'templates/common/navigation.php'), 'utf8');
    expect(tpl).toContain("\$l->t('Personal')");
    expect(tpl).not.toContain("\$l->t('Mine')");
    expect(tpl).not.toContain('crm-sales-inbox-settings');
    expect(tpl).not.toContain("\$l->t('Email settings')");
    expect(nav).toContain("\$l->t('My email')");
    expect(nav).toContain("settingsSections['my-email']");
    expect(nav).toContain('canManageWorkspaceNav');
  });

  it('Sales Inbox list is full width; preview parks under the selected row', () => {
    const css = readFileSync(join(root, 'css/app.css'), 'utf8');
    const js = readFileSync(join(root, 'js/pages/sales-inbox.js'), 'utf8');
    expect(css).not.toMatch(
      /crm-mail-3pane:has\(#crm-sales-inbox-list\)\s*\{[^}]*grid-template-columns/s,
    );
    expect(css).toContain('crm-mail-thread-row--detail');
    expect(css).toContain('grid-template-columns: minmax(0, 1fr) auto');
    // Single page scroll — never nest the shared 70vh thread scrollport on Sales Inbox.
    expect(css).toMatch(
      /#crm-sales-inbox-panel\.crm-mail-3pane[\s\S]{0,220}overflow:\s*visible/,
    );
    expect(css).toMatch(
      /#crm-sales-inbox-panel\.crm-mail-3pane \.crm-mail-3pane__thread[\s\S]{0,280}max-height:\s*none/,
    );
    expect(css).toMatch(
      /#crm-sales-inbox-panel\.crm-mail-3pane \.crm-mail-3pane__thread[\s\S]{0,280}overflow:\s*visible/,
    );
    expect(js).toContain('crm-mail-thread-row__account');
    expect(js).toContain('parkSalesReaderUnderSelection');
    expect(js).toContain('crm-sales-inbox-reader-host');
    expect(js).toContain('closeSalesPreview');
    expect(js).toContain('Preview open');
    expect(js).not.toContain("li.setAttribute('aria-expanded'");
    expect(js).toContain('snippetUseful');
    expect(js).toContain('Second click / Enter on the open row collapses');
    expect(js).toContain('Intl.DateTimeFormat');
  });

  it('My email + Sales Inbox CSS use NC mobile ≤767px stacking (desktop base untouched)', () => {
    const css = readFileSync(join(root, 'css/app.css'), 'utf8');
    expect(css).toMatch(
      /@media \(max-width:\s*767px\) \{\s*\n\s*#app-content\.crm-app \.crm-personal-mail-connected-actions/,
    );
    expect(css).toMatch(
      /@media \(max-width:\s*767px\) \{\s*\n\s*#app-content\.crm-app \.crm-personal-mail-folder \{[\s\S]*?\* 0\.85rem/,
    );
    expect(css).toMatch(
      /@media \(max-width:\s*767px\) \{\s*\n\s*#app-content\.crm-app \.crm-sales-inbox__toolbar[\s\S]*?crm-sales-inbox__actions-bar[\s\S]*?crm-sales-compose__cc-toggle/,
    );
    expect(css).toMatch(
      /@media \(max-width:\s*1023px\) \{\s*\n\s*#app-content\.crm-app \.crm-personal-mail-folders/,
    );
    expect(css).toMatch(
      /#app-content\.crm-app \.crm-sales-inbox__toolbar \{\s*\n\s*display:\s*flex;\s*\n\s*flex-wrap:\s*wrap;/,
    );
    expect(css).not.toMatch(
      /#app-content\.crm-app \.crm-sales-inbox__toolbar \{\s*\n\s*display:\s*flex;\s*\n\s*flex-direction:\s*column/,
    );
    expect(css).toContain('var(--crm-folder-depth, 0) * 1.75rem');
    expect(css).toContain('#crm-personal-mail-sync.crm-btn--lg');
    expect(css).toContain('min-height: var(--crm-touch-lg)');
    expect(css).toContain('var(--crm-muted)');
    expect(css).toContain('var(--crm-bg-card)');
    expect(css).toContain('var(--crm-border)');
    // No hex fallbacks in the Sales Inbox / personal-mail Bachus block.
    const mailBlock = css.slice(
      css.indexOf('/* Sales Inbox + personal mail settings'),
      css.indexOf('/* Sales Inbox: full-width list'),
    );
    expect(mailBlock).not.toMatch(/#[0-9a-fA-F]{3,8}/);
  });

  it('Talk/Review Answer uses hardened prepared-mail dialog', () => {
    const shared = readFileSync(join(root, 'js/common/shared-mail-send.js'), 'utf8');
    const company = readFileSync(join(root, 'js/pages/company-detail.js'), 'utf8');
    const persona = readFileSync(join(root, 'js/pages/persona.js'), 'utf8');
    const css = readFileSync(join(root, 'css/app.css'), 'utf8');
    expect(shared).toContain('crm-prepared-mail');
    expect(shared).toContain('queryInDialog');
    expect(shared).toContain('elevatingPrivate');
    expect(shared).toContain('C.closeModal(dialogRoot(dialogId))');
    expect(shared).not.toMatch(/,\s*\[data-crm-shared-send\]/);
    expect(company).toContain('replyMode: true');
    expect(persona).toContain('replyMode: true');
    expect(persona).toContain("wrap.getAttribute('data-company-id')");
    expect(css).toContain('.crm-prepared-mail__meta');
    expect(css).toMatch(/crm-prepared-mail__actions[\s\S]{0,200}min-height:\s*44px/);
  });
});
