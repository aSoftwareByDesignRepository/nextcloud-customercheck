import { describe, expect, it } from 'vitest';
import {
  addYears,
  buildAgreementSaveBody,
  eurosToCents,
  todayIso,
} from '../../js/utils/agreement-form-core.js';

describe('todayIso / addYears', () => {
  it('formats local calendar dates without UTC drift', () => {
    expect(todayIso(new Date(2026, 7, 7))).toBe('2026-08-07');
  });

  it('adds years on UTC date parts (leap-safe Feb 29)', () => {
    expect(addYears('2024-02-29', 1)).toBe('2025-03-01');
    expect(addYears('2026-08-07', 1)).toBe('2027-08-07');
  });

  it('falls back to today+years when input is empty', () => {
    const next = addYears('', 1);
    expect(next).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });
});

describe('eurosToCents', () => {
  it('rounds euro amounts to integer cents', () => {
    expect(eurosToCents('1200.00')).toBe(120000);
    expect(eurosToCents('10.005')).toBe(1001);
    expect(eurosToCents('nope')).toBe(0);
  });
});

describe('buildAgreementSaveBody', () => {
  it('omits limits on edit so partner hoursQuota cannot be wiped', () => {
    const body = buildAgreementSaveBody({
      mode: 'edit',
      label: 'Partner',
      kind: 'partner',
      productCode: 'cp-std',
      startsAt: '2026-01-01',
      validUntil: '2026-12-31',
      amountCents: 50000,
      invoiceStatus: 'none',
      updatedAt: '2026-08-01T10:00:00+00:00',
    });
    expect(body).not.toHaveProperty('limits');
    expect(body).not.toHaveProperty('status');
    expect(body).not.toHaveProperty('companyId');
    expect(body.updatedAt).toBe('2026-08-01T10:00:00+00:00');
    expect(body.label).toBe('Partner');
  });

  it('omits limits on create so the server applies product defaults', () => {
    const body = buildAgreementSaveBody({
      mode: 'create',
      label: 'License',
      kind: 'license',
      productCode: 'azc',
      startsAt: '2026-08-07',
      validUntil: '2027-08-07',
      amountCents: 120000,
      invoiceStatus: 'none',
      companyId: 9,
    });
    expect(body).not.toHaveProperty('limits');
    expect(body.status).toBe('active');
    expect(body.companyId).toBe(9);
  });

  it('clears validUntil for onetime kind', () => {
    const body = buildAgreementSaveBody({
      mode: 'edit',
      label: 'One shot',
      kind: 'onetime',
      productCode: 'azc',
      startsAt: '2026-08-07',
      validUntil: '2027-08-07',
      amountCents: 100,
      invoiceStatus: 'none',
    });
    expect(body.validUntil).toBeNull();
  });
});
