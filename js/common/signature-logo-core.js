/**
 * Pure helpers for signature logo insert (AS-18 http(s) img).
 * No DOM — vitest + mutation friendly.
 */
(function (global) {
  'use strict';

  function isPublicImageUrl(url) {
    const src = String(url || '').trim();
    if (src === '' || src.length > 2048) {
      return false;
    }
    if (!/^https?:\/\//i.test(src)) {
      return false;
    }
    if (/^\s*(javascript|data|vbscript)\s*:/i.test(src)) {
      return false;
    }
    return true;
  }

  function escapeAttr(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  /**
   * @param {string} url
   * @param {{ alt?: string, width?: number }} [opts]
   * @returns {string} safe HTML snippet or ''
   */
  function buildSignatureLogoHtml(url, opts) {
    const o = opts && typeof opts === 'object' ? opts : {};
    if (!isPublicImageUrl(url)) {
      return '';
    }
    let width = parseInt(String(o.width != null ? o.width : 180), 10);
    if (!Number.isFinite(width) || width < 16) width = 16;
    if (width > 600) width = 600;
    let alt = String(o.alt != null ? o.alt : 'Logo').trim() || 'Logo';
    if (alt.length > 120) alt = alt.slice(0, 120);
    return '<p><img src="' + escapeAttr(String(url).trim()) + '" alt="' + escapeAttr(alt) + '" width="' + width + '"></p>';
  }

  /**
   * Prefer a direct download URL for public shares so email clients get the image bytes.
   * @param {string} shareUrl
   */
  function publicShareToImageSrc(shareUrl) {
    const u = String(shareUrl || '').trim().replace(/\/+$/, '');
    if (!isPublicImageUrl(u)) {
      return '';
    }
    if (/\/download$/i.test(u)) {
      return u;
    }
    return u + '/download';
  }

  const api = {
    isPublicImageUrl: isPublicImageUrl,
    buildSignatureLogoHtml: buildSignatureLogoHtml,
    publicShareToImageSrc: publicShareToImageSrc,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckSignatureLogo = api;
})(typeof window !== 'undefined' ? window : globalThis);
