/**
 * @vitest-environment node
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { describe, expect, it } from 'vitest';

function loadLogoApi() {
  const path = join(dirname(fileURLToPath(import.meta.url)), '../../js/common/signature-logo-core.js');
  const code = readFileSync(path, 'utf8');
  const module = { exports: {} };
  const globalThisObj = {};
  const context = {
    module,
    exports: module.exports,
    window: globalThisObj,
    globalThis: globalThisObj,
  };
  vm.runInNewContext(code, context);
  return module.exports.isPublicImageUrl
    ? module.exports
    : globalThisObj.CustomerCheckSignatureLogo;
}

const Logo = loadLogoApi();

describe('signature-logo-core (AS-18 / Argus SF-01)', () => {
  it('accepts https image URLs only', () => {
    expect(Logo.isPublicImageUrl('https://cdn.example/logo.png')).toBe(true);
    expect(Logo.isPublicImageUrl('http://files.local/a.jpg')).toBe(false);
    expect(Logo.isPublicImageUrl('data:image/png;base64,xx')).toBe(false);
    expect(Logo.isPublicImageUrl('javascript:alert(1)')).toBe(false);
    expect(Logo.isPublicImageUrl('')).toBe(false);
    expect(Logo.isPublicImageUrl('ftp://x/y')).toBe(false);
  });

  it('builds capped img HTML or empty for bad URLs', () => {
    const html = Logo.buildSignatureLogoHtml('https://cdn.example/logo.png', {
      alt: 'Acme',
      width: 900,
    });
    expect(html).toContain('<img src="https://cdn.example/logo.png"');
    expect(html).toContain('alt="Acme"');
    expect(html).toContain('width="600"');
    expect(Logo.buildSignatureLogoHtml('http://files.local/a.jpg')).toBe('');
    expect(Logo.buildSignatureLogoHtml('data:image/png;base64,x')).toBe('');
  });

  it('appends /download to public share URLs', () => {
    expect(Logo.publicShareToImageSrc('https://nc.example/s/Token')).toBe(
      'https://nc.example/s/Token/download',
    );
    expect(Logo.publicShareToImageSrc('https://nc.example/s/Token/download')).toBe(
      'https://nc.example/s/Token/download',
    );
    expect(Logo.publicShareToImageSrc('http://nc.local/s/Token')).toBe('');
    expect(Logo.publicShareToImageSrc('data:x')).toBe('');
  });
});
