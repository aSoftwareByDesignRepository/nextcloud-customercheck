/**
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import {
  buildBillingHonesty,
  buildBillingSummaryTiles,
  safeInternalHref,
} from '../../js/utils/company360-present.js';

const t = (msg, args) => (args && args.length ? msg.replace('%s', args[0]) : msg);
const rawT = (msg) => msg;
const billingStateLabel = (state) => {
  if (state === 'ok') return 'Paid up';
  if (state === 'critical') return 'Overdue';
  if (state === 'attention') return 'Needs attention';
  return 'Unknown';
};
const billingTone = (state) => {
  if (state === 'ok') return 'success';
  if (state === 'critical') return 'danger';
  if (state === 'attention') return 'warning';
  return 'neutral';
};

describe('safeInternalHref', () => {
  it('allows absolute app paths and rejects schemes / protocol-relative', () => {
    expect(safeInternalHref('/apps/tasks/', '/')).toBe('/apps/tasks/');
    expect(safeInternalHref('/apps/tasks?x=1', '/')).toBe('/apps/tasks?x=1');
    expect(safeInternalHref('javascript:alert(1)', '/apps/tasks/')).toBe('/apps/tasks/');
    expect(safeInternalHref('data:text/html,x', '/apps/tasks/')).toBe('/apps/tasks/');
    expect(safeInternalHref('//evil.example/x', '/apps/tasks/')).toBe('/apps/tasks/');
    expect(safeInternalHref('https://evil.example/', '/apps/tasks/')).toBe('/apps/tasks/');
    expect(safeInternalHref('\\evil', '/apps/tasks/')).toBe('/apps/tasks/');
    expect(safeInternalHref('', '/apps/tasks/')).toBe('/apps/tasks/');
    expect(safeInternalHref(null, '/apps/tasks/')).toBe('/apps/tasks/');
  });
});

describe('buildBillingSummaryTiles', () => {
  it('builds three granny tiles with overdue danger and open warning', () => {
    const tiles = buildBillingSummaryTiles(
      { state: 'critical', overdueCount: 2, openCount: 3 },
      { t, billingStateLabel, billingTone },
    );
    expect(tiles).toHaveLength(3);
    expect(tiles.map((x) => x.key)).toEqual(['status', 'overdue', 'open']);
    expect(tiles[0].value).toBe('Overdue');
    expect(tiles[0].tone).toBe('danger');
    expect(tiles[1].value).toBe('2');
    expect(tiles[1].tone).toBe('danger');
    expect(tiles[1].meta).toBe('Needs collection');
    expect(tiles[2].value).toBe('3');
    expect(tiles[2].tone).toBe('warning');
  });

  it('adds license tile when licenses exist', () => {
    const tiles = buildBillingSummaryTiles(
      {
        state: 'attention',
        overdueCount: 0,
        openCount: 1,
        licenses: { total: 2, unpaid: 1 },
      },
      { t, billingStateLabel, billingTone },
    );
    expect(tiles).toHaveLength(4);
    expect(tiles[3].key).toBe('licenses');
    expect(tiles[3].value).toBe('1');
    expect(tiles[3].tone).toBe('warning');
  });

  it('marks zero overdue/open as calm and survives null billing', () => {
    const tiles = buildBillingSummaryTiles(
      { state: 'ok', overdueCount: 0, openCount: 0 },
      { t, billingStateLabel, billingTone },
    );
    expect(tiles[1].tone).toBe('success');
    expect(tiles[1].meta).toBe('None overdue');
    expect(tiles[2].tone).toBe('neutral');
    expect(tiles[2].meta).toBe('Nothing open');
    expect(buildBillingSummaryTiles(null, { t, billingStateLabel, billingTone })[0].value).toBe('Unknown');
  });
});

describe('buildBillingHonesty', () => {
  it('explains license mode and unset invoice status', () => {
    expect(
      buildBillingHonesty(
        {
          sourceMode: 'licenses',
          sources: { invoiceCheckAvailable: false, projectCheckAvailable: false, licenses: true },
          licenses: { total: 1, unpaid: 1, none: 0 },
        },
        t,
      ).text,
    ).toMatch(/license payment status/i);

    expect(
      buildBillingHonesty(
        {
          sourceMode: 'none',
          sources: { invoiceCheckAvailable: false, projectCheckAvailable: false, licenses: true },
          licenses: { total: 2, unpaid: 0, none: 2 },
        },
        t,
      ).tone,
    ).toBe('warning');

    expect(
      buildBillingHonesty(
        {
          sourceMode: 'invoicecheck',
          sources: { invoiceCheckAvailable: true, invoiceCheckLinked: true },
          licenses: { total: 0 },
        },
        t,
      ).text,
    ).toMatch(/InvoiceCheck/i);

    expect(
      buildBillingHonesty(
        {
          sourceMode: 'none',
          sources: { invoiceCheckAvailable: true, invoiceCheckLinked: true },
          licenses: { total: 2, unpaid: 2, none: 0 },
        },
        rawT,
      ).text,
    ).toBe('2 license invoice(s) still unpaid.');
  });
});
