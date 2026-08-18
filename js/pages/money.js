(function () {
  'use strict';
  const C = window.CustomerCheckComponents || window.CrmComponents;
  const Api = window.CustomerCheckApi;
  const SharedMail = window.CustomerCheckSharedMail || null;
  const t = (msg) => (window.t ? window.t('customercheck', msg) : msg);
  if (C && C.init) { C.init(); }

  const ListToolbar = window.CustomerCheckListToolbar;
  if (ListToolbar && typeof ListToolbar.bind === 'function') {
    ListToolbar.bind({
      controlsId: 'crm-money-controls',
      toggleId: 'crm-money-filter-toggle',
      panelId: 'crm-money-filter',
    });
  }

  document.addEventListener('click', async (ev) => {
    const target = ev.target;
    if (!target || typeof target.closest !== 'function') {
      return;
    }
    const btn = target.closest('[data-crm-payment-reminder]');
    if (!btn) {
      return;
    }
    ev.preventDefault();
    if (btn.getAttribute('aria-busy') === 'true') {
      return;
    }
    btn.setAttribute('aria-busy', 'true');
    try {
      const companyId = btn.getAttribute('data-company-id');
      const agreementId = btn.getAttribute('data-crm-payment-reminder');
      const res = await Api.post('/companies/' + companyId + '/compose-template', {
        template: 'payment_reminder',
        agreementId: parseInt(agreementId, 10),
      });
      const data = res.data || res;
      if (SharedMail) {
        await SharedMail.offerPreparedMail(data, {
          companyId: parseInt(String(companyId || '0'), 10),
          title: t('Payment reminder'),
        });
      } else if (data.mailAppUrl) {
        window.open(data.mailAppUrl, '_blank');
      } else if (data.mailtoUrl) {
        window.location.href = data.mailtoUrl;
      }
      const live = document.getElementById('crm-live-region');
      if (live) live.textContent = t('Payment reminder prepared.');
    } catch (e) {
      if (C) await C.alertDialog({ title: t('Payment reminder'), message: (e.payload && e.payload.message) || e.message, variant: 'danger' });
    } finally {
      btn.removeAttribute('aria-busy');
    }
  });
})();
