/**
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import {
  applyExclude,
  filterLocalCompanies,
  normalizeCompanyHits,
} from '../../js/utils/company-picker-core.js';

describe('normalizeCompanyHits', () => {
  it('maps array rows and drops invalid ids', () => {
    expect(normalizeCompanyHits([
      { id: 3, name: 'Acme', updatedAt: '2026-01-01' },
      { id: 0, name: 'Nope' },
      { id: '7', label: 'Brandt' },
    ])).toEqual([
      { id: 3, name: 'Acme', updatedAt: '2026-01-01' },
      { id: 7, name: 'Brandt', updatedAt: '' },
    ]);
  });

  it('unwraps nested data/items payloads', () => {
    expect(normalizeCompanyHits({ data: [{ id: 1, name: 'One' }] })).toEqual([
      { id: 1, name: 'One', updatedAt: '' },
    ]);
    expect(normalizeCompanyHits({ items: [{ id: 2, name: 'Two' }] })).toEqual([
      { id: 2, name: 'Two', updatedAt: '' },
    ]);
  });
});

describe('filterLocalCompanies', () => {
  const rows = [
    { id: 1, name: 'Alpha GmbH' },
    { id: 2, name: 'Brandt & Söhne' },
    { id: 3, name: 'Hafenblick' },
  ];

  it('returns browse slice when query empty', () => {
    expect(filterLocalCompanies(rows, '', 2)).toEqual([
      { id: 1, name: 'Alpha GmbH' },
      { id: 2, name: 'Brandt & Söhne' },
    ]);
  });

  it('filters case-insensitively', () => {
    expect(filterLocalCompanies(rows, 'brandt', 40)).toEqual([
      { id: 2, name: 'Brandt & Söhne' },
    ]);
  });
});

describe('applyExclude', () => {
  it('drops banned ids', () => {
    expect(applyExclude([
      { id: 1, name: 'A' },
      { id: 2, name: 'B' },
      { id: 3, name: 'C' },
    ], [2, '3'])).toEqual([{ id: 1, name: 'A' }]);
  });
});
