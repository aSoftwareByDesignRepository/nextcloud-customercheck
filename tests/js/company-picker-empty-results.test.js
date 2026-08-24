/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

describe('company-picker empty-results hook', () => {
  it('invokes onEmptyResults after successful filter with zero rows', () => {
    const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/company-picker.js');
    const js = readFileSync(path, 'utf8');
    expect(js).toMatch(/var shown = rows\.length \? rows : local;/);
    expect(js).toContain('if (!shown.length && q && typeof opts.onEmptyResults === \'function\')');
    expect(js).toContain('opts.onEmptyResults(q);');
  });
});
