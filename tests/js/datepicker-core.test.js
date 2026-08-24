/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';

function loadCore() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/datepicker-core.js');
  const code = readFileSync(path, 'utf8');
  const module = { exports: {} };
  const globalThisObj = {};
  vm.runInNewContext(code, {
    module,
    exports: module.exports,
    window: globalThisObj,
    globalThis: globalThisObj,
    Date,
    Number,
    String,
    Math,
    Object,
    Array,
    parseInt,
  });
  return module.exports.toIso ? module.exports : globalThisObj.CustomerCheckDatepickerCore;
}

const Core = loadCore();

describe('CustomerCheckDatepickerCore ISO / European round-trip', () => {
  it('accepts real calendar days and rejects rolled-over fakes', () => {
    expect(Core.isIsoDate('2026-08-18')).toBe(true);
    expect(Core.isIsoDate('2026-02-30')).toBe(false);
    expect(Core.isIsoDate('2026-13-01')).toBe(false);
    expect(Core.isIsoDate('2026-00-10')).toBe(false);
    expect(Core.isIsoDate('2024-02-29')).toBe(true);
    expect(Core.isIsoDate('2023-02-29')).toBe(false);
    expect(Core.isIsoDate('18.08.2026')).toBe(false);
    expect(Core.isIsoDate('')).toBe(false);
    expect(Core.isIsoDate(null)).toBe(false);
  });

  it('converts European display to ISO without swapping day and month', () => {
    expect(Core.toIso('18.08.2026')).toBe('2026-08-18');
    expect(Core.toIso('01.12.2026')).toBe('2026-12-01');
    expect(Core.toIso('2026-08-18')).toBe('2026-08-18');
    expect(Core.toEuropean('2026-08-18')).toBe('18.08.2026');
    expect(Core.toEuropean('18.08.2026')).toBe('18.08.2026');
    expect(Core.toIso('30.02.2026')).toBe('');
    expect(Core.toIso('2026-02-30')).toBe('');
    expect(Core.toIso('')).toBe('');
    expect(Core.toIso('n/a')).toBe('');
  });

  it('does not shift the calendar day around DST (local Y-M-D, never UTC parse)', () => {
    expect(Core.isoFromYmd(2026, 3, 29)).toBe('2026-03-29');
    expect(Core.isoFromYmd(2026, 10, 25)).toBe('2026-10-25');
    const spring = new Date(2026, 2, 29);
    expect(Core.isoFromLocalDate(spring)).toBe('2026-03-29');
    const back = Core.localDateFromIso('2026-03-29');
    expect(back).toBeInstanceOf(Date);
    expect(back.getFullYear()).toBe(2026);
    expect(back.getMonth()).toBe(2);
    expect(back.getDate()).toBe(29);
  });

  it('compares and ranges on ISO strings only', () => {
    expect(Core.compareIso('2026-08-18', '2026-08-19')).toBe(-1);
    expect(Core.compareIso('2026-08-19', '2026-08-18')).toBe(1);
    expect(Core.compareIso('18.08.2026', '2026-08-18')).toBe(0);
    expect(Core.compareIso('', '2026-08-18')).toBe(0);
    expect(Core.isIsoInRange('2026-08-18', '2026-08-01', '2026-08-31')).toBe(true);
    expect(Core.isIsoInRange('2026-07-31', '2026-08-01', '2026-08-31')).toBe(false);
    expect(Core.isIsoInRange('2026-09-01', '2026-08-01', '2026-08-31')).toBe(false);
    expect(Core.isIsoInRange('2026-08-01', '2026-08-01', '2026-08-01')).toBe(true);
  });
});

