// @ts-check
/**
 * Legacy /settings#anchor and /app-settings#anchor → split sub-page forwarding.
 *
 * Regression lock: the mega-page era deep-linked to /settings#<element-id>;
 * after the settings split those hashes must route to the owning sub-page.
 * The redirect reads its allowlist + server-rendered data-crm-urls only —
 * fragment text never becomes a URL by itself.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const appRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

/** Evaluate one of the IIFE redirect modules with a stubbed window. */
function loadModule(file) {
	const src = readFileSync(join(appRoot, 'js', file), 'utf8');
	const win = {};
	// eslint-disable-next-line no-new-func
	new Function('window', `${src}\n;return window;`)(win);
	return win;
}

function fakeDoc({ section, urls, attr = 'data-crm-settings-section' }) {
	return {
		getElementById(id) {
			if (id !== 'app-content') return null;
			return {
				getAttribute(name) {
					if (name === attr) return section;
					if (name === 'data-crm-urls') return JSON.stringify(urls);
					return null;
				},
			};
		},
	};
}

const WS_URLS = { settingsSections: { 'review-sources': '/apps/customercheck/settings/review-sources' } };
const APP_URLS = { appSettingsSections: { license: '/apps/customercheck/app-settings/license' } };

describe('workspace settings legacy hash redirect', () => {
	const win = loadModule('workspace-settings-legacy-redirect.js');
	const api = win.CustomerCheckWorkspaceSettingsLegacyRedirect;

	it('is exported and frozen', () => {
		expect(api).toBeTruthy();
		expect(Object.isFrozen(api)).toBe(true);
	});

	it('maps the mega-page review-sources hash to its sub-page, preserving hash + workspaceId', () => {
		const doc = fakeDoc({ section: 'mailbox', urls: WS_URLS });
		const out = api.resolve(doc, { hash: '#crm-review-sources', search: '?workspaceId=7' });
		expect(out).toBe('/apps/customercheck/settings/review-sources?workspaceId=7#crm-review-sources');
	});

	it('maps every anchor only to a valid workspace section', () => {
		const allowed = new Set(['my-email', 'mailbox', 'preferences', 'checklists', 'review-sources', 'promises', 'integrations']);
		for (const [anchor, section] of Object.entries(api.ANCHOR_SECTIONS)) {
			expect(allowed.has(section), `${anchor} → ${section} must be a live section`).toBe(true);
		}
	});

	it('returns null for unknown hashes (no fragment-to-URL leakage)', () => {
		const doc = fakeDoc({ section: 'mailbox', urls: WS_URLS });
		expect(api.resolve(doc, { hash: '#javascript-alert-1', search: '' })).toBeNull();
		expect(api.resolve(doc, { hash: '', search: '' })).toBeNull();
	});

	it('returns null when already on the target section', () => {
		const doc = fakeDoc({ section: 'review-sources', urls: WS_URLS });
		expect(api.resolve(doc, { hash: '#crm-review-sources', search: '' })).toBeNull();
	});

	it('returns null when the section url is absent from data-crm-urls', () => {
		const doc = fakeDoc({ section: 'mailbox', urls: { settingsSections: {} } });
		expect(api.resolve(doc, { hash: '#crm-review-sources', search: '' })).toBeNull();
	});
});

describe('app settings legacy hash redirect', () => {
	const win = loadModule('app-settings-legacy-redirect.js');
	const api = win.CustomerCheckAppSettingsLegacyRedirect;

	it('maps the license hash to its sub-page on the app-settings attribute', () => {
		const doc = fakeDoc({ section: 'access', urls: APP_URLS, attr: 'data-crm-app-settings-section' });
		const out = api.resolve(doc, { hash: '#crm-license-form', search: '' });
		expect(out).toBe('/apps/customercheck/app-settings/license#crm-license-form');
	});

	it('returns null for unknown hashes', () => {
		const doc = fakeDoc({ section: 'access', urls: APP_URLS, attr: 'data-crm-app-settings-section' });
		expect(api.resolve(doc, { hash: '#nope', search: '' })).toBeNull();
	});
});
