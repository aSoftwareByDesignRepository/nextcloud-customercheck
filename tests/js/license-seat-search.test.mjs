/**
 * CustomerCheck license seat picker — search + assign contracts.
 * Run: node --test tests/js/license-seat-search.test.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const settingsJs = readFileSync(path.join(appRoot, 'js/pages/settings.js'), 'utf8');
const directoryPhp = readFileSync(path.join(appRoot, 'lib/Service/DirectoryOptionsService.php'), 'utf8');

describe('CustomerCheck license seat search', () => {
	it('directory search merges display-name hits and skips disabled users', () => {
		assert.match(directoryPhp, /searchDisplayName/);
		assert.match(directoryPhp, /isEnabled/);
		assert.match(directoryPhp, /mb_strlen\(\$query\) < 2/);
	});

	it('seat picker filters already-seated uids and shows empty/error states', () => {
		// Call-site option key (not the helper typedef) — mutation renames this to break filtering.
		assert.match(settingsJs, /bindDirectoryUserPicker\(\{[\s\S]*?excludeUidsFn:\s*\(\)\s*=>[\s\S]*?data-seat-uid/);
		assert.match(settingsJs, /No matching users found\./);
		assert.match(settingsJs, /Could not search the directory\. Try again\./);
		assert.match(settingsJs, /Searching…/);
		assert.match(settingsJs, /aria-busy/);
		assert.match(settingsJs, /seatAssignInFlight/);
	});

	it('accepts data.users envelope and bare users array', () => {
		assert.match(settingsJs, /res\.data\.users/);
		assert.match(settingsJs, /res\.users/);
	});

	it('assigns seats in place without location.reload', () => {
		const start = settingsJs.indexOf('async function assignSeat');
		const end = settingsJs.indexOf('if (seatAdd && canManage)', start);
		assert.ok(start > 0 && end > start);
		const block = settingsJs.slice(start, end);
		assert.doesNotMatch(block, /location\.reload/);
		assert.match(block, /appendSeatRow/);
		assert.match(block, /Api\.post\('\/license\/seats'/);
	});
});
