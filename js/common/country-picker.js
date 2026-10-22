/**
 * Country combobox (WAI-ARIA, design system §3.6.7) — binds the markup from
 * templates/common/crm-country-picker.php and upgrades dynamically injected
 * [data-crm-country-slot] placeholders (e.g. Assign "Fix country mapping").
 *
 * Behaviour: type to filter localized names or ISO codes (diacritic- and
 * case-insensitive), arrows + Enter via CustomerCheckUserPickerNav, Escape
 * closes, click/mousedown commits, blur resolves free text (exact code or
 * exact label commits, anything else restores the committed label).
 */
(function (global) {
	'use strict';

	function tr(msg) {
		if (typeof global.t === 'function') {
			return global.t('customercheck', msg);
		}
		return msg;
	}

	/** Diacritic-insensitive fold: "Österreich"→"osterreich", "ß"→"ss". */
	function fold(value) {
		return String(value == null ? '' : value)
			.toLowerCase()
			.replace(/ß/g, 'ss')
			.normalize('NFD')
			.replace(/[̀-ͯ]/g, '');
	}

	/**
	 * @param {Array<{code:string,label:string}>} options
	 * @param {string} query
	 * @returns {Array<{code:string,label:string}>}
	 */
	function filterOptions(options, query) {
		var q = fold(query).trim();
		var rows = Array.isArray(options) ? options : [];
		if (q === '') {
			return rows;
		}
		return rows.filter(function (o) {
			var hay = o._search || (o._search = fold(o.label) + ' ' + fold(o.code));
			return hay.indexOf(q) !== -1;
		});
	}

	function optionLabelOf(li) {
		var name = li.querySelector('.user-picker__name');
		return String((name ? name.textContent : li.textContent) || '').trim();
	}

	function readOptionsFromList(list) {
		if (!list || typeof list.querySelectorAll !== 'function') {
			return [];
		}
		var out = [];
		Array.prototype.forEach.call(list.querySelectorAll('li[data-code]'), function (li) {
			var code = String(li.getAttribute('data-code') || '').toUpperCase();
			if (code === '') {
				return;
			}
			out.push({ code: code, label: optionLabelOf(li), el: li });
		});
		return out;
	}

	var optionsPromise = null;
	/**
	 * Options for dynamically built pickers: reuse the complete set captured by
	 * the first mounted picker (its DOM list shrinks while filtering, so a DOM
	 * scrape could yield a subset); fall back to one GET /countries (memoized).
	 * @returns {Promise<Array<{code:string,label:string}>>}
	 */
	function ensureOptions() {
		if (optionsPromise) {
			return optionsPromise;
		}
		var mounted = global.document
			? global.document.querySelector('[data-crm-country-picker][data-crm-country-mounted]')
			: null;
		if (mounted && Array.isArray(mounted.__crmCountryOptions) && mounted.__crmCountryOptions.length > 0) {
			optionsPromise = Promise.resolve(mounted.__crmCountryOptions.map(function (o) {
				return { code: o.code, label: o.label };
			}));
			return optionsPromise;
		}
		var existing = global.document
			? global.document.querySelector('[data-crm-country-list]')
			: null;
		var scraped = readOptionsFromList(existing);
		if (scraped.length > 0) {
			optionsPromise = Promise.resolve(scraped);
			return optionsPromise;
		}
		var Api = global.CustomerCheckApi;
		if (!Api || typeof Api.get !== 'function') {
			optionsPromise = Promise.resolve([]);
			return optionsPromise;
		}
		optionsPromise = Api.get('/countries').then(function (res) {
			var items = res && res.data && Array.isArray(res.data.items) ? res.data.items : [];
			return items.map(function (it) {
				return {
					code: String(it && it.code || '').toUpperCase(),
					label: String(it && it.label || ''),
				};
			}).filter(function (it) { return it.code !== ''; });
		}).catch(function () {
			return [];
		});
		return optionsPromise;
	}

	function setExpanded(search, list, open) {
		list.hidden = !open;
		search.setAttribute('aria-expanded', open ? 'true' : 'false');
	}

	function buildOptionLi(baseId, opt, selectedCode) {
		var li = global.document.createElement('li');
		li.className = 'user-picker__item';
		li.setAttribute('role', 'option');
		li.id = baseId + '-opt-' + opt.code;
		li.setAttribute('data-code', opt.code);
		li.setAttribute('aria-selected', opt.code === selectedCode ? 'true' : 'false');
		var name = global.document.createElement('span');
		name.className = 'user-picker__name';
		name.textContent = opt.label;
		var code = global.document.createElement('span');
		code.className = 'crm-country-picker__code';
		code.setAttribute('aria-hidden', 'true');
		code.textContent = opt.code;
		li.appendChild(name);
		li.appendChild(code);
		return li;
	}

	/**
	 * Wire one picker root ([data-crm-country-picker]).
	 * @param {HTMLElement} root
	 */
	function mount(root) {
		if (!root || root.getAttribute('data-crm-country-mounted') === '1') {
			return;
		}
		var search = root.querySelector('[data-crm-country-search]');
		var hidden = root.querySelector('[data-crm-country-value]');
		var list = root.querySelector('[data-crm-country-list]');
		var clear = root.querySelector('[data-crm-country-clear]');
		if (!search || !hidden || !list) {
			return;
		}
		root.setAttribute('data-crm-country-mounted', '1');

		var options = readOptionsFromList(list);
		// Full set survives filtering — ensureOptions() hands it to dynamically
		// built pickers whose slot is mounted after this list was narrowed.
		root.__crmCountryOptions = options;
		var emptyEl = null;
		var committedLabel = String(search.value || '');
		var suppressFocusOpen = false;

		// ArrowDown/Home on a closed combobox opens the full list first; the
		// shared nav module (registered next) then marks the first option.
		search.addEventListener('keydown', function (ev) {
			if ((ev.key === 'ArrowDown' || ev.key === 'Home') && list.hidden) {
				ev.preventDefault();
				applyFilter('');
				setExpanded(search, list, true);
			}
		});
		var nav = (global.CustomerCheckUserPickerNav && typeof global.CustomerCheckUserPickerNav.bindListboxKeyboard === 'function')
			? global.CustomerCheckUserPickerNav.bindListboxKeyboard(search, list)
			: { reset: function () {} };

		/**
		 * The shared listbox nav walks every [role=option] in the DOM — filtered-
		 * out options must therefore be detached, not merely hidden, or arrows
		 * and Enter could land on an invisible country. Rebuilding from `options`
		 * each time also heals the nav module's Escape innerHTML wipe and keeps
		 * the ISO ordering intact.
		 */
		function applyFilter(query) {
			if (nav && typeof nav.reset === 'function') {
				nav.reset();
			}
			var q = fold(query).trim();
			var shown = [];
			options.forEach(function (o) {
				var hay = o._search || (o._search = fold(o.label) + ' ' + fold(o.code));
				if (q === '' || hay.indexOf(q) !== -1) {
					shown.push(o.el);
				}
			});
			while (list.firstChild) {
				list.removeChild(list.firstChild);
			}
			var frag = global.document.createDocumentFragment();
			shown.forEach(function (el) {
				frag.appendChild(el);
			});
			if (shown.length === 0) {
				if (!emptyEl) {
					emptyEl = global.document.createElement('li');
					emptyEl.className = 'user-picker__item user-picker__item--muted';
					emptyEl.setAttribute('role', 'presentation');
				}
				emptyEl.textContent = tr('No country matches.');
				frag.appendChild(emptyEl);
			}
			list.appendChild(frag);
		}

		function commit(code, label) {
			hidden.value = code;
			committedLabel = label;
			search.value = label;
			setExpanded(search, list, false);
			if (nav && typeof nav.reset === 'function') {
				nav.reset();
			}
		}

		function commitLi(li) {
			var code = String(li.getAttribute('data-code') || '').toUpperCase();
			if (code === '') {
				return;
			}
			commit(code, optionLabelOf(li));
		}

		function resolveFreeText() {
			var text = String(search.value || '').trim();
			if (text === '') {
				search.value = committedLabel;
				return;
			}
			var upper = text.toUpperCase();
			var folded = fold(text);
			for (var i = 0; i < options.length; i++) {
				var o = options[i];
				if (o.code === upper || fold(o.label) === folded) {
					commit(o.code, o.label);
					return;
				}
			}
			// Degraded mode (option fetch failed): still accept an explicit
			// ISO code — the server validates it on submit. Never a dead end.
			if (options.length === 0 && /^[A-Z]{2}$/.test(upper)) {
				commit(upper, upper);
				return;
			}
			// No match: keep the last committed value, restore its label.
			search.value = committedLabel;
		}

		list.addEventListener('mousedown', function (ev) {
			// Keep focus in the search input (combobox pattern) — commit via click.
			ev.preventDefault();
		});
		list.addEventListener('click', function (ev) {
			var li = ev.target && ev.target.closest ? ev.target.closest('li[data-code]') : null;
			if (li) {
				commitLi(li);
				if (typeof search.focus === 'function') {
					suppressFocusOpen = true;
					search.focus();
				}
			}
		});
		search.addEventListener('focus', function () {
			if (suppressFocusOpen) {
				suppressFocusOpen = false;
				return;
			}
			// When the input still shows the committed label it is a value, not a
			// query — open the full list so a change takes one click, not a delete.
			applyFilter(search.value === committedLabel ? '' : search.value);
			setExpanded(search, list, true);
		});
		search.addEventListener('input', function () {
			applyFilter(search.value);
			setExpanded(search, list, true);
		});
		root.addEventListener('focusout', function (ev) {
			if (ev.relatedTarget && root.contains(ev.relatedTarget)) {
				return;
			}
			resolveFreeText();
			setExpanded(search, list, false);
		});
		if (clear) {
			clear.hidden = false;
			clear.addEventListener('click', function () {
				hidden.value = '';
				committedLabel = '';
				search.value = '';
				applyFilter('');
				setExpanded(search, list, true);
				if (typeof search.focus === 'function') {
					search.focus();
				}
			});
		}
	}

	/** @param {ParentNode|HTMLElement} scope */
	function mountAll(scope) {
		var root = scope && scope.querySelectorAll ? scope : global.document;
		if (!root) {
			return;
		}
		Array.prototype.forEach.call(
			root.querySelectorAll('[data-crm-country-picker]:not([data-crm-country-mounted])'),
			mount,
		);
		if (root !== global.document && root.getAttribute
			&& root.getAttribute('data-crm-country-picker') !== null) {
			mount(root);
		}
	}

	function escId(raw) {
		return String(raw || 'crm-country').replace(/[^a-zA-Z0-9_-]/g, '');
	}

	/**
	 * Upgrade `<span data-crm-country-slot data-base data-field data-value>`
	 * placeholders into full pickers (used by client-rendered rows like the
	 * Assign "Fix country mapping" form).
	 * @param {ParentNode|HTMLElement} scope
	 * @returns {Promise<void>}
	 */
	function upgradeSlots(scope) {
		var root = scope && scope.querySelectorAll ? scope : global.document;
		if (!root) {
			return Promise.resolve();
		}
		var slots = root.querySelectorAll('[data-crm-country-slot]:not([data-crm-country-mounted])');
		if (!slots.length) {
			return Promise.resolve();
		}
		return ensureOptions().then(function (options) {
			Array.prototype.forEach.call(slots, function (slot) {
				if (!slot.isConnected) {
					return;
				}
				var base = escId(slot.getAttribute('data-base'));
				var field = String(slot.getAttribute('data-field') || 'country');
				var value = String(slot.getAttribute('data-value') || '').toUpperCase();
				var wrap = global.document.createElement('span');
				wrap.className = 'user-picker crm-country-picker';
				wrap.setAttribute('data-crm-country-picker', '');

				var control = global.document.createElement('span');
				control.className = 'user-picker__control';
				var search = global.document.createElement('input');
				search.type = 'search';
				search.id = base + '-search';
				search.className = 'form-input crm-input user-picker__search';
				search.setAttribute('role', 'combobox');
				search.setAttribute('aria-autocomplete', 'list');
				search.setAttribute('aria-expanded', 'false');
				search.setAttribute('aria-controls', base + '-list');
				search.setAttribute('autocomplete', 'off');
				search.setAttribute('spellcheck', 'false');
				search.setAttribute('data-field', field);
				search.setAttribute('data-crm-country-search', '');
				search.setAttribute('placeholder', String(slot.getAttribute('data-placeholder') || tr('Type to search countries')));
				control.appendChild(search);
				wrap.appendChild(control);

				var hidden = global.document.createElement('input');
				hidden.type = 'hidden';
				hidden.id = base;
				hidden.setAttribute('name', field);
				hidden.setAttribute('data-crm-country-value', '');
				wrap.appendChild(hidden);

				var list = global.document.createElement('ul');
				list.id = base + '-list';
				list.className = 'user-picker__list';
				list.setAttribute('role', 'listbox');
				list.setAttribute('aria-label', tr('Countries'));
				list.setAttribute('data-crm-country-list', '');
				list.hidden = true;
				var committedLabel = '';
				options.forEach(function (o) {
					if (o.code === value) {
						committedLabel = o.label;
					}
					list.appendChild(buildOptionLi(base, o, value));
				});
				if (value !== '' && committedLabel === '') {
					list.appendChild(buildOptionLi(base, { code: value, label: value }, value));
					committedLabel = value;
				}
				search.value = committedLabel;
				hidden.value = value;
				wrap.appendChild(list);

				slot.setAttribute('data-crm-country-mounted', '1');
				while (slot.firstChild) {
					slot.removeChild(slot.firstChild);
				}
				slot.appendChild(wrap);
				mount(wrap);
			});
		});
	}

	global.CustomerCheckCountryPicker = {
		mount: mount,
		mountAll: mountAll,
		upgradeSlots: upgradeSlots,
		ensureOptions: ensureOptions,
		filterOptions: filterOptions,
		fold: fold,
	};

	if (global.document && typeof global.document.addEventListener === 'function') {
		global.document.addEventListener('DOMContentLoaded', function () {
			mountAll(global.document);
		});
	}
}(typeof window !== 'undefined' ? window : globalThis));
