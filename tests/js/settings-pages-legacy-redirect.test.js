/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

function loadLegacy(relative, exportName) {
	const code = readFileSync(join(root, relative), 'utf8');
	const sandbox = {
		window: {},
		console,
		URLSearchParams,
		Object,
		String,
	};
	sandbox.window = sandbox;
	vm.runInNewContext(code, sandbox, { filename: relative });
	return sandbox[exportName] || sandbox.window[exportName];
}

const SECTION_URLS = {
	mailbox: '/apps/customercheck/settings/mailbox',
	preferences: '/apps/customercheck/settings/preferences',
	'review-sources': '/apps/customercheck/settings/review-sources',
	circles: '/apps/customercheck/settings/circles',
	integrations: '/apps/customercheck/settings/integrations',
};

const APP_SECTION_URLS = {
	access: '/apps/customercheck/app-settings/access',
	jobs: '/apps/customercheck/app-settings/jobs',
	tokens: '/apps/customercheck/app-settings/tokens',
	license: '/apps/customercheck/app-settings/license',
	'product-codes': '/apps/customercheck/app-settings/product-codes',
};

function docStub({ section = 'mailbox', urls = { settingsSections: SECTION_URLS }, attr = 'data-crm-settings-section' } = {}) {
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

describe('workspace-settings-legacy-redirect', () => {
	const api = loadLegacy('js/workspace-settings-legacy-redirect.js', 'CustomerCheckWorkspaceSettingsLegacyRedirect');

	it('exports frozen anchor map covering review-sources', () => {
		expect(api.ANCHOR_SECTIONS['crm-review-sources']).toBe('review-sources');
		expect(Object.isFrozen(api.ANCHOR_SECTIONS)).toBe(true);
	});

	it('forwards hash to sibling section URL + fragment', () => {
		const url = api.resolve(docStub({ section: 'mailbox' }), { hash: '#crm-review-sources', search: '' });
		expect(url).toBe('/apps/customercheck/settings/review-sources#crm-review-sources');
	});

	it('returns null when already on the target section', () => {
		expect(api.resolve(docStub({ section: 'review-sources' }), { hash: '#crm-review-sources', search: '' })).toBeNull();
	});

	it('fails closed when section URL missing from payload', () => {
		expect(api.resolve(docStub({ section: 'mailbox', urls: { settingsSections: {} } }), { hash: '#crm-review-sources', search: '' })).toBeNull();
	});

	it('preserves workspaceId from location', () => {
		const url = api.withWorkspaceId('/apps/customercheck/settings/preferences', { search: '?workspaceId=42' });
		expect(url).toContain('workspaceId=42');
	});
});

describe('app-settings-legacy-redirect', () => {
	const api = loadLegacy('js/app-settings-legacy-redirect.js', 'CustomerCheckAppSettingsLegacyRedirect');

	it('forwards license anchor from access page', () => {
		const url = api.resolve(
			docStub({
				section: 'access',
				urls: { appSettingsSections: APP_SECTION_URLS },
				attr: 'data-crm-app-settings-section',
			}),
			{ hash: '#section-license', search: '' },
		);
		expect(url).toBe('/apps/customercheck/app-settings/license#section-license');
	});

	it('ignores unknown fragments', () => {
		expect(api.resolve(
			docStub({
				section: 'access',
				urls: { appSettingsSections: APP_SECTION_URLS },
				attr: 'data-crm-app-settings-section',
			}),
			{ hash: '#evil', search: '' },
		)).toBeNull();
	});
});
