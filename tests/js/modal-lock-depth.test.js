/**
 * @vitest-environment node
 *
 * Regression: openModal on an already-open <dialog> used to call
 * _lockPageBehindModal() unconditionally — a second open bumped
 * _modalLockDepth to 2 while one closeModal released only once,
 * stranding depth>0 with body overflow:hidden + inert stuck on
 * #crm-main-content/#header (page dead). Locks are now accounted
 * per-dialog via modal._crmLockHeld.
 */
import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';
import vm from 'vm';

const SRC = fs.readFileSync(
  path.join(__dirname, '..', '..', 'js', 'common', 'components.js'),
  'utf8',
);

function fakeEl(tag = 'div') {
  const el = {
    tagName: tag.toUpperCase(),
    _attrs: {},
    style: {},
    dataset: {},
    children: [],
    isConnected: true,
    parentNode: null,
    classList: { contains: () => false, add() {}, remove() {} },
    setAttribute(k, v) { this._attrs[k] = String(v); },
    removeAttribute(k) { delete this._attrs[k]; },
    getAttribute(k) { return k in this._attrs ? this._attrs[k] : null; },
    hasAttribute(k) { return k in this._attrs; },
    addEventListener() {},
    removeEventListener() {},
    querySelector() { return null; },
    querySelectorAll() { return []; },
    appendChild(c) { this.children.push(c); c.parentNode = this; return c; },
    remove() { this.isConnected = false; this.parentNode = null; },
    contains() { return false; },
    closest() { return null; },
    matches() { return false; },
    focus() {},
  };
  return el;
}

function fakeDialog(id) {
  const d = fakeEl('dialog');
  d.id = id;
  d.open = false;
  d.showModal = () => { d.open = true; };
  d.close = () => { d.open = false; };
  return d;
}

function boot() {
  const nodes = new Map();
  const header = fakeEl('header');
  const main = fakeEl('main');
  nodes.set('header', header);
  nodes.set('crm-main-content', main);

  const document = {
    readyState: 'loading', // defer init() — we drive methods directly
    documentElement: { dataset: {} },
    body: { style: {}, contains: () => false },
    activeElement: null,
    addEventListener() {},
    removeEventListener() {},
    getElementById: (id) => nodes.get(id) || null,
    querySelectorAll: () => [],
    createElement: (tag) => fakeEl(tag),
  };
  const sandbox = {
    console,
    setTimeout,
    clearTimeout,
    document,
    window: { dispatchEvent() {}, getComputedStyle: () => ({ display: '', visibility: '' }) },
    HTMLElement: class HTMLElement {},
    Element: class Element {},
    MutationObserver: class { observe() {} takeRecords() { return []; } disconnect() {} },
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
  };
  vm.createContext(sandbox);
  vm.runInContext(`${SRC}; this.__C = window.CustomerCheckComponents;`, sandbox);
  return { C: sandbox.__C, header, main, body: document.body };
}

describe('modal page-lock accounting', () => {
  it('locks once even when openModal runs twice on the same open dialog', () => {
    const { C, main, body } = boot();
    const d = fakeDialog('m1');
    C.openModal(d);
    expect(d.open).toBe(true);
    expect(C._modalLockDepth).toBe(1);
    // The defect: a second openModal on the already-open dialog bumped
    // depth to 2 — one close then stranded overflow+inert forever.
    C.openModal(d);
    expect(C._modalLockDepth).toBe(1);
    C.closeModal(d);
    expect(C._modalLockDepth).toBe(0);
    expect(body.style.overflow).toBe('');
    expect(main.hasAttribute('inert')).toBe(false);
  });

  it('closeModal on a dialog that never held a lock does not release another modal\'s lock', () => {
    const { C, main } = boot();
    const a = fakeDialog('a');
    C.openModal(a);
    expect(C._modalLockDepth).toBe(1);
    const stray = fakeDialog('stray');
    stray.open = true; // opened by foreign code (raw showModal)
    C.closeModal(stray);
    expect(C._modalLockDepth).toBe(1); // a's lock survives
    expect(main.hasAttribute('inert')).toBe(true);
    C.closeModal(a);
    expect(C._modalLockDepth).toBe(0);
    expect(main.hasAttribute('inert')).toBe(false);
  });

  it('reopen after close re-locks and releases cleanly', () => {
    const { C } = boot();
    const d = fakeDialog('m2');
    C.openModal(d);
    C.closeModal(d);
    expect(C._modalLockDepth).toBe(0);
    C.openModal(d);
    expect(C._modalLockDepth).toBe(1);
    C.closeModal(d);
    expect(C._modalLockDepth).toBe(0);
  });

  it('lockBackground/unlockBackground pair independently of dialogs', () => {
    const { C } = boot();
    C.lockBackground();
    expect(C._modalLockDepth).toBe(1);
    const d = fakeDialog('m3');
    C.openModal(d); // modal on top of the overlay: depth 2
    expect(C._modalLockDepth).toBe(2);
    C.closeModal(d);
    expect(C._modalLockDepth).toBe(1); // overlay lock survives
    C.unlockBackground();
    expect(C._modalLockDepth).toBe(0);
  });
});
