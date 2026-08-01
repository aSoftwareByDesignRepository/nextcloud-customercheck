/**
 * CSRF-aware JSON API helper for CustomerCheck.
 */
(function () {
	'use strict';

	const APP = 'customercheck';
	const BASE = OC.generateUrl('/apps/' + APP + '/api/v1');

	function requestToken() {
		if (typeof OC !== 'undefined' && OC.requestToken) {
			return OC.requestToken;
		}
		const meta = document.querySelector('meta[name="requesttoken"]');
		return meta ? meta.getAttribute('content') : '';
	}

	/** Active book from the page URL — defense in depth for classic CRM APIs. */
	function activeWorkspaceId() {
		try {
			const q = new URLSearchParams(window.location.search).get('workspaceId');
			const id = parseInt(String(q || '0'), 10);
			return Number.isFinite(id) && id > 0 ? id : 0;
		} catch (e) {
			return 0;
		}
	}

	function withWorkspace(path) {
		const ws = activeWorkspaceId();
		if (ws <= 0 || /[?&]workspaceId=/.test(path)) {
			return path;
		}
		return path + (path.indexOf('?') >= 0 ? '&' : '?') + 'workspaceId=' + encodeURIComponent(String(ws));
	}

	async function request(method, path, body) {
		const headers = {
			'Accept': 'application/json',
			'requesttoken': requestToken(),
		};
		const opts = { method: method.toUpperCase(), headers, credentials: 'same-origin' };
		if (body !== undefined && body !== null) {
			headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		const response = await fetch(BASE + withWorkspace(path), opts);
		let payload = null;
		const text = await response.text();
		if (text) {
			try {
				payload = JSON.parse(text);
			} catch (e) {
				payload = { success: false, error: 'PARSE_ERROR', message: text };
			}
		}
		if (!response.ok) {
			const err = new Error((payload && payload.message) || ('HTTP ' + response.status));
			err.status = response.status;
			err.payload = payload;
			throw err;
		}
		return payload;
	}

	window.CustomerCheckApi = {
		get: (path) => request('GET', path),
		post: (path, body) => request('POST', path, body),
		put: (path, body) => request('PUT', path, body),
		del: (path, body) => request('DELETE', path, body),
		base: BASE,
		activeWorkspaceId: activeWorkspaceId,
	};
})();
