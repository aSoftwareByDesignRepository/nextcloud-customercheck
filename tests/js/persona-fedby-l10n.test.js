/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

describe('persona pulse fedBy l10n', () => {
  it('localizes the invoicing-app segment (intake 20261125T053314Z)', () => {
    const js = readFileSync(join(root, 'js/pages/persona.js'), 'utf8');
    // Bare ' · the invoicing app ' concat rendered English mid-sentence in
    // every non-en locale. The segment must go through t() like its siblings.
    expect(js).toContain("t('the invoicing app')");
    expect(js).not.toContain('· the invoicing app ·');
    expect(js).not.toMatch(/['"`] · the invoicing app ['"`]/);
    // Catalogs must ship the msgid the page resolves at runtime.
    const en = JSON.parse(readFileSync(join(root, 'l10n/en.json'), 'utf8'));
    expect(en.translations['the invoicing app']).toBe('the invoicing app');
    const da = JSON.parse(readFileSync(join(root, 'l10n/da.json'), 'utf8'));
    expect(da.translations['the invoicing app']).toBe('faktureringsappen');
  });
});
