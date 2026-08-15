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
		const head = document.querySelector('head');
		const fromHead = head ? head.getAttribute('data-requesttoken') : '';
		if (fromHead) {
			return fromHead;
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

	function looksLikeHtml(text) {
		const t = String(text || '').trim();
		if (!t) return false;
		return t.charAt(0) === '<' || /<\/?[a-z][\s\S]*>/i.test(t.slice(0, 200));
	}

	function humanMessageFromBody(text, status) {
		const raw = String(text || '').trim();
		if (!raw) {
			return status ? ('HTTP ' + status) : 'Empty response';
		}
		if (looksLikeHtml(raw)) {
			if (status === 503) {
				return 'Server temporarily unavailable (HTTP 503). Wait a moment and try again — Apache may be busy (e.g. installing PHP extensions).';
			}
			if (status === 502 || status === 504) {
				return 'Server gateway error (HTTP ' + status + '). Try again in a moment.';
			}
			if (status === 500) {
				return 'The server had a problem loading this (HTTP 500). Please try again in a moment. If it keeps failing, ask an admin to check the Nextcloud log.';
			}
			return 'Server returned an HTML error page instead of JSON' + (status ? ' (HTTP ' + status + ')' : '') + '.';
		}
		if (raw.length > 280) {
			return raw.slice(0, 277) + '…';
		}
		return raw;
	}

	/**
	 * @param {string} method
	 * @param {string} path
	 * @param {unknown} [body]
	 * @param {Record<string, string>|{ headers?: Record<string, string>, signal?: AbortSignal }} [extra]
	 *        Legacy: plain header map (e.g. Idempotency-Key).
	 *        New: { headers, signal } for AbortController support (mail preview stampede).
	 */
	async function request(method, path, body, extra) {
		let extraHeaders = null;
		let signal = null;
		if (extra && typeof extra === 'object') {
			if (Object.prototype.hasOwnProperty.call(extra, 'signal')
				|| Object.prototype.hasOwnProperty.call(extra, 'headers')) {
				signal = extra.signal || null;
				extraHeaders = extra.headers && typeof extra.headers === 'object' ? extra.headers : null;
			} else {
				extraHeaders = extra;
			}
		}
		const headers = {
			'Accept': 'application/json',
			'requesttoken': requestToken(),
		};
		if (extraHeaders) {
			Object.keys(extraHeaders).forEach((k) => {
				if (extraHeaders[k] != null && extraHeaders[k] !== '') {
					headers[k] = String(extraHeaders[k]);
				}
			});
		}
		const opts = { method: method.toUpperCase(), headers, credentials: 'same-origin' };
		if (signal) {
			opts.signal = signal;
		}
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
				payload = {
					success: false,
					error: 'PARSE_ERROR',
					message: humanMessageFromBody(text, response.status),
				};
			}
		}
		if (!response.ok) {
			const msg = (payload && payload.message)
				? humanMessageFromBody(payload.message, response.status)
				: ('HTTP ' + response.status);
			const err = new Error(msg);
			err.status = response.status;
			err.payload = payload && typeof payload === 'object'
				? Object.assign({}, payload, { message: msg })
				: { success: false, error: 'HTTP_ERROR', message: msg };
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
