/**
 * CustomerCheck desklet chrome contracts — CSS + load() registration.
 */
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(path.join(appRoot, rel), 'utf8');

describe('customercheck dashboard desklet chrome', () => {
	it('ships desklet-nextcloud.css with AA touch and focus', () => {
		assert.equal(existsSync(path.join(appRoot, 'css/desklet-nextcloud.css')), true);
		const css = read('css/desklet-nextcloud.css');
		assert.match(css, /\.panel--header img\[src\*=\"\/customercheck\/\"\]/);
		assert.match(css, /customercheck/);
		assert.match(css, /min-height:\s*44px/);
		assert.match(css, /:focus-visible/);
		assert.match(css, /app-dashboard\.svg/);
		assert.match(css, /background-invert-if-dark/);
		assert.match(css, /prefers-reduced-motion/);
		assert.match(css, /a\.more/);
	});

	it('TodayWidget load() registers desklet styles', () => {
		const trait = read('lib/Dashboard/RegistersDeskletStylesTrait.php');
		assert.match(trait, /Util::addStyle\(Application::APP_ID, 'desklet-nextcloud'\)/);
		const src = read('lib/Dashboard/TodayWidget.php');
		assert.match(src, /RegistersDeskletStylesTrait/);
		assert.match(src, /registerDeskletStylesForWidget\(\)/);
	});
});
