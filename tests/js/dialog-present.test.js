/**
 * @vitest-environment node
 */
import { describe, expect, it } from 'vitest';
import {
  buildMailPackDialogHtml,
  escapeHtml,
  safeExternalHref,
} from '../../js/utils/dialog-present.js';

describe('escapeHtml', () => {
  it('escapes angle brackets and quotes', () => {
    expect(escapeHtml('<img src=x onerror=alert(1)>')).toBe(
      '&lt;img src=x onerror=alert(1)&gt;',
    );
    expect(escapeHtml('"quoted"')).toContain('&quot;');
  });
});

describe('safeExternalHref', () => {
  it('allows http(s), mailto, and absolute paths', () => {
    expect(safeExternalHref('https://mail.example/compose')).toBe('https://mail.example/compose');
    expect(safeExternalHref('mailto:a@b.c?subject=Hi')).toBe('mailto:a@b.c?subject=Hi');
    expect(safeExternalHref('/apps/mail/')).toBe('/apps/mail/');
  });

  it('blocks javascript and data URLs', () => {
    expect(safeExternalHref('javascript:alert(1)')).toBe('#');
    expect(safeExternalHref('JAVASCRIPT:alert(1)')).toBe('#');
    expect(safeExternalHref('data:text/html,x')).toBe('#');
    expect(safeExternalHref('')).toBe('#');
  });
});

describe('buildMailPackDialogHtml', () => {
  const t = (m) => m;

  it('escapes subject/body and sanitizes href', () => {
    const html = buildMailPackDialogHtml({
      subject: '<script>x</script>',
      bodyText: 'Hello <b>world</b>',
      mailAppUrl: 'javascript:evil()',
    }, { t });
    expect(html).not.toContain('<script>');
    expect(html).toContain('&lt;script&gt;');
    expect(html).toContain('Hello &lt;b&gt;world&lt;/b&gt;');
    expect(html).toContain('href="#"');
    expect(html).not.toContain('javascript:');
    expect(html).toContain('rel="noopener noreferrer"');
  });

  it('keeps safe mailto and extra actions', () => {
    const html = buildMailPackDialogHtml({
      subject: 'Hi',
      bodyText: 'Body',
      mailtoUrl: 'mailto:team@example.com',
    }, { t, extraActionsHtml: '<button type="button" id="x">X</button>' });
    expect(html).toContain('href="mailto:team@example.com"');
    expect(html).toContain('id="x"');
  });
});
