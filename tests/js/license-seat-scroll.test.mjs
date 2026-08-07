/**
 * CustomerCheck license seats — no reload / scroll-steal on assign.
 * Run: node --test tests/js/license-seat-scroll.test.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(path.join(appRoot, rel), 'utf8');

describe('CustomerCheck license seat UX', () => {
	const js = read('js/pages/settings.js');
	const tpl = read('templates/settings/license.php');

	it('assigns and removes seats in place without location.reload', () => {
		const assignStart = js.indexOf('async function assignSeat');
		assert.ok(assignStart > 0);
		const assignEnd = js.indexOf('if (seatAdd && canManage)', assignStart);
		const assignBlock = js.slice(assignStart, assignEnd);
		assert.doesNotMatch(assignBlock, /location\.reload/);
		assert.match(assignBlock, /appendSeatRow/);
		assert.match(assignBlock, /seatPicker\.clear/);

		assert.match(js, /function appendSeatRow/);
		assert.match(js, /crm-license-seats-count/);
		assert.match(tpl, /id="crm-license-seats-count"/);
		assert.match(tpl, /data-seat-limit/);
	});

	it('remove seat updates the list without reload', () => {
		assert.match(js, /row\.remove\(\)/);
		assert.match(js, /updateSeatFactsCount/);
		const removeHandler = js.slice(js.indexOf('data-remove-seat'), js.indexOf('crm-settings-jobs-form'));
		assert.doesNotMatch(removeHandler, /location\.reload/);
	});
});
