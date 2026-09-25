/**
 * Legacy /app-settings#anchor → split App settings sub-page forwarding.
 *
 * The mega-page era linked to /app-settings#<element-id>; after the settings
 * split those hashes must route to the owning sub-page so old bookmarks and
 * deep links do not silently land on the wrong section.
 *
 * Security: the target URL is read from the server-rendered data-crm-urls
 * payload and selected only through the frozen allowlist below; no fragment
 * content ever becomes a URL by itself.
 */
(function (root) {
	'use strict';

	const ANCHOR_SECTIONS = Object.freeze({
		'section-access': 'access',
		'section-access-heading': 'access',
		'crm-settings-access-form': 'access',
		'section-jobs': 'jobs',
		'section-jobs-heading': 'jobs',
		'crm-settings-jobs-form': 'jobs',
		'section-tokens': 'tokens',
		'section-tokens-heading': 'tokens',
		'section-license': 'license',
		'section-license-heading': 'license',
		'crm-license-form': 'license',
		'section-product-codes': 'product-codes',
		'crm-product-codes-form': 'product-codes',
		'section-personal-mail': 'personal-mail',
		'crm-personal-mail-oauth-form': 'personal-mail',
	});

	/**
	 * @param {string} sectionUrl
	 * @param {object} loc
	 * @returns {string}
	 */
	function withWorkspaceId(sectionUrl, loc) {
		try {
			const locSearch = String((loc && loc.search) || '');
			const locParams = new URLSearchParams(
				locSearch.startsWith('?') ? locSearch.slice(1) : locSearch,
			);
			const wid = locParams.get('workspaceId');
			if (!wid || !/^\d+$/.test(wid)) {
				return sectionUrl;
			}
			const qIndex = sectionUrl.indexOf('?');
			const path = qIndex === -1 ? sectionUrl : sectionUrl.slice(0, qIndex);
			const search = qIndex === -1 ? '' : sectionUrl.slice(qIndex + 1);
			const sectionParams = new URLSearchParams(search);
			if (!sectionParams.has('workspaceId')) {
				sectionParams.set('workspaceId', wid);
			}
			const qs = sectionParams.toString();
			return qs ? path + '?' + qs : path;
		} catch (_err) {
			return sectionUrl;
		}
	}

	/**
	 * @param {object} doc
	 * @param {object} loc
	 * @returns {string|null}
	 */
	function resolve(doc, loc) {
		const hash = String((loc && loc.hash) || '').replace(/^#/, '');
		if (!Object.prototype.hasOwnProperty.call(ANCHOR_SECTIONS, hash)) {
			return null;
		}
		const targetSection = ANCHOR_SECTIONS[hash];
		const rootEl = doc && typeof doc.getElementById === 'function'
			? doc.getElementById('app-content')
			: null;
		if (!rootEl || typeof rootEl.getAttribute !== 'function') {
			return null;
		}
		const currentSection = String(rootEl.getAttribute('data-crm-app-settings-section') || '');
		if (currentSection === '' || currentSection === targetSection) {
			return null;
		}
		let urls = null;
		try {
			urls = JSON.parse(String(rootEl.getAttribute('data-crm-urls') || '{}'));
		} catch (_err) {
			return null;
		}
		const sectionUrl = urls && urls.appSettingsSections
			? urls.appSettingsSections[targetSection]
			: null;
		if (typeof sectionUrl !== 'string' || sectionUrl === '') {
			return null;
		}
		return withWorkspaceId(sectionUrl, loc) + '#' + hash;
	}

	const api = Object.freeze({ ANCHOR_SECTIONS, resolve, withWorkspaceId });
	if (root) {
		root.CustomerCheckAppSettingsLegacyRedirect = api;
	}
})(typeof window !== 'undefined' ? window : null);
