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
		const response = await fetch(BASE + path, opts);
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
	};
})();
