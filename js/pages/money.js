(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  if (C && C.init) { C.init(); }

  const toggle = document.getElementById('crm-money-filter-toggle');
  const panel = document.getElementById('crm-money-filter');
  if (toggle && panel) {
    toggle.addEventListener('click', () => {
      const open = panel.hasAttribute('hidden');
      if (open) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-crm-payment-reminder]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const companyId = btn.getAttribute('data-company-id');
        const agreementId = btn.getAttribute('data-crm-payment-reminder');
        const res = await Api.post('/companies/' + companyId + '/compose-template', {
          template: 'payment_reminder',
          agreementId: parseInt(agreementId, 10),
        });
        const data = res.data || res;
        if (data.mailAppUrl) window.open(data.mailAppUrl, '_blank');
        else if (data.mailtoUrl) window.location.href = data.mailtoUrl;
        const live = document.getElementById('crm-live-region');
        if (live) live.textContent = 'Payment reminder prepared.';
      } catch (e) {
        if (C) await C.alertDialog({ title: 'Payment reminder', message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
      }
    });
  });
})();