describe('CustomerCheckDatepickerCore calendar grid', () => {
  it('uses Monday-first weeks (Sunday offset 6)', () => {
    expect(Core.mondayFirstOffset(0)).toBe(6);
    expect(Core.mondayFirstOffset(1)).toBe(0);
    expect(Core.mondayFirstOffset(6)).toBe(5);
  });

  it('builds 42 cells for August 2026 with Saturday the 1st in column 6', () => {
    const cells = Core.buildCalendarCells(2026, 8, {
      selectedIso: '2026-08-18',
      todayIso: '2026-08-18',
      minIso: '2026-08-10',
      maxIso: '2026-08-20',
    });
    expect(cells).toHaveLength(42);
    const firstOfMonth = cells.find((c) => c.iso === '2026-08-01');
    expect(firstOfMonth).toBeTruthy();
    expect(firstOfMonth.inMonth).toBe(true);
    expect(cells[0].iso).toBe('2026-07-27');
    expect(cells[0].inMonth).toBe(false);
    const selected = cells.find((c) => c.isSelected);
    expect(selected.iso).toBe('2026-08-18');
    expect(selected.isToday).toBe(true);
    expect(cells.find((c) => c.iso === '2026-08-09').disabled).toBe(true);
    expect(cells.find((c) => c.iso === '2026-08-10').disabled).toBe(false);
    expect(cells.find((c) => c.iso === '2026-08-21').disabled).toBe(true);
  });

  it('shifts months and clamps to the year window', () => {
    expect(Core.shiftMonth(2026, 12, 1)).toEqual({ year: 2027, month: 1 });
    expect(Core.shiftMonth(2026, 1, -1)).toEqual({ year: 2025, month: 12 });
    expect(Core.shiftMonth(Core.MIN_YEAR, 1, -1)).toEqual({ year: Core.MIN_YEAR, month: 1 });
    expect(Core.shiftMonth(Core.MAX_YEAR, 12, 1)).toEqual({ year: Core.MAX_YEAR, month: 12 });
    expect(Core.shiftMonth('x', 1, 1)).toBeNull();
  });
});

describe('CustomerCheckDatepickerCore duration and hour selectors', () => {
  it('snaps minutes to 15-minute billing units', () => {
    expect(Core.snapDurationMinutes(0)).toBe(15);
    expect(Core.snapDurationMinutes(14)).toBe(15);
    expect(Core.snapDurationMinutes(22)).toBe(15);
    expect(Core.snapDurationMinutes(23)).toBe(30);
    expect(Core.snapDurationMinutes(60)).toBe(60);
    expect(Core.snapDurationMinutes(500)).toBe(480);
    expect(Core.snapDurationMinutes('abc')).toBe(15);
    expect(Core.isAllowedDuration(15)).toBe(true);
    expect(Core.isAllowedDuration(16)).toBe(false);
    expect(Core.isAllowedDuration(0)).toBe(false);
    expect(Core.DURATION_MINUTES).toContain(60);
    expect(Core.DURATION_MINUTES.every((m) => m % 15 === 0)).toBe(true);
  });

  it('formats granny-readable duration labels', () => {
    const t = (msg, args) => {
      let out = msg;
      const list = args || [];
      let i = 0;
      out = out.replace(/%s/g, () => String(list[i++] ?? ''));
      return out;
    };
    expect(Core.formatDurationLabel(15, t)).toBe('15 minutes');
    expect(Core.formatDurationLabel(60, t)).toBe('1 hour');
    expect(Core.formatDurationLabel(120, t)).toBe('2 hours');
    expect(Core.formatDurationLabel(75, t)).toBe('1 hour 15 minutes');
    expect(Core.formatDurationLabel(135, t)).toBe('2 hours 15 minutes');
  });

  it('clamps clock hours to 00:00–23:00 for <select> values', () => {
    expect(Core.clampHour(-1)).toBe(0);
    expect(Core.clampHour(24)).toBe(23);
    expect(Core.clampHour('7')).toBe(7);
    expect(Core.formatHourLabel(5)).toBe('05:00');
    expect(Core.formatHourLabel(23)).toBe('23:00');
    expect(Core.hourValues()).toHaveLength(24);
    expect(Core.hourValues()[0]).toBe(0);
    expect(Core.hourValues()[23]).toBe(23);
  });
});

