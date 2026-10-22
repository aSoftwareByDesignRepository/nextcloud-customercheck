/**
 * @vitest-environment jsdom
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it, vi } from 'vitest';

function loadPicker() {
	const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/country-picker.js');
	const code = readFileSync(path, 'utf8');
	// eslint-disable-next-line no-eval
	eval(code);
	return window.CustomerCheckCountryPicker;
}

function pickerMarkup() {
	return `
		<div class="user-picker crm-country-picker" data-crm-country-picker>
			<div class="user-picker__control">
				<input type="search" id="crm-company-country-search" class="form-input user-picker__search"
					role="combobox" aria-autocomplete="list" aria-expanded="false"
					aria-controls="crm-company-country-list" data-crm-country-search value="Germany" />
				<button type="button" class="user-picker__clear" data-crm-country-clear hidden>×</button>
			</div>
			<input type="hidden" id="crm-company-country" name="country" value="DE" data-crm-country-value />
			<ul id="crm-company-country-list" class="user-picker__list" role="listbox" data-crm-country-list hidden>
				<li class="user-picker__item" role="option" id="crm-company-country-opt-BR" data-code="BR"><span class="user-picker__name">Brazil</span></li>
				<li class="user-picker__item" role="option" id="crm-company-country-opt-DE" data-code="DE"><span class="user-picker__name">Germany</span></li>
				<li class="user-picker__item" role="option" id="crm-company-country-opt-AT" data-code="AT"><span class="user-picker__name">Austria</span></li>
			</ul>
		</div>`;
}

function fire(input, type, init) {
	input.dispatchEvent(new window.Event(type, Object.assign({ bubbles: true }, init || {})));
}

describe('CustomerCheckCountryPicker', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
		delete window.CustomerCheckCountryPicker;
		delete window.CustomerCheckApi;
	});

	it('folds diacritics and case for matching', () => {
		const P = loadPicker();
		expect(P.fold('Österreich')).toBe('osterreich');
		expect(P.fold('Straße')).toBe('strasse');
		expect(P.fold(' BR ')).toBe(' br ');
	});

	it('filterOptions matches localized name and ISO code', () => {
		const P = loadPicker();
		const opts = [
			{ code: 'BR', label: 'Brazil' },
			{ code: 'DE', label: 'Germany' },
			{ code: 'AT', label: 'Austria' },
		];
		expect(P.filterOptions(opts, 'bra').map((o) => o.code)).toEqual(['BR']);
		expect(P.filterOptions(opts, 'br')).toHaveLength(1);
		expect(P.filterOptions(opts, '')).toHaveLength(3);
		expect(P.filterOptions(opts, 'zzz')).toHaveLength(0);
		// Localized label match: a German UI offers "Brasilien", so "bras" hits it.
		expect(P.filterOptions([{ code: 'BR', label: 'Brasilien' }], 'bras').map((o) => o.code)).toEqual(['BR']);
	});

	it('mount wires focus-open, filter, and click commit into the hidden input', () => {
		document.body.innerHTML = pickerMarkup();
		const P = loadPicker();
		P.mountAll(document);

		const search = document.getElementById('crm-company-country-search');
		const hidden = document.getElementById('crm-company-country');
		const list = document.getElementById('crm-company-country-list');

		// Committed label in the input means "browse all" on focus, not a 1-hit filter.
		fire(search, 'focus');
		expect(list.hidden).toBe(false);
		expect(list.querySelectorAll('li[data-code]:not([hidden])')).toHaveLength(3);

		search.value = 'bra';
		fire(search, 'input');
		// Non-matching options leave the DOM (not just `hidden`) — the shared
		// listbox nav walks every [role=option], so arrows/Enter must never be
		// able to land on an invisible country.
		const visible = list.querySelectorAll('li[data-code]');
		expect(visible).toHaveLength(1);
		expect(visible[0].getAttribute('data-code')).toBe('BR');
		expect(list.querySelector('li[data-code="DE"]')).toBeNull();

		visible[0].dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
		expect(hidden.value).toBe('BR');
		expect(search.value).toBe('Brazil');
		expect(list.hidden).toBe(true);
	});

	it('arrow keys + Enter commit a filtered option, never a hidden one', () => {
		document.body.innerHTML = pickerMarkup();
		// Load the real shared nav module so ArrowDown/Enter behave like production.
		const navCode = readFileSync(
			join(dirname(fileURLToPath(import.meta.url)), '../../js/common/user-picker-nav.js'),
			'utf8',
		);
		// eslint-disable-next-line no-eval
		eval(navCode);
		const P = loadPicker();
		P.mountAll(document);

		const search = document.getElementById('crm-company-country-search');
		const hidden = document.getElementById('crm-company-country');
		const list = document.getElementById('crm-company-country-list');

		fire(search, 'focus');
		search.value = 'at';
		fire(search, 'input');
		// Only AT remains reachable; AT is the LAST li in the markup, so a nav
		// implementation that walks hidden options would commit BR instead.
		search.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
		search.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
		expect(hidden.value).toBe('AT');
		expect(search.value).toBe('Austria');
		expect(list.hidden).toBe(true);
	});

	it('shows a muted empty row when nothing matches', () => {
		document.body.innerHTML = pickerMarkup();
		const P = loadPicker();
		P.mountAll(document);
		const search = document.getElementById('crm-company-country-search');
		const list = document.getElementById('crm-company-country-list');
		fire(search, 'focus');
		search.value = 'zzz';
		fire(search, 'input');
		expect(list.querySelector('li[data-code]')).toBeNull();
		expect(list.textContent).toContain('No country matches.');
	});

	it('commits an exact ISO code typed by hand on blur', () => {
		document.body.innerHTML = pickerMarkup();
		const P = loadPicker();
		P.mountAll(document);
		const search = document.getElementById('crm-company-country-search');
		const hidden = document.getElementById('crm-company-country');
		search.value = 'at';
		fire(search, 'focusout');
		expect(hidden.value).toBe('AT');
		expect(search.value).toBe('Austria');
	});

	it('accepts an explicit ISO code on blur when options failed to load', async () => {
		document.body.innerHTML = '<span data-crm-country-slot data-base="crm-learn-5" data-field="canonical"></span>';
		window.CustomerCheckApi = {
			get: vi.fn(async () => { throw new Error('network down'); }),
		};
		const P = loadPicker();
		await P.upgradeSlots(document);

		const search = document.getElementById('crm-learn-5-search');
		const hidden = document.getElementById('crm-learn-5');
		// No options loaded — a typed code must still commit (degraded, not dead end).
		search.value = 'br';
		fire(search, 'focusout');
		expect(hidden.value).toBe('BR');
		expect(search.value).toBe('BR');
		// …but free prose still snaps back to the committed value.
		search.value = 'nowhere';
		fire(search, 'focusout');
		expect(hidden.value).toBe('BR');
		expect(search.value).toBe('BR');
	});

	it('restores the committed label when free text matches nothing', () => {
		document.body.innerHTML = pickerMarkup();
		const P = loadPicker();
		P.mountAll(document);
		const search = document.getElementById('crm-company-country-search');
		const hidden = document.getElementById('crm-company-country');
		search.value = 'Atlantis';
		fire(search, 'focusout');
		expect(hidden.value).toBe('DE');
		expect(search.value).toBe('Germany');
	});

	it('upgradeSlots builds a picker from fetched countries', async () => {
		document.body.innerHTML = '<span data-crm-country-slot data-base="crm-learn-7" data-field="canonical"></span>';
		window.CustomerCheckApi = {
			get: vi.fn(async () => ({ data: { items: [
				{ code: 'BR', label: 'Brazil' },
				{ code: 'DE', label: 'Germany' },
			] } })),
		};
		const P = loadPicker();
		await P.upgradeSlots(document);

		const search = document.getElementById('crm-learn-7-search');
		const hidden = document.getElementById('crm-learn-7');
		expect(search).not.toBeNull();
		expect(search.getAttribute('role')).toBe('combobox');
		expect(hidden.getAttribute('data-crm-country-value')).not.toBeNull();
		expect(document.querySelectorAll('#crm-learn-7-list li[data-code]')).toHaveLength(2);

		// Commit via click still writes the code to the hidden carrier.
		fire(search, 'focus');
		document.querySelector('#crm-learn-7-list li[data-code="BR"]')
			.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
		expect(hidden.value).toBe('BR');
	});

	it('ensureOptions returns the full set even after a mounted picker was filtered', async () => {
		document.body.innerHTML = pickerMarkup()
			+ '<span data-crm-country-slot data-base="crm-learn-3" data-field="canonical"></span>';
		const P = loadPicker();
		P.mountAll(document);
		const search = document.getElementById('crm-company-country-search');
		fire(search, 'focus');
		search.value = 'bra';
		fire(search, 'input');
		// The first picker's DOM list is now narrowed to BR — the dynamic picker
		// must still receive the complete option set (no API needed).
		await P.upgradeSlots(document);
		expect(document.querySelectorAll('#crm-learn-3-list li[data-code]')).toHaveLength(3);
	});

	it('upgradeSlots preserves a pre-set value label', async () => {
		document.body.innerHTML = '<span data-crm-country-slot data-base="crm-learn-9" data-field="canonical" data-value="BR"></span>';
		window.CustomerCheckApi = {
			get: vi.fn(async () => ({ data: { items: [
				{ code: 'BR', label: 'Brazil' },
				{ code: 'DE', label: 'Germany' },
			] } })),
		};
		const P = loadPicker();
		await P.upgradeSlots(document);
		expect(document.getElementById('crm-learn-9').value).toBe('BR');
		expect(document.getElementById('crm-learn-9-search').value).toBe('Brazil');
	});
});
