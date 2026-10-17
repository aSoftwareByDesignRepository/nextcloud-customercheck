// @ts-check
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, credsFromEnv } from './helpers/auth.js';

/**
 * Personal email sync / Sales Inbox — CUC-PERSONAL-MAIL-1 FAC + a11y smoke.
 */
test.describe('Personal email sync / Sales Inbox', () => {
  test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');

  test('Sales Inbox shell: Bachus tabs, disclosure, empty CTA, axe clean', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/sales-inbox');
    await page.waitForSelector('#crm-sales-inbox-root[data-crm-bachus-mail="1"]', { timeout: 30000 });

    await expect(page.locator('#crm-sales-inbox-disclosure')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-disclosure')).not.toBeEmpty();
    // Account filter stays hidden until multiple accounts exist (Bachus).
    await expect(page.locator('#crm-sales-inbox-account-wrap')).toBeHidden();
    await expect(page.locator('#crm-sales-tab-mine')).toBeVisible();
    await expect(page.locator('#crm-sales-tab-team')).toBeVisible();
    await expect(page.locator('#crm-sales-tab-unmatched')).toBeVisible();
    await expect(page.locator('#tools-subnav a[href*="/settings/my-email"]')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-settings')).toHaveCount(0);
    await expect(page.locator('#crm-sales-inbox-empty-cta')).toHaveCount(1);
    await expect(page.locator('#crm-sales-compose-cc-toggle')).toHaveCount(1);
    await expect(page.locator('#crm-sales-compose-to')).toHaveCount(1);
    await expect(page.locator('label[for="crm-sales-compose-to"]')).toBeAttached();
    // Must not use ambiguous "To" (DE "Bis"); compose label is "Send to".
    const toLabel = await page.locator('label[for="crm-sales-compose-to"]').innerText();
    expect(toLabel.toLowerCase()).not.toMatch(/^bis$/i);
    await expect(page.locator('#crm-sales-compose-hint')).toBeAttached();
    // Shared-folders banner is hidden until API returns shared folders (US-004 AC-3).
    await expect(page.locator('#crm-sales-inbox-shared-folders')).toHaveCount(1);
    await expect(page.locator('#crm-sales-inbox-auth-alert')).toHaveCount(1);

    await page.locator('#crm-sales-tab-team').click();
    await expect(page.locator('#crm-sales-tab-team')).toHaveAttribute('aria-selected', 'true');
    // Update mail is tab-aware: may show for Team when shared boxes exist; must stay hidden on Unassigned.
    await page.locator('#crm-sales-tab-unmatched').click();
    await expect(page.locator('#crm-sales-tab-unmatched')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#crm-sales-inbox-sync')).toBeHidden();
    await page.locator('#crm-sales-tab-mine').click();
    await expect(page.locator('#crm-sales-tab-mine')).toHaveAttribute('aria-selected', 'true');
    // Account filter labels are PHP-baked for native first paint (not English leftovers).
    await expect(page.locator('#crm-sales-inbox-account')).toHaveAttribute('data-crm-all-personal', /.+/);
    await page.locator('#crm-sales-tab-unmatched').click();
    await expect(page.locator('#crm-sales-tab-unmatched')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-assign-panel')).toHaveCount(0);
    await expect(page.locator('#crm-sales-inbox-list-tools')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-q')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-mail-filters')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#crm-sales-inbox-empty, #crm-sales-inbox-list .crm-mail-thread-row').first()).toBeVisible({ timeout: 20000 });
    if (await page.locator('#crm-sales-inbox-list .crm-mail-thread-row').count()) {
      await expect(page.locator('#crm-sales-inbox-cols')).toBeVisible();
      await expect(page.locator('#crm-sales-inbox-cols [data-crm-inbox-sort="sent"]')).toBeVisible();
    }
    await expect(page.locator('#crm-sales-inbox-list')).toBeVisible();
    await page.locator('#crm-sales-tab-mine').click();
    await expect(page.locator('#crm-sales-tab-mine')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#crm-sales-inbox-panel')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-list-tools')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-mail-filters')).toBeHidden();

    await expect(page.locator('#crm-sales-inbox-list')).toHaveAttribute('role', 'listbox');

    // Paging chrome is present but hidden until hasMore (granny: no dead controls).
    await expect(page.locator('#crm-sales-inbox-more-wrap')).toBeHidden();
    await expect(page.locator('#crm-sales-inbox-more')).toHaveCount(1);
    await expect(page.locator('#crm-sales-inbox-more-hint')).toBeAttached();

    const results = await new AxeBuilder({ page })
      .include('#crm-sales-inbox-root')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });

  test('Sales Inbox failed load renders honest error chrome, not a fabricated empty state', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.route('**/apps/customercheck/api/**/mail/inbox**', async (route) => {
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, error: 'SERVER_ERROR', message: 'Unexpected error', fields: {} }),
      });
    });

    await page.goto('/apps/customercheck/sales-inbox?tab=team');
    await page.waitForSelector('#crm-sales-inbox-root[data-crm-bachus-mail="1"]', { timeout: 30000 });

    const empty = page.locator('#crm-sales-inbox-empty');
    await expect(empty).toBeVisible({ timeout: 20000 });
    // Error chrome contract: alert role + error styling hook + no fake CTA.
    await expect(empty).toHaveAttribute('role', 'alert');
    await expect(empty).toHaveClass(/is-error/);
    await expect(page.locator('#crm-sales-inbox-empty-cta-wrap')).toBeHidden();
    // Honest copy: the panel must not claim "nothing here" — its title differs
    // from the true-empty title rendered by a 200-with-zero-rows response.
    const failedTitle = await page.locator('#crm-sales-inbox-empty-title').innerText();
    expect(failedTitle.trim()).not.toBe('');

    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await page.route('**/apps/customercheck/api/**/mail/inbox**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: { items: [], nextCursor: null, hasMore: false, personalMailEnabled: true, sharedFolders: [], accounts: [], authAlerts: [] },
        }),
      });
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#crm-sales-inbox-root[data-crm-bachus-mail="1"]', { timeout: 30000 });
    await expect(empty).toBeVisible({ timeout: 20000 });
    await expect(empty).toHaveAttribute('role', 'status');
    await expect(empty).not.toHaveClass(/is-error/);
    const trueEmptyTitle = await page.locator('#crm-sales-inbox-empty-title').innerText();
    expect(trueEmptyTitle).not.toBe(failedTitle);
  });

  test('Sales Inbox Load older messages: cursor pages append without duplicates', async ({ page }) => {
    test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS');
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    let inboxCalls = 0;
    await page.route('**/apps/customercheck/api/**/mail/inbox**', async (route) => {
      const url = new URL(route.request().url());
      const cursor = url.searchParams.get('cursor') || '';
      inboxCalls += 1;
      const mk = (id, subject) => ({
        id,
        subject,
        from: `user${id}@example.test`,
        snippet: `snippet ${id}`,
        sentAt: `2026-08-${String(Math.max(1, 16 - Math.floor(id / 10))).padStart(2, '0')} 12:00:00`,
        visibility: 'private',
        private: true,
        mailboxId: 1,
        mailboxKind: 'personal',
        mailboxLabel: 'Me',
        mailboxEmail: 'me@example.test',
        triageStatus: 'open',
        companyId: null,
      });
      if (!cursor) {
        const items = Array.from({ length: 50 }, (_, i) => mk(100 - i, `Page1 ${100 - i}`));
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            data: {
              items,
              nextCursor: '2026-08-11 12:00:00|51',
              hasMore: true,
              personalMailEnabled: true,
              sharedFolders: [],
              accounts: [{ id: 1, kind: 'personal', label: 'Me', email: 'me@example.test', status: 'ok' }],
              authAlerts: [],
            },
          }),
        });
        return;
      }
      const items = Array.from({ length: 20 }, (_, i) => mk(50 - i, `Page2 ${50 - i}`));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            items,
            nextCursor: null,
            hasMore: false,
            personalMailEnabled: true,
            sharedFolders: [],
            accounts: [{ id: 1, kind: 'personal', label: 'Me', email: 'me@example.test', status: 'ok' }],
            authAlerts: [],
          },
        }),
      });
    });

    await page.goto('/apps/customercheck/sales-inbox?tab=mine');
    await page.waitForSelector('#crm-sales-inbox-root[data-crm-bachus-mail="1"]', { timeout: 30000 });
    await expect(page.locator('#crm-sales-inbox-list .crm-mail-thread-row')).toHaveCount(50, { timeout: 20000 });
    await expect(page.locator('#crm-sales-inbox-more-wrap')).toBeVisible();
    await expect(page.locator('#crm-sales-inbox-more')).toBeEnabled();
    // Locale-agnostic: status shows loaded count + “more” signal (EN/DE/…).
    await expect(page.locator('#crm-sales-inbox-status')).toContainText('50');
    await expect(page.locator('#crm-sales-inbox-status')).not.toHaveText(/^50$/);

    await page.locator('#crm-sales-inbox-more').click();
    await expect(page.locator('#crm-sales-inbox-list .crm-mail-thread-row')).toHaveCount(70, { timeout: 20000 });
    await expect(page.locator('#crm-sales-inbox-more-wrap')).toBeHidden();
    expect(inboxCalls).toBeGreaterThanOrEqual(2);

    const ids = await page.locator('#crm-sales-inbox-list .crm-mail-thread-row').evaluateAll((rows) =>
      rows.map((r) => r.getAttribute('data-crm-mail-id')));
    expect(new Set(ids).size).toBe(ids.length);

    const axe = await new AxeBuilder({ page })
      .include('#crm-sales-inbox-root')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();
    expect(axe.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });

  for (const theme of ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']) {
    test(`Sales Inbox + My email: axe WCAG 2.1 AA under theme ${theme}`, async ({ page }) => {
      test.setTimeout(180000);
      await login(page, credsFromEnv('ADMIN'));
      const enable = async (id) => {
        const status = await page.evaluate(async (themeId) => {
          const token = document.head?.dataset?.requesttoken
            // @ts-ignore
            || (window.OC && window.OC.requestToken);
          const res = await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${themeId}/enable`, {
            method: 'PUT',
            headers: { requesttoken: token || '', 'OCS-APIRequest': 'true' },
          });
          return res.status;
        }, id);
        expect(status, `enable ${id}`).toBe(200);
        await page.reload({ waitUntil: 'domcontentloaded' });
      };
      const disable = async (id) => {
        await page.evaluate(async (themeId) => {
          const token = document.head?.dataset?.requesttoken
            // @ts-ignore
            || (window.OC && window.OC.requestToken);
          await fetch(`${window.location.origin}/ocs/v2.php/apps/theming/api/v1/theme/${themeId}`, {
            method: 'DELETE',
            headers: { requesttoken: token || '', 'OCS-APIRequest': 'true' },
          }).catch(() => {});
        }, id);
      };
      try {
        await enable(theme);
        for (const path of ['/apps/customercheck/sales-inbox', '/apps/customercheck/settings/my-email']) {
          await page.goto(path, { waitUntil: 'domcontentloaded' });
          await page.waitForSelector('#crm-main-content', { timeout: 30000 });
          const results = await new AxeBuilder({ page })
            .include('#crm-main-content')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
            .analyze();
          const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
          expect(serious, `[${theme}] ${path}: ${JSON.stringify(serious, null, 2)}`).toEqual([]);
          const overflowFree = await page.evaluate(() => {
            const main = document.getElementById('crm-main-content');
            return !!main && main.scrollWidth <= main.clientWidth + 2;
          });
          expect(overflowFree, `[${theme}] ${path} overflow`).toBe(true);
        }
      } finally {
        await disable(theme).catch(() => {});
      }
    });
  }

  test('My email settings: Bachus OAuth-first strip present', async ({ page }) => {
    await page.setViewportSize({ width: 1100, height: 900 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/settings/my-email#crm-ws-personal-mail-mine');
    await page.waitForSelector('#crm-ws-personal-mail-mine, #section-my-email', { timeout: 30000 });

    const mine = page.locator('#crm-ws-personal-mail-mine');
    await expect(mine).toBeVisible();

    if (await page.locator('#crm-personal-mail-connect-form').count()) {
      await expect(page.locator('#crm-personal-mail-connect-form')).toHaveAttribute('data-crm-bachus-pm', '1');
      await expect(page.locator('#crm-pm-dsgvo-wrap')).toBeAttached();
      await expect(page.locator('#crm-personal-mail-imap')).toBeAttached();
      // Bachus: DSGVO sits under OAuth (before IMAP) so the first OAuth tap is not a scroll trap.
      const oauthBox = await page.locator('#crm-personal-mail-oauth').boundingBox();
      const dsgvoBox = await page.locator('#crm-pm-dsgvo-wrap').boundingBox();
      if (oauthBox && dsgvoBox) {
        expect(dsgvoBox.y).toBeGreaterThanOrEqual(oauthBox.y);
      }
      // When OAuth buttons are present, IMAP stays collapsed; when none, IMAP + servers open so Connect is visible.
      const oauthVisible = await page.locator('#crm-personal-mail-oauth:not([hidden]) button').count();
      if (oauthVisible > 0) {
        await expect(page.locator('#crm-personal-mail-imap')).not.toHaveAttribute('open');
      } else if (await page.locator('#crm-personal-mail-connect-form:not([hidden])').count()) {
        await expect(page.locator('#crm-personal-mail-imap')).toHaveAttribute('open');
        await expect(page.locator('#crm-personal-mail-servers')).toHaveAttribute('open');
      }
      const axeMyEmail = await new AxeBuilder({ page })
        .include('#section-my-email')
        .disableRules(['color-contrast'])
        .analyze();
      expect(axeMyEmail.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
    }
  });

  test('Signature editors: Logo is Files-pick WYSIWYG (WCAG toolbar)', async ({ page }) => {
    await page.setViewportSize({ width: 1100, height: 900 });
    await login(page, credsFromEnv('ADMIN'));

    await page.goto('/apps/customercheck/settings/my-email#crm-ws-personal-mail-mine');
    await page.waitForSelector('#section-my-email, #crm-ws-personal-mail-mine', { timeout: 30000 });
    const personalDetails = page.locator('#crm-personal-mail-signature-details');
    if (await personalDetails.count()) {
      await personalDetails.evaluate((el) => {
        if (el instanceof HTMLDetailsElement) el.open = true;
      });
      const logo = page.locator('#crm-personal-mail-signature-editor [data-crm-rich-cmd="insertLogo"]');
      // Connected inbox mounts the editor; connect form alone has no signature chrome.
      if (await logo.count()) {
        await expect(logo).toBeVisible();
        await expect(logo).toHaveAttribute('aria-label', /logo/i);
        const box = await logo.boundingBox();
        expect(box, 'Logo touch target').toBeTruthy();
        expect(box.height).toBeGreaterThanOrEqual(44);
        expect(box.width).toBeGreaterThanOrEqual(44);
        await expect(page.locator('#crm-personal-mail-signature-editor [data-crm-logo-live]')).toHaveCount(1);
        await expect(page.locator('#crm-personal-mail-signature-editor [data-crm-rich-cmd="insertLogoFile"]')).toHaveCount(0);
      }
    }

    await page.goto('/apps/customercheck/settings/mailbox');
    await page.waitForSelector('#section-workspace-mailbox, #crm-mailbox-form', { timeout: 30000 });
    const compose = page.locator('#crm-mailbox-compose');
    if (await compose.count()) {
      await compose.evaluate((el) => {
        if (el instanceof HTMLDetailsElement) el.open = true;
      });
    }
    const teamDetails = page.locator('#crm-mbox-signature-details');
    if (await teamDetails.count()) {
      await teamDetails.evaluate((el) => {
        if (el instanceof HTMLDetailsElement) el.open = true;
      });
      const teamLogo = page.locator('#crm-mbox-signature-editor [data-crm-rich-cmd="insertLogo"]');
      await expect(teamLogo).toBeAttached({ timeout: 15000 });
      await teamLogo.scrollIntoViewIfNeeded();
      await expect(teamLogo).toBeVisible({ timeout: 15000 });
      await expect(teamLogo).toHaveAttribute('aria-label', /logo/i);
      const tbox = await teamLogo.boundingBox();
      expect(tbox).toBeTruthy();
      expect(tbox.height).toBeGreaterThanOrEqual(44);
      await expect(page.locator('#crm-mbox-signature-help')).toContainText(/Files|Dateien|Archivos|Fichiers|File/i);
      const axeTeam = await new AxeBuilder({ page })
        .include('#crm-mbox-signature-wrap')
        .disableRules(['color-contrast'])
        .analyze();
      expect(axeTeam.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
    }
  });

  test('OAuth error query is consumed by settings JS contract', async ({ page }) => {
    await page.setViewportSize({ width: 900, height: 700 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/settings/my-email');
    await page.waitForLoadState('networkidle');
    const { readFileSync } = await import('node:fs');
    const { join, dirname } = await import('node:path');
    const { fileURLToPath } = await import('node:url');
    const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('consumePersonalMailQueryFeedback');
    expect(js).toContain('personalMailError');
    const components = readFileSync(join(root, 'js/common/components.js'), 'utf8');
    expect(components).toMatch(/typedField\.value\.trim\(\)\.toUpperCase\(\)/);
  });

  test('share disclosure JS contract requires checkbox ack', async ({ page }) => {
    await page.setViewportSize({ width: 900, height: 700 });
    await login(page, credsFromEnv('ADMIN'));
    await page.goto('/apps/customercheck/settings/mailbox');
    await page.waitForLoadState('networkidle');

    const hasSettingsScript = await page.evaluate(() => {
      return Array.from(document.scripts).some((s) => {
        const src = String(s.src || '');
        return src.includes('settings') || src.includes('customercheck');
      });
    });
    expect(hasSettingsScript).toBeTruthy();

    // Source contract from disk (NC may gate raw /js/pages/*.js behind auth HTML).
    const { readFileSync } = await import('node:fs');
    const { join, dirname } = await import('node:path');
    const { fileURLToPath } = await import('node:url');
    const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
    const js = readFileSync(join(root, 'js/pages/settings.js'), 'utf8');
    expect(js).toContain('requireCheckbox: true');
    expect(js).toContain('I understand these emails will be visible to teammates');
    expect(js).toContain('requireTypedConfirm: !!inboxSent');
    expect(js).toContain("typedConfirmPhrase: 'SHARE'");
  });

  for (const viewport of [
    { name: 'phone-320', width: 320, height: 640 },
    { name: 'phone-375', width: 375, height: 812 },
    { name: 'phone-414', width: 414, height: 896 },
    { name: 'tablet-768', width: 768, height: 1024 },
    { name: 'desktop-1024', width: 1024, height: 768 },
    { name: 'desktop-1280', width: 1280, height: 900 },
  ]) {
    test(`Sales Inbox + My email: no overflow at ${viewport.name}`, async ({ page }) => {
      test.setTimeout(120000);
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await login(page, credsFromEnv('ADMIN'));

      await page.goto('/apps/customercheck/sales-inbox');
      await page.waitForSelector('#crm-sales-inbox-root[data-crm-bachus-mail="1"]', { timeout: 30000 });
      await expect(page.locator('#crm-sales-inbox-root')).toBeVisible();
      const inboxOverflow = await page.evaluate(() => {
        const doc = document.scrollingElement || document.documentElement;
        const root = document.getElementById('crm-sales-inbox-root');
        return {
          doc: doc.scrollWidth - doc.clientWidth,
          root: root ? root.scrollWidth - root.clientWidth : 0,
        };
      });
      expect(inboxOverflow.doc, `inbox doc overflow @ ${viewport.name}`).toBeLessThanOrEqual(2);
      expect(inboxOverflow.root, `inbox root overflow @ ${viewport.name}`).toBeLessThanOrEqual(2);

      const toolbarDir = await page.evaluate(() => {
        const el = document.querySelector('#app-content.crm-app .crm-sales-inbox__toolbar');
        return el ? getComputedStyle(el).flexDirection : '';
      });
      if (viewport.width < 768) {
        expect(toolbarDir, `toolbar stacks on mobile @ ${viewport.name}`).toBe('column');
        const tabs = page.locator('#crm-sales-inbox-tabs [role="tab"]');
        const count = await tabs.count();
        for (let i = 0; i < count; i++) {
          const box = await tabs.nth(i).boundingBox();
          expect(box, `tab ${i} box @ ${viewport.name}`).toBeTruthy();
          expect(box.height, `tab ${i} height @ ${viewport.name}`).toBeGreaterThanOrEqual(44);
        }
      } else {
        // Desktop/tablet sacred: row toolbar (≥768) — mobile MQ is max-width 767 only.
        expect(toolbarDir, `toolbar stays row @ ${viewport.name}`).toBe('row');
      }

      await page.goto('/apps/customercheck/settings/my-email#crm-ws-personal-mail-mine');
      await page.waitForSelector('#section-my-email, #crm-ws-personal-mail-mine', { timeout: 30000 });
      const mailOverflow = await page.evaluate(() => {
        const doc = document.scrollingElement || document.documentElement;
        const section = document.getElementById('section-my-email')
          || document.getElementById('crm-ws-personal-mail-mine');
        return {
          doc: doc.scrollWidth - doc.clientWidth,
          section: section ? section.scrollWidth - section.clientWidth : 0,
        };
      });
      expect(mailOverflow.doc, `my-email doc overflow @ ${viewport.name}`).toBeLessThanOrEqual(2);
      expect(mailOverflow.section, `my-email section overflow @ ${viewport.name}`).toBeLessThanOrEqual(2);

      if (viewport.width >= 1024) {
        // Desktop sacred: when connected, Sync stays a large primary (not mobile full-bleed stack).
        // Button may exist in DOM but stay hidden until an inbox is linked — only assert when shown.
        const sync = page.locator('#crm-personal-mail-sync');
        if (await sync.count() && await sync.isVisible()) {
          const box = await sync.boundingBox();
          expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        }
      }
    });
  }
});
