/**
 * Legacy /settings#anchor → split Workspace settings sub-page forwarding.
 *
 * The mega-page era linked to /settings#<element-id>; after the settings split
 * (SETTINGS-PAGES-STANDARD) those hashes must route to the owning sub-page so
 * old bookmarks and deep links do not silently land on the wrong section.
 *
 * Security: the target URL is read from the server-rendered data-crm-urls
 * payload and selected only through the frozen allowlist below; no fragment
 * content ever becomes a URL by itself.
 */
(function (root) {
	'use strict';

	const ANCHOR_SECTIONS = Object.freeze({
		'section-my-email': 'my-email',
		'section-my-email-heading': 'my-email',
		'crm-ws-personal-mail-mine': 'my-email',
		'crm-ws-personal-mail-mine-title': 'my-email',
		'section-workspace-mailbox': 'mailbox',
		'section-workspace-mailbox-heading': 'mailbox',
		'crm-ws-current': 'mailbox',
		'crm-mailbox-form': 'mailbox',
		'crm-value-maps': 'mailbox',
		'section-preferences': 'preferences',
		'section-preferences-heading': 'preferences',
		'crm-settings-me-form': 'preferences',
		'section-checklists': 'checklists',
		'section-checklists-heading': 'checklists',
		'crm-checklist-templates': 'checklists',
		'crm-cl-tmpl-new': 'checklists',
		'crm-review-sources': 'review-sources',
		'crm-review-sources-title': 'review-sources',
		'crm-review-sources-form': 'review-sources',
		'section-promises-roll-call': 'promises',
		'section-promises-heading': 'promises',
		'crm-promises-roll-call': 'promises',
		'section-integrations': 'integrations',
		'section-integrations-heading': 'integrations',
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
		const currentSection = String(rootEl.getAttribute('data-crm-settings-section') || '');
		if (currentSection === '' || currentSection === targetSection) {
			return null;
		}
		let urls = null;
		try {
			urls = JSON.parse(String(rootEl.getAttribute('data-crm-urls') || '{}'));
		} catch (_err) {
			return null;
		}
		const sectionUrl = urls && urls.settingsSections
			? urls.settingsSections[targetSection]
			: null;
		if (typeof sectionUrl !== 'string' || sectionUrl === '') {
			return null;
		}
		return withWorkspaceId(sectionUrl, loc) + '#' + hash;
	}

	const api = Object.freeze({ ANCHOR_SECTIONS, resolve, withWorkspaceId });
	if (root) {
		root.CustomerCheckWorkspaceSettingsLegacyRedirect = api;
	}
})(typeof window !== 'undefined' ? window : null);
