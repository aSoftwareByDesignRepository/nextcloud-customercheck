/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * extends window.CustomerCheckApi with the .url/.get/.post/.upload
 * contract the synced js/transfer.js expects — the app's own api.js is
 * bound to the /api/v1 base while the transfer controller answers
 * {success,data} / {success:false,error:'CODE',context} on
 * /api/admin/transfer/*. All other CustomerCheckApi members
 * (put/del/base/markFields/…) pass through untouched.
 * App-specific — not synced. Loaded after common/api.js, before
 * transfer.js, on the Transfer app-settings section only.
 */
(function () {
	'use strict';
	const base = window.CustomerCheckApi || {};
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			const ctx = (j && (j.context !== undefined ? j.context : j.data)) || {};
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (ctx && typeof ctx === 'object') ? ctx : {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	function url(path) {
		return (window.OC && OC.generateUrl) ? OC.generateUrl('/apps/customercheck' + path) : path;
	}
	window.CustomerCheckApi = Object.assign({}, base, {
		url,
		async get(path, params) {
			let u = url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	});
	// Messaging: CustomerCheckComponents.showToast({type,message}) is the
	// app's toast — the synced transfer.js asks for .alert/.polite; map both
	// and always mirror into the crm-transfer-result-live status region.
	const msg = window.CustomerCheckMessaging || {};
	function live(text) {
		const el = document.getElementById('crm-transfer-result-live');
		if (el) el.textContent = text;
	}
	function toast(text, type) {
		const C = window.CustomerCheckComponents || window.CrmComponents;
		if (C && typeof C.initToasts === 'function' && typeof C.showToast === 'function') {
			C.initToasts();
			C.showToast({ type: type, message: text });
			return;
		}
		if (window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(text);
		}
	}
	if (typeof msg.alert !== 'function') {
		msg.alert = function (text) { toast(text, 'error'); live(text); };
	}
	if (typeof msg.polite !== 'function') {
		msg.polite = function (text) { toast(text, 'info'); live(text); };
	}
	window.CustomerCheckMessaging = msg;
})();