describe('CustomerCheckDatepickerCore hidden ISO source of truth', () => {
  it('reads ISO from the hidden sibling, not the European display', () => {
    const hidden = { value: '2026-08-18' };
    const display = { value: '01.01.2000' };
    const host = {
      querySelector: (sel) => (String(sel).indexOf('data-crm-date-iso') !== -1 ? hidden : display),
    };
    const el = { value: '01.01.2000', closest: () => host };
    expect(Core.isoValue(el)).toBe('2026-08-18');
  });

  it('falls back to the display field when the hidden ISO is empty', () => {
    const hidden = { value: '' };
    const display = { value: '2026-08-15' };
    const host = {
      querySelector: (sel) => (String(sel).indexOf('data-crm-date-iso') !== -1 ? hidden : display),
    };
    const el = { value: '', closest: () => host };
    expect(Core.isoValue(el)).toBe('2026-08-15');
  });

  it('reads the first populated value from a form.elements RadioNodeList', () => {
    const hidden = { value: '2026-08-18' };
    const host = { querySelector: () => hidden };
    const named = {
      length: 2,
      0: { value: '', closest: () => null },
      1: { value: '18.08.2026', closest: () => host },
      item(i) { return this[i]; },
    };
    expect(Core.isoValue(named)).toBe('2026-08-18');
  });

  it('falls back to parsing the visible value when no host exists', () => {
    expect(Core.isoValue({ value: '18.08.2026', closest: () => null })).toBe('2026-08-18');
    expect(Core.isoValue({ value: '2026-08-18' })).toBe('2026-08-18');
    expect(Core.isoValue(null)).toBe('');
  });

  it('writes ISO to the hidden field and European to the display', () => {
    const hidden = { value: '' };
    const host = { querySelector: () => hidden };
    const el = { value: '', closest: () => host };
    Core.setIsoValue(el, '2026-08-18');
    expect(hidden.value).toBe('2026-08-18');
    expect(el.value).toBe('18.08.2026');
    Core.setIsoValue(el, '30.02.2026');
    expect(hidden.value).toBe('');
    expect(el.value).toBe('');
  });

  it('parses today / ISO / European bound attributes', () => {
    expect(Core.parseBoundAttr('today', '2026-08-18')).toBe('2026-08-18');
    expect(Core.parseBoundAttr('2026-01-01')).toBe('2026-01-01');
    expect(Core.parseBoundAttr('01.01.2026')).toBe('2026-01-01');
    expect(Core.parseBoundAttr('')).toBe('');
    expect(Core.parseBoundAttr('nope')).toBe('');
  });
});

describe('CustomerCheckDatepickerCore top-layer mount strategy', () => {
  it('mounts inside the open dialog so the calendar shares showModal() top-layer', () => {
    expect(Core.resolveCalendarMountMode({ supportsPopover: true, insideOpenDialog: true })).toBe('dialog-host');
    expect(Core.resolveCalendarMountMode({ supportsPopover: false, insideOpenDialog: true })).toBe('dialog-host');
  });

  it('uses Popover on the page when no dialog owns the field', () => {
    expect(Core.resolveCalendarMountMode({ supportsPopover: true, insideOpenDialog: false })).toBe('popover');
    expect(Core.resolveCalendarMountMode({ supportsPopover: false, insideOpenDialog: false })).toBe('body');
    expect(Core.resolveCalendarMountMode({})).toBe('body');
    expect(Core.resolveCalendarMountMode(null)).toBe('body');
  });

  it('detects Popover capability from a prototype-like object', () => {
    expect(Core.supportsCalendarPopover({
      showPopover: () => {},
      hidePopover: () => {},
    })).toBe(true);
    expect(Core.supportsCalendarPopover({ showPopover: () => {} })).toBe(false);
    expect(Core.supportsCalendarPopover({})).toBe(false);
    expect(Core.supportsCalendarPopover(null)).toBe(false);
  });

  it('finds only open dialog ancestors for the date field', () => {
    const openDialog = { open: true, hasAttribute: () => true };
    const closedDialog = { open: false, hasAttribute: () => false };
    expect(Core.findOpenDialogHost({ closest: () => openDialog })).toBe(openDialog);
    expect(Core.findOpenDialogHost({ closest: () => closedDialog })).toBeNull();
    expect(Core.findOpenDialogHost({ closest: () => null })).toBeNull();
    expect(Core.findOpenDialogHost(null)).toBeNull();
    expect(Core.findOpenDialogHost({})).toBeNull();

    const attrOpen = { open: false, hasAttribute: (name) => name === 'open' };
    expect(Core.findOpenDialogHost({ closest: () => attrOpen })).toBe(attrOpen);
  });
});
