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

	async function request(method, path, body, extraHeaders) {
		const headers = {
			'Accept': 'application/json',
			'requesttoken': requestToken(),
		};
		if (extraHeaders && typeof extraHeaders === 'object') {
			Object.keys(extraHeaders).forEach((k) => {
				if (extraHeaders[k] != null && extraHeaders[k] !== '') {
					headers[k] = String(extraHeaders[k]);
				}
			});
		}
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

	function makeIdempotencyKey() {
		if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
			return crypto.randomUUID();
		}
		return 'idem-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
	}

	window.CustomerCheckApi = {
		get: (path) => request('GET', path),
		post: (path, body, extraHeaders) => request('POST', path, body, extraHeaders),
		put: (path, body, extraHeaders) => request('PUT', path, body, extraHeaders),
		del: (path, body) => request('DELETE', path, body),
		base: BASE,
		activeWorkspaceId: activeWorkspaceId,
		makeIdempotencyKey: makeIdempotencyKey,
	};
})();
