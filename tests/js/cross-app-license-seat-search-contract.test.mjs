/**
 * Cross-app license seat search response-shape contracts.
 * Guards against DutyCheck-class items-vs-users breakage regenerating.
 *
 * Run: node --test tests/js/cross-app-license-seat-search-contract.test.mjs
 * (from customercheck — reads sibling apps under ../)
 */
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const appsRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

function readApp(app, rel) {
	const p = path.join(appsRoot, app, rel);
	assert.ok(existsSync(p), `missing ${app}/${rel}`);
	return readFileSync(p, 'utf8');
}

const APPS = [
	{
		id: 'dutycheck',
		js: 'js/license-settings.js',
		must: [/res\.data\.items/, /res\.data\.users/, /normalizeSearchHits|raw/],
	},
	{
		id: 'projectcheck',
		js: 'js/license-settings.js',
		must: [/res\.data\.items/, /res\.data\.users/],
	},
	{
		id: 'ticketcheck',
		js: 'js/license-settings.js',
		must: [/res\.data\.items/, /res\.data\.users/],
	},
	{
		id: 'invoicecheck',
		js: 'js/license-settings.js',
		must: [/res\.data\.items/, /res\.data\.users/],
	},
	{
		id: 'deskcheck',
		js: 'js/license.js',
		must: [/data\.users/, /hasSeat/],
	},
	{
		id: 'mobilitycheck',
		js: 'js/license.js',
		must: [/data\.users/, /hasSeat/],
	},
	{
		id: 'arbeitszeitcheck',
		js: 'js/admin-license.js',
		must: [/data\.users/, /hasSeat/],
	},
	{
		id: 'customercheck',
		js: 'js/pages/settings.js',
		must: [/res\.data\.users/, /excludeUidsFn:\s*\(\)\s*=>/, /appendSeatRow/],
	},
];

describe('Cross-app license seat search contracts', () => {
	for (const app of APPS) {
		it(`${app.id} client understands its search payload shape`, () => {
			const js = readApp(app.id, app.js);
			for (const re of app.must) {
				assert.match(js, re, `${app.id} failed ${re}`);
			}
			// Never blindly ignore a dual-shape safety net for items-only panels.
			if (app.js.includes('license-settings.js')) {
				assert.match(js, /res\.data\.users/, `${app.id} must fall back to users`);
			}
		});
	}
});
