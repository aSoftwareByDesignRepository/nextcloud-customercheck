/**
 * @vitest-environment node
 */
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const src = readFileSync(join(root, 'js/common/mail-sync-now.js'), 'utf8');

function loadMailSync() {
  function makeEl(tag) {
    const el = {
      tagName: String(tag).toUpperCase(),
      textContent: '',
      disabled: false,
      className: '',
      id: '',
      hidden: false,
      attributes: {},
      children: [],
      listeners: {},
      setAttribute(k, v) {
        this.attributes[k] = v;
        if (k === 'id') {
          this.id = v;
        }
      },
      getAttribute(k) {
        return this.attributes[k];
      },
      removeAttribute(k) {
        delete this.attributes[k];
      },
      addEventListener(type, fn) {
        this.listeners[type] = fn;
      },
      click() {
        if (this.listeners.click) {
          this.listeners.click();
        }
      },
      replaceChildren(...nodes) {
        this.children = nodes;
      },
      appendChild(child) {
        this.children.push(child);
        return child;
      },
    };
    return el;
  }

  const globalObj = {
    window: {},
    CustomerCheckMailSync: null,
    document: {
      documentElement: { lang: 'en' },
      createElement(tag) {
        return makeEl(tag);
      },
    },
    navigator: { language: 'en-US' },
  };
  globalObj.window = globalObj;
  vm.runInNewContext(src, globalObj);
  return globalObj.CustomerCheckMailSync;
}

const t = (msg, args) => {
  let out = msg;
  (args || []).forEach((a) => {
    out = out.replace('%s', a);
  });
  return out;
};

describe('mail-sync-now.js', () => {
  it('exports granny-readable status line with interval and last sync', () => {
    const api = loadMailSync();
    const line = api.statusLine(t, {
      mailboxCount: 1,
      lastSyncAt: '2026-08-13T10:00:00',
      backgroundIntervalMinutes: 15,
    });
    expect(line).toContain('15');
    expect(line).toContain('Last sync:');
  });

  it('shows not-synced-yet when lastSyncAt missing', () => {
    const api = loadMailSync();
    const line = api.statusLine(t, { mailboxCount: 2, lastSyncAt: null, backgroundIntervalMinutes: 15 });
    expect(line).toContain('Not synced yet.');
    expect(line).toContain('2 shared mailboxes connected.');
  });

  it('omits multi-count for a single mailbox', () => {
    const api = loadMailSync();
    const line = api.statusLine(t, { mailboxCount: 1, lastSyncAt: null, backgroundIntervalMinutes: 15 });
    expect(line).not.toContain('shared mailboxes connected');
    expect(line).toContain('Not synced yet.');
  });

  it('mountBar wires region, live status, and sync-all POST', async () => {
    const api = loadMailSync();
    const host = {
      hidden: false,
      className: '',
      attributes: {},
      children: [],
      setAttribute(k, v) {
        this.attributes[k] = v;
      },
      getAttribute(k) {
        return this.attributes[k];
      },
      removeAttribute(k) {
        delete this.attributes[k];
      },
      replaceChildren(...nodes) {
        this.children = nodes;
      },
    };

    const posts = [];
    let resolvePost;
    const postPromise = new Promise((resolve) => {
      resolvePost = resolve;
    });

    api.mountBar(host, {
      t,
      summary: { mailboxCount: 1, lastSyncAt: null, backgroundIntervalMinutes: 15 },
      canSync: true,
      workspaceId: 3,
      apiPost(path, body) {
        posts.push({ path, body });
        return postPromise;
      },
    });

    expect(host.hidden).toBe(false);
    expect(host.className).toBe('crm-mail-sync-bar');
    expect(host.attributes['aria-label']).toBe('Shared mailbox sync');

    const status = host.children[0];
    const btn = host.children[1].children[0];
    expect(status.getAttribute('role')).toBe('status');
    expect(status.getAttribute('aria-live')).toBe('polite');
    expect(btn.textContent).toBe('Sync now');

    btn.click();
    btn.click();
    expect(posts).toHaveLength(1);
    expect(posts[0].path).toBe('/workspaces/3/mailboxes/sync-all');
    expect(host.attributes['aria-busy']).toBe('true');

    resolvePost({ message: 'Shared mailbox is up to date.', lastSyncAt: '2026-08-13 12:00:00' });
    await postPromise;
    await new Promise((r) => setTimeout(r, 0));

    expect(status.textContent).toContain('up to date');
    expect(host.attributes['aria-busy']).toBeUndefined();
  });

  it('disables sync for viewers with aria-disabled', () => {
    const api = loadMailSync();
    const host = {
      hidden: false,
      className: '',
      attributes: {},
      children: [],
      setAttribute(k, v) {
        this.attributes[k] = v;
      },
      getAttribute(k) {
        return this.attributes[k];
      },
      removeAttribute() {},
      replaceChildren(...nodes) {
        this.children = nodes;
      },
    };

    api.mountBar(host, {
      t,
      summary: { mailboxCount: 1, lastSyncAt: null, backgroundIntervalMinutes: 15 },
      canSync: false,
      workspaceId: 1,
      apiPost: vi.fn(),
    });

    const btn = host.children[1].children[0];
    expect(btn.disabled).toBe(true);
    expect(btn.getAttribute('aria-disabled')).toBe('true');
  });

  it('contract: source has WCAG and race guards', () => {
    expect(src).toContain('aria-live');
    expect(src).toContain('syncing');
    expect(src).toContain('aria-busy');
    expect(src).toContain('aria-disabled');
    expect(src).toContain('statusLine(opts.t, opts.summary)');
  });
});
