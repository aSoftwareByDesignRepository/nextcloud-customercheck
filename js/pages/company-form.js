(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  if (C && C.init) { C.init(); }

  const form = document.getElementById('crm-company-form');
  if (!form) return;
  const errBox = document.getElementById('crm-company-form-error');
  const nameInput = document.getElementById('crm-company-name');
  const slugInput = document.getElementById('crm-company-slug');

  function t(key) {
    if (typeof OC !== 'undefined' && OC.L10N && typeof OC.L10N.translate === 'function') {
      return OC.L10N.translate('customercheck', key);
    }
    return key;
  }

  /**
   * Filter sibling <select> options by name — keeps value=id under the hood.
   * @param {HTMLInputElement|null} filter
   * @param {HTMLSelectElement|null} select
   */
  function wireSiblingFilter(filter, select) {
    if (!filter || !select || select.tagName !== 'SELECT') return;
    const all = Array.from(select.options).map((opt) => ({
      value: opt.value,
      label: opt.textContent || '',
      selected: opt.selected,
    }));
    filter.addEventListener('input', () => {
      const q = filter.value.trim().toLowerCase();
      const current = select.value;
      select.innerHTML = '';
      all.forEach((row) => {
        if (row.value !== '' && q !== '' && row.label.toLowerCase().indexOf(q) === -1) {
          return;
        }
        const opt = document.createElement('option');
        opt.value = row.value;
        opt.textContent = row.label;
        if (row.value === current) opt.selected = true;
        select.appendChild(opt);
      });
      if (current && !Array.from(select.options).some((o) => o.value === current)) {
        select.value = '';
      }
    });
  }

  wireSiblingFilter(
    document.getElementById('crm-company-pc-filter'),
    document.getElementById('crm-company-pc'),
  );
  wireSiblingFilter(
    document.getElementById('crm-company-tc-filter'),
    document.getElementById('crm-company-tc'),
  );

  if (form.dataset.mode === 'create' && nameInput && slugInput) {
    let timer = null;
    nameInput.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const name = nameInput.value.trim();
        if (name.length < 2) return;
        try {
          const res = await Api.post('/companies/suggest-slug', { name });
          if (res && res.data && res.data.slug && !slugInput.dataset.touched) {
            slugInput.value = res.data.slug;
          }
        } catch (e) { /* suggestion is best-effort */ }
      }, 250);
    });
    slugInput.addEventListener('input', () => { slugInput.dataset.touched = '1'; });
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    errBox.hidden = true;
    let slug = document.getElementById('crm-company-slug').value.trim();
    const name = document.getElementById('crm-company-name').value.trim();
    if (form.dataset.mode === 'create' && slug === '' && name.length >= 2) {
      try {
        const res = await Api.post('/companies/suggest-slug', { name });
        if (res && res.data && res.data.slug) {
          slug = res.data.slug;
          slugInput.value = slug;
        }
      } catch (e) { /* fall through to server validation */ }
    }
    const body = {
      name: name,
      slug: slug,
      status: document.getElementById('crm-company-status').value,
      lang: document.getElementById('crm-company-lang').value,
      notes: document.getElementById('crm-company-notes').value,
      accountOwnerUserId: (document.getElementById('crm-company-owner') || {}).value || '',
    };
    const pc = document.getElementById('crm-company-pc');
    const tc = document.getElementById('crm-company-tc');
    if (pc) {
      const v = parseInt(pc.value, 10);
      body.pcCustomerId = Number.isFinite(v) && v > 0 ? v : null;
    }
    if (tc) {
      const v = parseInt(tc.value, 10);
      body.tcCustomerId = Number.isFinite(v) && v > 0 ? v : null;
    }
    try {
      let res;
      if (form.dataset.mode === 'edit') {
        body.updatedAt = form.dataset.updatedAt || null;
        res = await Api.put('/companies/' + form.dataset.id, body);
      } else {
        res = await Api.post('/companies', body);
      }
      const id = res.data && (res.data.id || (res.data.company && res.data.company.id));
      window.location.href = OC.generateUrl('/apps/customercheck/companies/' + id);
    } catch (e) {
      errBox.hidden = false;
      errBox.textContent = (e.payload && e.payload.message) || e.message;
    }
  });

  /**
   * Select an existing sibling id in the matching <select> (adds option if missing).
   * @param {string} selectId
   * @param {number} siblingId
   * @param {string} label
   */
  function selectSiblingOption(selectId, siblingId, label) {
    const select = document.getElementById(selectId);
    if (!select || select.tagName !== 'SELECT') return;
    const value = String(siblingId);
    let opt = Array.from(select.options).find((o) => o.value === value);
    if (!opt) {
      opt = document.createElement('option');
      opt.value = value;
      opt.textContent = label || ('#' + value);
      select.appendChild(opt);
    }
    select.value = value;
  }

  /**
   * Create sibling customer from CRM company (write facade). On duplicate_name,
   * offer one-click ensureLink with existingPcCustomerId / existingTcCustomerId.
   * @param {string} pathSuffix
   * @param {HTMLButtonElement|null} btn
   * @param {{ fieldKey: string, selectId: string, bodyKey: string }} opts
   */
  function wireCreateSibling(pathSuffix, btn, opts) {
    if (!btn || form.dataset.mode !== 'edit' || !form.dataset.id) return;

    async function runCreate(extraBody) {
      errBox.hidden = true;
      while (errBox.firstChild) {
        errBox.removeChild(errBox.firstChild);
      }
      btn.disabled = true;
      try {
        const body = Object.assign({
          updatedAt: form.dataset.updatedAt || null,
        }, extraBody || {});
        const res = await Api.post('/companies/' + form.dataset.id + pathSuffix, body);
        const company = res.data && (res.data.company || res.data);
        if (company && company.updatedAt) {
          form.dataset.updatedAt = company.updatedAt;
        }
        window.location.href = OC.generateUrl('/apps/customercheck/companies/' + form.dataset.id);
      } catch (e) {
        const fields = (e.payload && e.payload.fields) || {};
        const existingId = parseInt(fields[opts.fieldKey], 10);
        errBox.hidden = false;
        const msg = document.createElement('p');
        msg.textContent = (e.payload && e.payload.message) || e.message;
        errBox.appendChild(msg);
        if (Number.isFinite(existingId) && existingId > 0) {
          selectSiblingOption(opts.selectId, existingId, t('Existing customer'));
          const linkBtn = document.createElement('button');
          linkBtn.type = 'button';
          linkBtn.className = 'crm-btn crm-btn--primary';
          linkBtn.style.minHeight = '44px';
          linkBtn.style.marginTop = '0.5rem';
          linkBtn.textContent = t('Link existing customer');
          linkBtn.addEventListener('click', () => {
            const payload = {};
            payload[opts.bodyKey] = existingId;
            runCreate(payload);
          });
          errBox.appendChild(linkBtn);
        }
      } finally {
        btn.disabled = false;
      }
    }

    btn.addEventListener('click', () => {
      runCreate({});
    });
  }

  wireCreateSibling('/create-pc-customer', document.getElementById('crm-create-pc-customer'), {
    fieldKey: 'existingPcCustomerId',
    selectId: 'crm-company-pc',
    bodyKey: 'existingPcCustomerId',
  });
  wireCreateSibling('/create-tc-customer', document.getElementById('crm-create-tc-customer'), {
    fieldKey: 'existingTcCustomerId',
    selectId: 'crm-company-tc',
    bodyKey: 'existingTcCustomerId',
  });
})();
