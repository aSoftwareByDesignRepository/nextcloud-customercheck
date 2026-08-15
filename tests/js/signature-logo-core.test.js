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
    URL,
  };
  vm.runInNewContext(code, context);
  return module.exports.isPublicImageUrl
    ? module.exports
    : globalThisObj.CustomerCheckSignatureLogo;
}

const Logo = loadLogoApi();

describe('signature-logo-core (AS-18 / Argus SF-01)', () => {
  it('accepts https image URLs', () => {
    expect(Logo.isPublicImageUrl('https://cdn.example/logo.png')).toBe(true);
    expect(Logo.isPublicImageUrl('data:image/png;base64,xx')).toBe(false);
    expect(Logo.isPublicImageUrl('javascript:alert(1)')).toBe(false);
    expect(Logo.isPublicImageUrl('')).toBe(false);
    expect(Logo.isPublicImageUrl('ftp://x/y')).toBe(false);
  });

  it('rejects third-party http logos (tracking / mixed-content)', () => {
    expect(Logo.isPublicImageUrl('http://files.local/a.jpg')).toBe(false);
    expect(Logo.isPublicImageUrl('http://files.local/a.jpg', { pageOrigin: 'http://localhost:8081' })).toBe(false);
    expect(
      Logo.isPublicImageUrl('http://evil.example/s/Token/download', {
        pageOrigin: 'http://localhost:8081',
      }),
    ).toBe(false);
  });

  it('allows same-origin Nextcloud public share http logos (local Docker)', () => {
    const opts = { pageOrigin: 'http://localhost:8081' };
    expect(Logo.isPublicImageUrl('http://localhost:8081/s/AbCdEf/download', opts)).toBe(true);
    expect(Logo.isPublicImageUrl('http://localhost:8081/s/Ab-Cd_Ef/download', opts)).toBe(true);
    expect(Logo.isPublicImageUrl('http://localhost:8081/index.php/s/AbCdEf/download', opts)).toBe(true);
    expect(Logo.isPublicImageUrl('http://localhost:8081/remote.php/dav/files/x.png', opts)).toBe(false);
    expect(Logo.isNextcloudPublicSharePath('/s/AbCdEf/download')).toBe(true);
    expect(Logo.isNextcloudPublicSharePath('/apps/files/x')).toBe(false);
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
    const local = Logo.buildSignatureLogoHtml('http://localhost:8081/s/Tok/download', {
      pageOrigin: 'http://localhost:8081',
      alt: 'Logo',
      width: 180,
    });
    expect(local).toContain('http://localhost:8081/s/Tok/download');
  });

  it('appends /download to public share URLs', () => {
    expect(Logo.publicShareToImageSrc('https://nc.example/s/Token')).toBe(
      'https://nc.example/s/Token/download',
    );
    expect(Logo.publicShareToImageSrc('https://nc.example/s/Token/download')).toBe(
      'https://nc.example/s/Token/download',
    );
    expect(Logo.publicShareToImageSrc('http://nc.local/s/Token')).toBe('');
    expect(
      Logo.publicShareToImageSrc('http://localhost:8081/s/Token', {
        pageOrigin: 'http://localhost:8081',
      }),
    ).toBe('http://localhost:8081/s/Token/download');
    expect(Logo.publicShareToImageSrc('data:x')).toBe('');
  });

  it('reuses only passwordless downloadable public shares from OCS payloads', () => {
    expect(Logo.isReusablePublicShareRow({ share_type: 3, url: 'https://nc/s/A' })).toBe(true);
    expect(Logo.isReusablePublicShareRow({ share_type: 3, url: 'https://nc/s/A', password: 'x' })).toBe(false);
    expect(Logo.isReusablePublicShareRow({ share_type: 3, url: 'https://nc/s/A', hide_download: 1 })).toBe(false);
    expect(Logo.isReusablePublicShareRow({ share_type: 0, url: 'https://nc/s/A' })).toBe(false);

    expect(
      Logo.shareUrlFromOcsData({
        ocs: {
          data: [
            { share_type: 3, url: 'https://nc/s/Secret', password: 'pw' },
            { share_type: 3, url: 'https://nc/s/Good' },
          ],
        },
      }),
    ).toBe('https://nc/s/Good');

    expect(
      Logo.shareUrlFromOcsData({
        ocs: { data: { share_type: 3, url: 'https://nc/s/Created' } },
      }),
    ).toBe('https://nc/s/Created');

    expect(Logo.shareUrlFromOcsData({ ocs: { data: { url: 'https://nc/s/Bare' } } })).toBe(
      'https://nc/s/Bare',
    );
    expect(Logo.shareUrlFromOcsData(null)).toBe('');
  });
});
