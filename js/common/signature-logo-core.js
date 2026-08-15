/**
 * Pure helpers for signature logo insert (AS-18 / Argus SF-01).
 * Mirrored by OCA\CustomerCheck\Service\SignatureLogoUrlPolicy (PHP).
 * No DOM — vitest + mutation friendly.
 *
 * Allow https anywhere; allow http only for same-origin Nextcloud public share
 * download paths (local Docker / HTTP instances). Block data:/javascript: and
 * third-party http logos (tracking / mixed-content).
 */
(function (global) {
  'use strict';

  var MAX_LEN = 2048;
  // NC share tokens are alphanumeric; allow _- for forward-compat.
  var SHARE_PATH_RE = /^\/(?:index\.php\/)?s\/[A-Za-z0-9_-]+(?:\/download)?\/?$/i;

  function isNextcloudPublicSharePath(pathname) {
    return SHARE_PATH_RE.test(String(pathname || ''));
  }

  /**
   * @param {string} url
   * @param {{ pageOrigin?: string, allowHttpHosts?: string[] }} [opts]
   */
  function isPublicImageUrl(url, opts) {
    const src = String(url || '').trim();
    if (src === '' || src.length > MAX_LEN) {
      return false;
    }
    if (/^\s*(javascript|data|vbscript)\s*:/i.test(src)) {
      return false;
    }
    if (/^https:\/\//i.test(src)) {
      return true;
    }
    if (!/^http:\/\//i.test(src)) {
      return false;
    }
    const o = opts && typeof opts === 'object' ? opts : {};
    let hosts = Array.isArray(o.allowHttpHosts) ? o.allowHttpHosts.slice() : [];
    if (o.pageOrigin) {
      try {
        const page = new URL(String(o.pageOrigin));
        if (page.host) hosts.push(page.host);
      } catch (_e) { /* ignore */ }
    }
    if (!hosts.length) {
      return false;
    }
    try {
      const u = new URL(src);
      if (!isNextcloudPublicSharePath(u.pathname)) {
        return false;
      }
      const want = String(u.host || '').toLowerCase();
      for (let i = 0; i < hosts.length; i += 1) {
        if (String(hosts[i] || '').toLowerCase() === want) {
          return true;
        }
      }
      return false;
    } catch (_e2) {
      return false;
    }
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
   * @param {{ alt?: string, width?: number, pageOrigin?: string, allowHttpHosts?: string[] }} [opts]
   * @returns {string} safe HTML snippet or ''
   */
  function buildSignatureLogoHtml(url, opts) {
    const o = opts && typeof opts === 'object' ? opts : {};
    if (!isPublicImageUrl(url, o)) {
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
   * @param {{ pageOrigin?: string, allowHttpHosts?: string[] }} [opts]
   */
  function publicShareToImageSrc(shareUrl, opts) {
    let u = String(shareUrl || '').trim().replace(/\/+$/, '');
    if (u === '') {
      return '';
    }
    if (!/\/download$/i.test(u)) {
      u += '/download';
    }
    return isPublicImageUrl(u, opts) ? u : '';
  }

  /**
   * Public link rows that email clients can fetch without a password.
   * @param {object} row OCS share row
   */
  function isReusablePublicShareRow(row) {
    if (!row || typeof row !== 'object') {
      return false;
    }
    if (Number(row.share_type) !== 3) {
      return false;
    }
    if (!row.url) {
      return false;
    }
    const pwd = row.password;
    if (pwd != null && String(pwd) !== '') {
      return false;
    }
    const hide = row.hide_download;
    if (hide === true || hide === 1 || hide === '1') {
      return false;
    }
    return true;
  }

  /**
   * Extract a reusable public share URL from an OCS shares list/create payload.
   * @param {object|null} data
   * @returns {string}
   */
  function shareUrlFromOcsData(data) {
    if (!data || !data.ocs || data.ocs.data == null) {
      return '';
    }
    const payload = data.ocs.data;
    if (Array.isArray(payload)) {
      for (let i = 0; i < payload.length; i += 1) {
        const row = payload[i];
        if (isReusablePublicShareRow(row)) {
          return String(row.url);
        }
      }
      return '';
    }
    if (isReusablePublicShareRow(payload)) {
      return String(payload.url);
    }
    // Fresh create responses often omit password/hide_download — accept type-3 url.
    if (payload && Number(payload.share_type) === 3 && payload.url) {
      return String(payload.url);
    }
    // Some create payloads omit share_type and only return url.
    if (payload && payload.url && !Object.prototype.hasOwnProperty.call(payload, 'share_type')) {
      return String(payload.url);
    }
    return '';
  }

  const api = {
    isPublicImageUrl: isPublicImageUrl,
    isNextcloudPublicSharePath: isNextcloudPublicSharePath,
    buildSignatureLogoHtml: buildSignatureLogoHtml,
    publicShareToImageSrc: publicShareToImageSrc,
    isReusablePublicShareRow: isReusablePublicShareRow,
    shareUrlFromOcsData: shareUrlFromOcsData,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  global.CustomerCheckSignatureLogo = api;
})(typeof window !== 'undefined' ? window : globalThis);
