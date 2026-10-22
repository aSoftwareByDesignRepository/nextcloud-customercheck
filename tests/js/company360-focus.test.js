/**
 * @vitest-environment jsdom
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
let raf;

function focusMarkup() {
	return `
		<div id="app-content">
			<div data-crm-360-focus="1" data-company-id="42">
				<div class="crm-360-focus__chrome">
					<button type="button" data-crm-focus-tab="today" aria-selected="true">Today</button>
					<button type="button" data-crm-focus-tab="people">People</button>
					<button type="button" data-crm-focus-tab="deals">Deals</button>
					<button type="button" data-crm-focus-tab="money">Money</button>
					<button type="button" data-crm-focus-tab="more">More</button>
				</div>
				<section data-crm-focus-panel="today">
					<div id="crm-next-steps"><h3>Next steps</h3></div>
				</section>
				<section data-crm-focus-panel="people"><div id="crm-persons"></div></section>
				<section data-crm-focus-panel="deals"><div id="crm-deals"></div></section>
				<section data-crm-focus-panel="money"><div id="crm-tickets"><h3>Tickets</h3></div></section>
				<section data-crm-focus-panel="more"><div id="crm-account-plan"></div></section>
			</div>
		</div>`;
}

function dispatchHash(newHash) {
	const ev = new window.HashChangeEvent('hashchange', {
		newURL: window.location.origin + window.location.pathname + '#' + newHash,
		oldURL: window.location.href,
	});
	window.dispatchEvent(ev);
}

function selectedTab() {
	const sel = document.querySelector('[data-crm-focus-tab][aria-selected="true"]');
	return sel ? sel.getAttribute('data-crm-focus-tab') : null;
}

describe('CustomerCheck company360 focus tabs — hash jump races', () => {
	beforeAll(() => {
		document.body.innerHTML = focusMarkup();
		window.history.replaceState(null, '', window.location.pathname);
		raf = [];
		vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb) => {
			raf.push(cb);
			return raf.length;
		});
		const code = readFileSync(join(root, 'js/common/company360-focus.js'), 'utf8');
		// eslint-disable-next-line no-eval
		eval(code);
	});

	beforeEach(() => {
		raf.length = 0;
		window.sessionStorage.clear();
	});

	it('initializes with today selected', () => {
		expect(selectedTab()).toBe('today');
	});

	it('a newer hash set while a jump rAF is pending is never clobbered', () => {
		// 1) Navigate to #crm-next-steps → jump scheduled (gen 1, rAF pending).
		window.history.replaceState(null, '', '#crm-next-steps');
		dispatchHash('crm-next-steps');
		expect(selectedTab()).toBe('today');
		expect(raf).toHaveLength(1);

		// 2) User/script navigates to #crm-tickets; the hashchange task is
		//    queued but NOT dispatched yet — gen is still 1.
		window.history.replaceState(null, '', '#crm-tickets');
		expect(window.location.hash).toBe('#crm-tickets');

		// 3) The stale rAF fires. It must not rewrite #crm-next-steps over the
		//    newer hash — that revert is what previously made the pending
		//    hashchange read the old hash and jump back.
		raf.shift()();
		expect(window.location.hash).toBe('#crm-tickets');

		// 4) The pending hashchange now lands on the right section + tab.
		dispatchHash('crm-tickets');
		expect(selectedTab()).toBe('money');
		raf.shift()();
		expect(window.location.hash).toBe('#crm-tickets');
	});

	it('hashchange handler prefers ev.newURL over a reverted location.hash', () => {
		const ev = new window.HashChangeEvent('hashchange', {
			newURL: window.location.origin + window.location.pathname + '#crm-tickets',
			oldURL: window.location.origin + window.location.pathname + '#crm-next-steps',
		});
		// Simulate the revert racing the dispatch: location already back at old hash.
		window.history.replaceState(null, '', '#crm-next-steps');
		window.dispatchEvent(ev);

		expect(selectedTab()).toBe('money');
		raf.shift()();
		expect(window.location.hash).toBe('#crm-tickets');
	});
});
