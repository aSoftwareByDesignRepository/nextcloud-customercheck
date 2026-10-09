// @ts-check
/**
 * Fixture cleanup for specs that create entities through the UI.
 * DELETE /companies/{id} enforces the destructive-action contract:
 * confirmSlug=<slug> verbatim.
 */

/** session-cookie JSON call into the app API (browser context = logged in) */
export async function api(page, method, path, body) {
	return page.evaluate(async ({ method: m, path: p, body: b }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '';
		const res = await fetch(p, {
			method: m,
			credentials: 'same-origin',
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: b === undefined ? undefined : JSON.stringify(b),
		});
		return { status: res.status, body: await res.json().catch(() => ({})) };
	}, { method, path, body });
}

/** Best-effort delete; never throws (safe in finally). */
export async function deleteCompany(page, id) {
	try {
		const detail = await api(page, 'GET', `/index.php/apps/customercheck/api/v1/companies/${id}`);
		const company = (detail.body && detail.body.data) || {};
		const slug = (company.company && company.company.slug) || company.slug;
		await api(page, 'DELETE', `/index.php/apps/customercheck/api/v1/companies/${id}`, { confirmSlug: slug });
	} catch { /* best-effort cleanup */ }
}

/** Extract /companies/{id} from a URL and delete the company. */
export async function deleteCompanyAt(page, url) {
	const m = String(url).match(/\/companies\/(\d+)/);
	if (m) await deleteCompany(page, m[1]);
}
