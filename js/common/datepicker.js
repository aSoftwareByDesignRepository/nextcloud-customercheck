/**
 * CustomerCheck date selector — calendar is the only writer.
 *
 * Progressive enhancement: native type="date" still works without JS.
 * With JS, we wrap the field, show dd.mm.yyyy, and keep ISO in a hidden input
 * so existing form.elements[name] / FormData paths stay YYYY-MM-DD.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function () {
  'use strict';

  const Core = window.CustomerCheckDatepickerCore;
  if (!Core) {
    return;
  }

  const HOST_CLASS = 'crm-datepicker-host';
  const TOGGLE_CLASS = 'crm-datepicker-toggle';
  const CALENDAR_CLASS = 'crm-datepicker';
  const CALENDAR_ID = 'crm-datepicker-popup';
  const DATE_SELECTOR = 'input[type="date"], input.datepicker-input';

  const CALENDAR_SVG = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="lucide-icon crm-datepicker-toggle__icon" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';

  /** @type {null|{close: Function, element: HTMLElement}} */
  let activeCalendar = null;
  /** @type {MutationObserver|null} */
  let observer = null;

  const t = (msg, args) => {
    const list = Array.isArray(args) ? args : [];
    let out = window.t ? window.t('customercheck', msg, list) : msg;
    if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
      let i = 0;
      out = out.replace(/%%|%(?:\d+\$)?s/g, function (m) {
        if (m === '%%') { return '%'; }
        const v = list[i];
        i += 1;
        return v == null ? '' : String(v);
      });
    }
    return out;
  };

  function localeTag() {
    try {
      if (typeof OC !== 'undefined' && typeof OC.getLocale === 'function') {
        const loc = OC.getLocale();
        if (loc) {
          return String(loc).replace(/_/g, '-');
        }
      }
    } catch (_) { /* ignore */ }
    const htmlLang = document.documentElement && document.documentElement.lang;
    return htmlLang || (typeof navigator !== 'undefined' && navigator.language) || 'en';
  }

  function monthYearLabel(year, month) {
    const d = new Date(year, month - 1, 1);
    try {
      return new Intl.DateTimeFormat(localeTag(), { month: 'long', year: 'numeric' }).format(d);
    } catch (_) {
      return String(month).padStart(2, '0') + ' ' + String(year);
    }
  }

  function weekdayLabels() {
    const labels = [];
    try {
      const fmt = new Intl.DateTimeFormat(localeTag(), { weekday: 'short' });
      for (let i = 0; i < 7; i += 1) {
        labels.push(fmt.format(new Date(2024, 0, 1 + i)));
      }
    } catch (_) {
      return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    }
    return labels;
  }

  function longDayLabel(iso) {
    const date = Core.localDateFromIso(iso);
    if (!date) {
      return iso;
    }
    try {
      return new Intl.DateTimeFormat(localeTag(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }).format(date);
    } catch (_) {
      return Core.toEuropean(iso) || iso;
    }
  }

  function todayIso() {
    return Core.isoFromLocalDate(new Date());
  }

  function fieldLocked(el) {
    return !!(el.disabled || el.getAttribute('data-datepicker-locked') === '1');
  }

  function boundIso(el, attr) {
    const raw = el.getAttribute(attr);
    if (raw) {
      return Core.parseBoundAttr(raw, todayIso());
    }
    const native = attr === 'data-datepicker-min' ? el.getAttribute('min') : el.getAttribute('max');
    return Core.parseBoundAttr(native, todayIso());
  }

  function dynamicMinIso(el) {
    const otherId = el.getAttribute('data-datepicker-min-from');
    if (!otherId) {
      return boundIso(el, 'data-datepicker-min');
    }
    const other = document.getElementById(otherId);
    const fromIso = isoValue(other);
    const staticMin = boundIso(el, 'data-datepicker-min');
    if (fromIso && staticMin) {
      return fromIso > staticMin ? fromIso : staticMin;
    }
    return fromIso || staticMin;
  }

  function dynamicMaxIso(el) {
    const otherId = el.getAttribute('data-datepicker-max-from');
    if (!otherId) {
      return boundIso(el, 'data-datepicker-max');
    }
    const other = document.getElementById(otherId);
    const toIso = isoValue(other);
    const staticMax = boundIso(el, 'data-datepicker-max');
    if (toIso && staticMax) {
      return toIso < staticMax ? toIso : staticMax;
    }
    return toIso || staticMax;
  }

  function initialMonth(el, selectedIso) {
    if (selectedIso) {
      const parts = Core.parseIsoParts(selectedIso);
      if (parts) {
        return { year: parts.year, month: parts.month };
      }
    }
    const syncId = el.getAttribute('data-datepicker-sync-month-with');
    if (syncId) {
      const other = document.getElementById(syncId);
      const otherIso = isoValue(other);
      const parts = Core.parseIsoParts(otherIso);
      if (parts) {
        return { year: parts.year, month: parts.month };
      }
    }
    const now = new Date();
    return { year: now.getFullYear(), month: now.getMonth() + 1 };
  }

  function closeCalendar() {
    if (!activeCalendar) {
      return;
    }
    const pack = activeCalendar;
    activeCalendar = null;
    if (pack.onDialogClose && pack.dialogHost) {
      pack.dialogHost.removeEventListener('close', pack.onDialogClose);
    }
    if (pack.mountMode === 'popover' && pack.element && typeof pack.element.hidePopover === 'function') {
      try {
        if (typeof pack.element.matches === 'function' && pack.element.matches(':popover-open')) {
          pack.element.hidePopover();
        }
      } catch (_) { /* already closed / unsupported selector */ }
    }
    if (pack.mountMode === 'dialog-host' && pack.dialogHost && pack.overflowBackup != null) {
      pack.dialogHost.style.overflow = pack.overflowBackup;
    }
    if (pack.element && pack.element.parentNode) {
      pack.element.parentNode.removeChild(pack.element);
    }
    if (pack.onKey) {
      document.removeEventListener('keydown', pack.onKey, true);
    }
    if (pack.onPointer) {
      document.removeEventListener('pointerdown', pack.onPointer, true);
    }
    if (pack.toggle) {
      pack.toggle.setAttribute('aria-expanded', 'false');
      pack.toggle.removeAttribute('aria-controls');
    }
    if (pack.input) {
      pack.input.setAttribute('aria-expanded', 'false');
      pack.input.removeAttribute('aria-controls');
    }
    if (pack.restoreFocus && pack.input && typeof pack.input.focus === 'function') {
      pack.input.focus();
    }
  }

  /**
   * Mount calendar into the open dialog's top-layer entry, or Popover on the page.
   * Body + z-index alone loses to showModal() — auditor-known failure mode.
   *
   * @param {HTMLElement} container
   * @param {HTMLElement} anchor
   * @returns {{mountMode:string, dialogHost:HTMLElement|null, overflowBackup:string|null}}
   */
  function mountCalendarPopup(container, anchor) {
    const dialogHost = Core.findOpenDialogHost(anchor);
    const mountMode = Core.resolveCalendarMountMode({
      supportsPopover: Core.supportsCalendarPopover(),
      insideOpenDialog: !!dialogHost,
    });

    if (mountMode === 'dialog-host' && dialogHost) {
      // Dialogs use overflow:hidden (rounded clip). Temporarily allow the
      // fixed calendar to paint outside the panel while staying in-dialog
      // (required for UA focus trap + top-layer stacking with showModal).
      const overflowBackup = dialogHost.style.overflow;
      dialogHost.style.overflow = 'visible';
      dialogHost.appendChild(container);
      return { mountMode: mountMode, dialogHost: dialogHost, overflowBackup: overflowBackup };
    }

    if (mountMode === 'popover') {
      container.setAttribute('popover', 'manual');
      document.body.appendChild(container);
      try {
        container.showPopover();
      } catch (_) {
        /* rare UA quirk — still fixed + high z-index as last resort */
      }
      return { mountMode: mountMode, dialogHost: null, overflowBackup: null };
    }

    document.body.appendChild(container);
    return { mountMode: 'body', dialogHost: null, overflowBackup: null };
  }

  function positionCalendar(container, anchor) {
    const rect = anchor.getBoundingClientRect();
    const calHeight = container.offsetHeight || 320;
    const calWidth = container.offsetWidth || 280;
    const headerRaw = getComputedStyle(document.body).getPropertyValue('--header-height').trim();
    const headerOffset = Number.isFinite(parseFloat(headerRaw)) && parseFloat(headerRaw) > 0
      ? parseFloat(headerRaw)
      : 50;
    const minTop = headerOffset + 8;
    const spaceBelow = window.innerHeight - rect.bottom;
    const showAbove = spaceBelow < calHeight && rect.top > calHeight;
    let top;
    if (showAbove) {
      top = Math.max(minTop, rect.top - calHeight - 4);
    } else {
      top = Math.min(window.innerHeight - calHeight - 8, rect.bottom + 4);
      if (top < minTop) {
        top = minTop;
      }
    }
    let left = rect.left;
    if (left + calWidth > window.innerWidth - 8) {
      left = window.innerWidth - calWidth - 8;
    }
    if (left < 8) {
      left = 8;
    }
    container.style.top = top + 'px';
    container.style.left = left + 'px';
  }

  function renderCalendar(state) {
    const container = state.element;
    const monthLabel = container.querySelector('.crm-datepicker__month');
    const grid = container.querySelector('.crm-datepicker__grid');
    if (!monthLabel || !grid) {
      return;
    }
    monthLabel.textContent = monthYearLabel(state.year, state.month);
    grid.innerHTML = '';

    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    weekdayLabels().forEach((label) => {
      const th = document.createElement('th');
      th.scope = 'col';
      th.className = 'crm-datepicker__dow';
      th.textContent = label;
      headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    grid.appendChild(thead);

    const cells = Core.buildCalendarCells(state.year, state.month, {
      selectedIso: state.selectedIso,
      todayIso: todayIso(),
      minIso: state.minIso,
      maxIso: state.maxIso,
    });
    const tbody = document.createElement('tbody');
    let focusTarget = null;
    for (let i = 0; i < cells.length; i += 7) {
      const tr = document.createElement('tr');
      cells.slice(i, i + 7).forEach((cell) => {
        const td = document.createElement('td');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'crm-datepicker__day';
        btn.tabIndex = -1;
        btn.textContent = String(cell.day);
        btn.dataset.iso = cell.iso;
        btn.setAttribute('aria-label', longDayLabel(cell.iso));
        if (!cell.inMonth) {
          btn.classList.add('crm-datepicker__day--outside');
        }
        if (cell.isToday) {
          btn.classList.add('crm-datepicker__day--today');
          btn.setAttribute('aria-current', 'date');
        }
        if (cell.isSelected) {
          btn.classList.add('crm-datepicker__day--selected');
          btn.setAttribute('aria-pressed', 'true');
          focusTarget = btn;
        }
        if (cell.disabled) {
          btn.disabled = true;
          btn.setAttribute('aria-disabled', 'true');
        } else {
          btn.addEventListener('click', function () {
            applyIso(state.input, cell.iso);
            state.restoreFocus = true;
            closeCalendar();
          });
        }
        if (!focusTarget && cell.inMonth && !cell.disabled) {
          focusTarget = btn;
        }
        td.appendChild(btn);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    }
    grid.appendChild(tbody);
    state.focusDay = function (iso) {
      const days = grid.querySelectorAll('button.crm-datepicker__day');
      let match = null;
      days.forEach(function (btn) {
        btn.tabIndex = -1;
        if (btn.getAttribute('data-iso') === iso) {
          match = btn;
        }
      });
      if (match && typeof match.focus === 'function' && !match.disabled) {
        match.tabIndex = 0;
        match.focus();
        return true;
      }
      return false;
    };
    if (focusTarget && typeof focusTarget.focus === 'function') {
      focusTarget.tabIndex = 0;
      focusTarget.focus();
    }
  }

  function moveFocus(state, deltaDays) {
    const active = document.activeElement;
    const currentIso = (active && active.dataset && active.dataset.iso) || state.selectedIso || todayIso();
    const date = Core.localDateFromIso(currentIso);
    if (!date) {
      return;
    }
    date.setDate(date.getDate() + deltaDays);
    const nextIso = Core.isoFromLocalDate(date);
    if (!nextIso) {
      return;
    }
    if (date.getMonth() + 1 !== state.month || date.getFullYear() !== state.year) {
      const shifted = Core.shiftMonth(state.year, state.month, date.getMonth() + 1 === state.month ? 0 : (date < new Date(state.year, state.month - 1, 1) ? -1 : 1));
      if (shifted) {
        state.year = date.getFullYear();
        state.month = date.getMonth() + 1;
        renderCalendar(state);
      }
    }
    state.focusDay(nextIso);
  }

  function openCalendar(input, toggle) {
    if (fieldLocked(input)) {
      return;
    }
    if (activeCalendar && activeCalendar.input === input) {
      return;
    }
    closeCalendar();

    const selectedIso = isoValue(input);
    const view = initialMonth(input, selectedIso);
    const container = document.createElement('div');
    container.className = CALENDAR_CLASS;
    container.id = CALENDAR_ID;
    container.setAttribute('role', 'dialog');
    container.setAttribute('aria-modal', 'true');
    container.setAttribute('aria-label', t('Choose a date'));

    const header = document.createElement('div');
    header.className = 'crm-datepicker__header';

    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'crm-datepicker__nav';
    prevBtn.setAttribute('aria-label', t('Previous month'));
    prevBtn.textContent = '‹';

    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'crm-datepicker__nav';
    nextBtn.setAttribute('aria-label', t('Next month'));
    nextBtn.textContent = '›';

    const monthLabel = document.createElement('div');
    monthLabel.className = 'crm-datepicker__month';
    monthLabel.setAttribute('aria-live', 'polite');

    const grid = document.createElement('table');
    grid.className = 'crm-datepicker__grid';

    header.appendChild(prevBtn);
    header.appendChild(monthLabel);
    header.appendChild(nextBtn);
    container.appendChild(header);
    container.appendChild(grid);

    const mount = mountCalendarPopup(container, input);

    const state = {
      element: container,
      input: input,
      toggle: toggle,
      year: view.year,
      month: view.month,
      selectedIso: selectedIso,
      minIso: dynamicMinIso(input),
      maxIso: dynamicMaxIso(input),
      restoreFocus: false,
      onKey: null,
      onPointer: null,
      onDialogClose: null,
      mountMode: mount.mountMode,
      dialogHost: mount.dialogHost,
      overflowBackup: mount.overflowBackup,
      focusDay: function () { return false; },
    };

    if (mount.dialogHost) {
      state.onDialogClose = function () {
        closeCalendar();
      };
      mount.dialogHost.addEventListener('close', state.onDialogClose);
    }

    prevBtn.addEventListener('click', function () {
      const next = Core.shiftMonth(state.year, state.month, -1);
      if (!next) {
        return;
      }
      state.year = next.year;
      state.month = next.month;
      renderCalendar(state);
    });
    nextBtn.addEventListener('click', function () {
      const next = Core.shiftMonth(state.year, state.month, 1);
      if (!next) {
        return;
      }
      state.year = next.year;
      state.month = next.month;
      renderCalendar(state);
    });

    state.onKey = function (e) {
      if (!activeCalendar || activeCalendar.element !== container) {
        return;
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
          e.stopImmediatePropagation();
        }
        state.restoreFocus = true;
        closeCalendar();
        return;
      }
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        moveFocus(state, -1);
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        moveFocus(state, 1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveFocus(state, -7);
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        moveFocus(state, 7);
      } else if (e.key === 'PageUp') {
        e.preventDefault();
        prevBtn.click();
      } else if (e.key === 'PageDown') {
        e.preventDefault();
        nextBtn.click();
      } else if (e.key === 'Home') {
        e.preventDefault();
        moveFocus(state, -((new Date(state.year, state.month - 1, 1).getDay() + 6) % 7));
      } else if (e.key === 'End') {
        e.preventDefault();
        moveFocus(state, 6);
      } else if (e.key === 'Tab') {
        const focusable = [];
        container.querySelectorAll('button:not([disabled])').forEach(function (btn) {
          if (btn.tabIndex >= 0) {
            focusable.push(btn);
          }
        });
        if (!focusable.length) {
          return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener('keydown', state.onKey, true);

    state.onPointer = function (e) {
      if (!activeCalendar || activeCalendar.element !== container) {
        return;
      }
      const target = e.target;
      if (!(target instanceof Node)) {
        return;
      }
      if (container.contains(target)) {
        return;
      }
      const host = input.closest('.' + HOST_CLASS);
      if (host && host.contains(target)) {
        return;
      }
      closeCalendar();
    };
    document.addEventListener('pointerdown', state.onPointer, true);

    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-controls', CALENDAR_ID);
    }
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-controls', CALENDAR_ID);

    activeCalendar = state;
    renderCalendar(state);
    positionCalendar(container, input);
  }

  function hiddenFor(input) {
    const host = input.closest('.' + HOST_CLASS);
    if (!host) {
      return null;
    }
    return host.querySelector('input[data-crm-date-iso]');
  }

  function applyIso(input, value) {
    const iso = Core.toIso(value);
    const european = Core.toEuropean(iso);
    const hidden = hiddenFor(input);
    if (hidden) {
      hidden.value = iso;
    }
    input.value = european;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function isoValue(el) {
    return Core.isoValue(el);
  }

  function setIsoValue(el, value) {
    if (!el) {
      return;
    }
    applyIso(el, value);
  }

  function bindFormDateGuard(form) {
    if (!form || form.getAttribute('data-crm-date-guard') === '1') {
      return;
    }
    form.setAttribute('data-crm-date-guard', '1');
    form.addEventListener('submit', function (e) {
      const hosts = form.querySelectorAll('.' + HOST_CLASS);
      for (let i = 0; i < hosts.length; i += 1) {
        const host = hosts[i];
        const display = host.querySelector('input.datepicker-input');
        if (display && typeof display.setCustomValidity === 'function') {
          display.setCustomValidity('');
        }
        if (host.getAttribute('data-datepicker-required') !== '1') {
          continue;
        }
        const iso = isoValue(display || host.querySelector('input[data-crm-date-iso]'));
        if (!iso) {
          e.preventDefault();
          e.stopImmediatePropagation();
          if (display && typeof display.setCustomValidity === 'function') {
            display.setCustomValidity(t('Choose a date'));
            if (typeof display.reportValidity === 'function') {
              display.reportValidity();
            }
          }
          return;
        }
      }
    }, true);
  }

  function wrapInput(input) {
    if (!input || input.dataset.datepickerInit === '1') {
      return;
    }
    if (input.closest('.' + HOST_CLASS)) {
      input.dataset.datepickerInit = '1';
      return;
    }
    if (input.getAttribute('data-datepicker-skip') === '1') {
      return;
    }
    input.dataset.datepickerInit = '1';

    const iso = Core.toIso(input.value);
    const originalName = input.getAttribute('name') || '';
    const required = input.required || input.hasAttribute('required');
    const locked = !!(input.disabled || input.readOnly || input.hasAttribute('readonly'));
    const parentEl = input.parentElement;
    // --flex only when the host is a direct child of a grid/flex row (not nested in form-group).
    const inFlex = !!(parentEl && (
      parentEl.classList.contains('crm-date-range__part')
      || parentEl.classList.contains('crm-form-row-2')
    ));

    // type=date + readonly + leftover badInput blocks submit with no tooltip.
    try {
      input.type = 'text';
    } catch (err) {
      input.setAttribute('type', 'text');
    }
    input.removeAttribute('min');
    input.removeAttribute('max');
    input.removeAttribute('step');
    input.removeAttribute('pattern');
    input.removeAttribute('required');
    input.required = false;
    if (typeof input.setCustomValidity === 'function') {
      input.setCustomValidity('');
    }
    input.classList.add('datepicker-input', 'crm-input', 'form-input');
    input.setAttribute('readonly', 'readonly');
    input.readOnly = true;
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('inputmode', 'none');
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('aria-haspopup', 'dialog');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'none');
    if (!input.getAttribute('placeholder')) {
      input.setAttribute('placeholder', t('dd.mm.yyyy'));
    }
    if (locked) {
      input.setAttribute('data-datepicker-locked', '1');
    }
    input.value = Core.toEuropean(iso);

    const host = document.createElement('div');
    host.className = HOST_CLASS + (inFlex ? ' ' + HOST_CLASS + '--flex' : '');
    input.parentNode.insertBefore(host, input);
    host.appendChild(input);

    if (originalName) {
      input.removeAttribute('name');
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = originalName;
      hidden.value = iso;
      hidden.setAttribute('data-crm-date-iso', '1');
      hidden.setAttribute('autocomplete', 'off');
      host.appendChild(hidden);
    }
    if (required) {
      host.setAttribute('data-datepicker-required', '1');
    }

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = TOGGLE_CLASS;
    toggle.setAttribute('aria-label', t('Open calendar'));
    toggle.setAttribute('aria-haspopup', 'dialog');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.innerHTML = CALENDAR_SVG;
    if (locked) {
      toggle.disabled = true;
    }
    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (toggle.disabled || fieldLocked(input)) {
        return;
      }
      openCalendar(input, toggle);
    });
    host.appendChild(toggle);

    if (input.form) {
      bindFormDateGuard(input.form);
    }

    input.addEventListener('click', function (e) {
      e.preventDefault();
      if (!fieldLocked(input)) {
        openCalendar(input, toggle);
      }
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeCalendar();
        return;
      }
      if (e.key === 'Tab') {
        return;
      }
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' || e.key === 'ArrowDown') {
        e.preventDefault();
        if (!fieldLocked(input)) {
          openCalendar(input, toggle);
        }
        return;
      }
      e.preventDefault();
      if (!fieldLocked(input)) {
        openCalendar(input, toggle);
      }
    });
    input.addEventListener('paste', function (e) {
      e.preventDefault();
    });
  }

  function initInRoot(root) {
    const scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll(DATE_SELECTOR).forEach(function (el) {
      wrapInput(el);
    });
  }

  function initAll() {
    initInRoot(document);
    if (observer || typeof MutationObserver !== 'function') {
      return;
    }
    observer = new MutationObserver(function (records) {
      for (let i = 0; i < records.length; i += 1) {
        const rec = records[i];
        if (rec.type !== 'childList') {
          continue;
        }
        rec.addedNodes.forEach(function (node) {
          if (!node || node.nodeType !== 1) {
            return;
          }
          const el = /** @type {Element} */ (node);
          if (el.matches && el.matches(DATE_SELECTOR)) {
            wrapInput(el);
          }
          if (el.querySelectorAll) {
            initInRoot(el);
          }
        });
      }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  const api = {
    initializeDatepicker: wrapInput,
    initInRoot: initInRoot,
    isoValue: isoValue,
    setIsoValue: setIsoValue,
    toIso: Core.toIso,
    toEuropean: Core.toEuropean,
    convertEuropeanToISO: Core.toIso,
    convertISOToEuropean: Core.toEuropean,
    DURATION_MINUTES: Core.DURATION_MINUTES,
    formatDurationLabel: function (minutes) {
      return Core.formatDurationLabel(minutes, t);
    },
    snapDurationMinutes: Core.snapDurationMinutes,
    formatHourLabel: Core.formatHourLabel,
    hourValues: Core.hourValues,
    close: closeCalendar,
  };

  window.CustomerCheckDatepicker = api;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
