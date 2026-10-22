/**
 * Pure date/time selector helpers for CustomerCheck.
 * No DOM fetch — vitest + mutation friendly. Classic script for Util::addScript.
 *
 * Canonical wire format is ISO YYYY-MM-DD (matches PHP DateMath::parseStrictDate).
 * Display format is European dd.mm.yyyy. The calendar is the only writer.
 *
 * Red-team:
 * - Never parse ISO with the Date constructor on a YYYY-MM-DD string (UTC midnight → previous local day).
 * - Reject impossible dates (2026-02-30) the same way PHP does.
 * - Duration/hour helpers exist so time fields are selects, not free typing.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */
(function (global) {
  'use strict';

  /**
   * window.t / OC.L10N.translate fills {name} tokens only — %s/%d pass
   * through raw. Wrap an injected translator so leftover sprintf
   * placeholders are always filled (idempotent when already filled).
   * @param {unknown} base
   * @returns {(msg: string, args?: string[]) => string}
   */
  function sprintfT(base) {
    const baseT = typeof base === 'function' ? base : function (m) { return m; };
    return function (msg, args) {
      let out = baseT(msg, args);
      const list = Array.isArray(args) ? args : [];
      if (list.length && typeof out === 'string' && out.indexOf('%') !== -1) {
        let i = 0;
        out = out.replace(/%%|%(?:\d+\$)?[sd]/g, function (m) {
          if (m === '%%') { return '%'; }
          const v = list[i];
          i += 1;
          return v == null ? '' : String(v);
        });
      }
      return out;
    };
  }

  const ISO_RE = /^(\d{4})-(\d{2})-(\d{2})$/;
  const EU_RE = /^(\d{2})\.(\d{2})\.(\d{4})$/;
  const MIN_YEAR = 1900;
  const MAX_YEAR = 2200;
  const GRID_CELLS = 42;
  const DURATION_STEP = 15;
  const DURATION_MIN = 15;
  const DURATION_MAX = 480;
  const DURATION_MINUTES = Object.freeze([
    15, 30, 45, 60, 75, 90, 105, 120, 150, 180, 210, 240, 300, 360, 420, 480,
  ]);
  const HOUR_MIN = 0;
  const HOUR_MAX = 23;

  /**
   * @param {unknown} year
   * @param {unknown} month 1-12
   * @param {unknown} day
   * @returns {boolean}
   */
  function isRealYmd(year, month, day) {
    const y = typeof year === 'number' ? year : parseInt(String(year), 10);
    const m = typeof month === 'number' ? month : parseInt(String(month), 10);
    const d = typeof day === 'number' ? day : parseInt(String(day), 10);
    if (!Number.isFinite(y) || !Number.isFinite(m) || !Number.isFinite(d)) {
      return false;
    }
    if (y < MIN_YEAR || y > MAX_YEAR || m < 1 || m > 12 || d < 1 || d > 31) {
      return false;
    }
    const dt = new Date(y, m - 1, d);
    return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;
  }

  /**
   * @param {number} year
   * @param {number} month 1-12
   * @param {number} day
   * @returns {string}
   */
  function isoFromYmd(year, month, day) {
    if (!isRealYmd(year, month, day)) {
      return '';
    }
    const y = String(year);
    const m = String(month).padStart(2, '0');
    const d = String(day).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  /**
   * Local calendar YYYY-MM-DD — never toISOString (UTC day shift).
   *
   * @param {Date|null|undefined} date
   * @returns {string}
   */
  function isoFromLocalDate(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
      return '';
    }
    return isoFromYmd(date.getFullYear(), date.getMonth() + 1, date.getDate());
  }

  /**
   * @param {string} iso
   * @returns {Date|null} local midnight, or null
   */
  function localDateFromIso(iso) {
    const parts = parseIsoParts(iso);
    if (!parts) {
      return null;
    }
    return new Date(parts.year, parts.month - 1, parts.day);
  }

  /**
   * @param {unknown} value
   * @returns {{year:number,month:number,day:number}|null}
   */
  function parseIsoParts(value) {
    if (typeof value !== 'string') {
      return null;
    }
    const m = ISO_RE.exec(value.trim());
    if (!m) {
      return null;
    }
    const year = parseInt(m[1], 10);
    const month = parseInt(m[2], 10);
    const day = parseInt(m[3], 10);
    if (!isRealYmd(year, month, day)) {
      return null;
    }
    return { year: year, month: month, day: day };
  }

  /**
   * @param {unknown} value
   * @returns {{year:number,month:number,day:number}|null}
   */
  function parseEuropeanParts(value) {
    if (typeof value !== 'string') {
      return null;
    }
    const m = EU_RE.exec(value.trim());
    if (!m) {
      return null;
    }
    const day = parseInt(m[1], 10);
    const month = parseInt(m[2], 10);
    const year = parseInt(m[3], 10);
    if (!isRealYmd(year, month, day)) {
      return null;
    }
    return { year: year, month: month, day: day };
  }

  /**
   * @param {unknown} value
   * @returns {boolean}
   */
  function isIsoDate(value) {
    return parseIsoParts(value) !== null;
  }

  /**
   * @param {unknown} value
   * @returns {boolean}
   */
  function isEuropeanDate(value) {
    return parseEuropeanParts(value) !== null;
  }

  /**
   * Convert display or ISO to canonical ISO. Empty/invalid → ''.
   *
   * @param {unknown} value
   * @returns {string}
   */
  function toIso(value) {
    if (value == null) {
      return '';
    }
    const raw = String(value).trim();
    if (raw === '') {
      return '';
    }
    const iso = parseIsoParts(raw);
    if (iso) {
      return isoFromYmd(iso.year, iso.month, iso.day);
    }
    const eu = parseEuropeanParts(raw);
    if (eu) {
      return isoFromYmd(eu.year, eu.month, eu.day);
    }
    return '';
  }

  /**
   * @param {unknown} value
   * @returns {string} dd.mm.yyyy or ''
   */
  function toEuropean(value) {
    const iso = toIso(value);
    const parts = parseIsoParts(iso);
    if (!parts) {
      return '';
    }
    return String(parts.day).padStart(2, '0')
      + '.' + String(parts.month).padStart(2, '0')
      + '.' + String(parts.year);
  }

  /**
   * @param {unknown} a
   * @param {unknown} b
   * @returns {number} negative if a < b; 0 if equal or either invalid
   */
  function compareIso(a, b) {
    const left = toIso(a);
    const right = toIso(b);
    if (!left || !right) {
      return 0;
    }
    if (left === right) {
      return 0;
    }
    return left < right ? -1 : 1;
  }

  /**
   * @param {unknown} iso
   * @param {unknown} minIso
   * @param {unknown} maxIso
   * @returns {boolean}
   */
  function isIsoInRange(iso, minIso, maxIso) {
    const value = toIso(iso);
    if (!value) {
      return false;
    }
    const min = toIso(minIso);
    const max = toIso(maxIso);
    if (min && value < min) {
      return false;
    }
    if (max && value > max) {
      return false;
    }
    return true;
  }

  /**
   * Monday-first offset of a JS weekday (0=Sun).
   *
   * @param {number} jsDay
   * @returns {number} 0=Mon .. 6=Sun
   */
  function mondayFirstOffset(jsDay) {
    const n = Number(jsDay);
    if (!Number.isFinite(n)) {
      return 0;
    }
    return (n + 6) % 7;
  }

  /**
   * Local date for cell `index` (0..41) of the month grid.
   *
   * @param {number} viewYear
   * @param {number} viewMonth 1-12
   * @param {number} index
   * @returns {Date|null}
   */
  function gridCellDate(viewYear, viewMonth, index) {
    if (!isRealYmd(viewYear, viewMonth, 1)) {
      return null;
    }
    const i = typeof index === 'number' ? index : parseInt(String(index), 10);
    if (!Number.isFinite(i) || i < 0 || i >= GRID_CELLS) {
      return null;
    }
    const first = new Date(viewYear, viewMonth - 1, 1);
    const startDay = 1 - mondayFirstOffset(first.getDay());
    return new Date(viewYear, viewMonth - 1, startDay + i);
  }

  /**
   * @param {number} viewYear
   * @param {number} viewMonth 1-12
   * @param {{selectedIso?:string, todayIso?:string, minIso?:string, maxIso?:string}} [opts]
   * @returns {list<{iso:string, day:number, inMonth:boolean, isToday:boolean, isSelected:boolean, disabled:boolean}>}
   */
  function buildCalendarCells(viewYear, viewMonth, opts) {
    const options = opts && typeof opts === 'object' ? opts : {};
    const selected = toIso(options.selectedIso);
    const today = toIso(options.todayIso);
    const minIso = toIso(options.minIso);
    const maxIso = toIso(options.maxIso);
    const cells = [];
    for (let i = 0; i < GRID_CELLS; i += 1) {
      const date = gridCellDate(viewYear, viewMonth, i);
      if (!date) {
        continue;
      }
      const iso = isoFromLocalDate(date);
      const inMonth = date.getMonth() === viewMonth - 1;
      const disabled = !iso || (minIso && iso < minIso) || (maxIso && iso > maxIso);
      cells.push({
        iso: iso,
        day: date.getDate(),
        inMonth: inMonth,
        isToday: !!today && iso === today,
        isSelected: !!selected && iso === selected,
        disabled: !!disabled,
      });
    }
    return cells;
  }

  /**
   * @param {unknown} year
   * @param {unknown} month 1-12
   * @param {number} deltaMonths
   * @returns {{year:number, month:number}|null}
   */
  function shiftMonth(year, month, deltaMonths) {
    const y = typeof year === 'number' ? year : parseInt(String(year), 10);
    const m = typeof month === 'number' ? month : parseInt(String(month), 10);
    const d = typeof deltaMonths === 'number' ? deltaMonths : parseInt(String(deltaMonths), 10);
    if (!Number.isFinite(y) || !Number.isFinite(m) || !Number.isFinite(d)) {
      return null;
    }
    const dt = new Date(y, m - 1 + d, 1);
    const nextY = dt.getFullYear();
    const nextM = dt.getMonth() + 1;
    if (nextY < MIN_YEAR || nextY > MAX_YEAR) {
      return { year: y, month: m };
    }
    return { year: nextY, month: nextM };
  }

  /**
   * @param {unknown} minutes
   * @returns {number}
   */
  function snapDurationMinutes(minutes) {
    const n = typeof minutes === 'number' ? minutes : parseInt(String(minutes), 10);
    if (!Number.isFinite(n) || n < DURATION_MIN) {
      return DURATION_MIN;
    }
    const snapped = Math.round(n / DURATION_STEP) * DURATION_STEP;
    if (snapped < DURATION_MIN) {
      return DURATION_MIN;
    }
    if (snapped > DURATION_MAX) {
      return DURATION_MAX;
    }
    return snapped;
  }

  /**
   * @param {unknown} minutes
   * @returns {boolean}
   */
  function isAllowedDuration(minutes) {
    const n = typeof minutes === 'number' ? minutes : parseInt(String(minutes), 10);
    if (!Number.isFinite(n) || n < DURATION_MIN || n > DURATION_MAX) {
      return false;
    }
    return n % DURATION_STEP === 0;
  }

  /**
   * @param {unknown} minutes
   * @param {(msg:string, args?:string[]) => string} [translate]
   * @returns {string}
   */
  function formatDurationLabel(minutes, translate) {
    const t = sprintfT(translate);
    const n = snapDurationMinutes(minutes);
    const h = Math.floor(n / 60);
    const m = n % 60;
    if (h === 0) {
      return t('%s minutes', [String(n)]);
    }
    if (m === 0) {
      if (h === 1) {
        return t('1 hour');
      }
      return t('%s hours', [String(h)]);
    }
    if (h === 1) {
      return t('1 hour %s minutes', [String(m)]);
    }
    return t('%s hours %s minutes', [String(h), String(m)]);
  }

  /**
   * @param {unknown} hour
   * @returns {number} 0-23, default 0 when invalid
   */
  function clampHour(hour) {
    const n = typeof hour === 'number' ? hour : parseInt(String(hour), 10);
    if (!Number.isFinite(n)) {
      return HOUR_MIN;
    }
    if (n < HOUR_MIN) {
      return HOUR_MIN;
    }
    if (n > HOUR_MAX) {
      return HOUR_MAX;
    }
    return n;
  }

  /**
   * @param {unknown} hour
   * @returns {string} HH:00
   */
  function formatHourLabel(hour) {
    return String(clampHour(hour)).padStart(2, '0') + ':00';
  }

  /**
   * @returns {number[]}
   */
  function hourValues() {
    const out = [];
    for (let h = HOUR_MIN; h <= HOUR_MAX; h += 1) {
      out.push(h);
    }
    return out;
  }

  /**
   * form.elements[name] can be an Element, RadioNodeList, or undefined.
   * RadioNodeList has length/item but no tagName and no closest().
   *
   * @param {unknown} el
   * @returns {boolean}
   */
  function isNamedControlList(el) {
    return !!(
      el
      && typeof el === 'object'
      && typeof el.tagName !== 'string'
      && typeof el.length === 'number'
      && el.length > 0
    );
  }

  /**
   * Read canonical ISO from an enhanced field (hidden sibling) or a raw value.
   *
   * @param {{value?:unknown, closest?:Function, length?:number, item?:Function}|null|undefined} el
   * @returns {string}
   */
  function isoValue(el) {
    if (!el) {
      return '';
    }
    if (isNamedControlList(el)) {
      let found = '';
      const len = el.length;
      for (let i = 0; i < len; i += 1) {
        const item = (typeof el.item === 'function') ? el.item(i) : el[i];
        const iso = isoValue(item);
        if (iso) {
          found = iso;
        }
      }
      return found;
    }
    if (typeof el.closest === 'function') {
      const host = el.closest('.crm-datepicker-host');
      if (host && typeof host.querySelector === 'function') {
        const hidden = host.querySelector('input[data-crm-date-iso]');
        const fromHidden = hidden && hidden.value != null ? toIso(hidden.value) : '';
        if (fromHidden) {
          return fromHidden;
        }
        const display = host.querySelector('input.datepicker-input');
        const fromDisplay = display && display.value != null ? toIso(display.value) : '';
        if (fromDisplay) {
          return fromDisplay;
        }
      }
    }
    return toIso(el.value);
  }

  /**
   * Write ISO to hidden + European display. Safe no-op without a host.
   *
   * @param {{value?:string, closest?:Function}|null|undefined} el
   * @param {unknown} value
   */
  function setIsoValue(el, value) {
    if (!el) {
      return;
    }
    const iso = toIso(value);
    const european = toEuropean(iso);
    if (typeof el.closest === 'function') {
      const host = el.closest('.crm-datepicker-host');
      if (host && typeof host.querySelector === 'function') {
        const hidden = host.querySelector('input[data-crm-date-iso]');
        if (hidden) {
          hidden.value = iso;
        }
      }
    }
    el.value = european || iso;
  }

  /**
   * Parse data-datepicker-min / max: 'today', ISO, or European.
   *
   * @param {unknown} raw
   * @param {string} [todayIso]
   * @returns {string} ISO or ''
   */
  function parseBoundAttr(raw, todayIso) {
    if (raw == null) {
      return '';
    }
    const s = String(raw).trim();
    if (s === '') {
      return '';
    }
    if (s === 'today') {
      return toIso(todayIso) || isoFromLocalDate(new Date());
    }
    return toIso(s);
  }

  /**
   * Open native <dialog> that owns the date field (modal top-layer host).
   *
   * Red-team: body-mounted calendars paint *under* showModal() dialogs —
   * z-index cannot beat the top layer. Callers must mount into the top layer
   * (Popover API) or inside this dialog as a fallback.
   *
   * @param {{closest?:Function}|null|undefined} el
   * @returns {HTMLElement|null}
   */
  function findOpenDialogHost(el) {
    if (!el || typeof el.closest !== 'function') {
      return null;
    }
    const dialog = el.closest('dialog');
    if (!dialog) {
      return null;
    }
    if (dialog.open === true || (typeof dialog.hasAttribute === 'function' && dialog.hasAttribute('open'))) {
      return dialog;
    }
    return null;
  }

  /**
   * Whether the runtime can put the calendar into the top layer via Popover.
   *
   * @param {unknown} [proto]
   * @returns {boolean}
   */
  function supportsCalendarPopover(proto) {
    const p = proto != null
      ? proto
      : (typeof HTMLElement !== 'undefined' ? HTMLElement.prototype : null);
    return !!(p && typeof p.showPopover === 'function' && typeof p.hidePopover === 'function');
  }

  /**
   * Mount strategy for the calendar popup.
   *
   * - dialog-host: append inside the open dialog (same top-layer entry as showModal)
   * - popover: top layer for page-level fields (escapes overflow:hidden ancestors)
   * - body: last-resort page mount without Popover
   *
   * Red-team: Popover-above-dialog is NOT reliable across Chromium builds —
   * :popover-open can be true while elementFromPoint still hits the modal.
   * Sharing the dialog's top-layer entry is the foolproof modal path.
   *
   * @param {{supportsPopover?:boolean, insideOpenDialog?:boolean}} [opts]
   * @returns {'popover'|'dialog-host'|'body'}
   */
  function resolveCalendarMountMode(opts) {
    const options = opts && typeof opts === 'object' ? opts : {};
    if (options.insideOpenDialog) {
      return 'dialog-host';
    }
    if (options.supportsPopover) {
      return 'popover';
    }
    return 'body';
  }

  const api = {
    ISO_RE: ISO_RE,
    EU_RE: EU_RE,
    MIN_YEAR: MIN_YEAR,
    MAX_YEAR: MAX_YEAR,
    GRID_CELLS: GRID_CELLS,
    DURATION_STEP: DURATION_STEP,
    DURATION_MIN: DURATION_MIN,
    DURATION_MAX: DURATION_MAX,
    DURATION_MINUTES: DURATION_MINUTES,
    HOUR_MIN: HOUR_MIN,
    HOUR_MAX: HOUR_MAX,
    isRealYmd: isRealYmd,
    isoFromYmd: isoFromYmd,
    isoFromLocalDate: isoFromLocalDate,
    localDateFromIso: localDateFromIso,
    parseIsoParts: parseIsoParts,
    parseEuropeanParts: parseEuropeanParts,
    isIsoDate: isIsoDate,
    isEuropeanDate: isEuropeanDate,
    toIso: toIso,
    toEuropean: toEuropean,
    convertEuropeanToISO: toIso,
    convertISOToEuropean: toEuropean,
    compareIso: compareIso,
    isIsoInRange: isIsoInRange,
    mondayFirstOffset: mondayFirstOffset,
    gridCellDate: gridCellDate,
    buildCalendarCells: buildCalendarCells,
    shiftMonth: shiftMonth,
    snapDurationMinutes: snapDurationMinutes,
    isAllowedDuration: isAllowedDuration,
    formatDurationLabel: formatDurationLabel,
    clampHour: clampHour,
    formatHourLabel: formatHourLabel,
    hourValues: hourValues,
    isoValue: isoValue,
    setIsoValue: setIsoValue,
    parseBoundAttr: parseBoundAttr,
    findOpenDialogHost: findOpenDialogHost,
    supportsCalendarPopover: supportsCalendarPopover,
    resolveCalendarMountMode: resolveCalendarMountMode,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckDatepickerCore = api;
})(typeof window !== 'undefined' ? window : globalThis);
